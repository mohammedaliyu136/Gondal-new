<?php

namespace App\Services\Finance;

use App\Models\Batch;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\FarmerWallet;
use App\Models\FarmerWalletTransaction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service managing Farmer electronic balance wallets and transaction activities.
 */
class FarmerWalletService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Get or initialize a farmer's wallet.
     */
    public function getOrCreateWallet(Farmer $farmer): FarmerWallet
    {
        return $farmer->getOrCreateWallet();
    }

    /**
     * Credit a farmer's wallet with an auditable transaction.
     */
    public function credit(
        Farmer $farmer,
        int $amountMinor,
        string $type,
        ?Model $source,
        string $description,
        ?User $actor = null,
        ?string $litres = null,
        ?int $rateMinor = null,
        array $meta = []
    ): FarmerWalletTransaction {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('Credit amount cannot be negative.');
        }

        return DB::transaction(function () use ($farmer, $amountMinor, $type, $source, $description, $actor, $litres, $rateMinor, $meta) {
            $wallet = $this->getOrCreateWallet($farmer);

            /** @var FarmerWallet $lockedWallet */
            $lockedWallet = FarmerWallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = $lockedWallet->balance_minor;
            $balanceAfter = $balanceBefore + $amountMinor;

            $lockedWallet->balance_minor = $balanceAfter;
            $lockedWallet->total_credited_minor += $amountMinor;
            $lockedWallet->save();

            $reference = 'FWT-' . date('Ymd') . '-' . strtoupper(Str::random(6)) . '-' . $lockedWallet->id;

            $transaction = FarmerWalletTransaction::query()->create([
                'farmer_wallet_id' => $lockedWallet->id,
                'farmer_id' => $farmer->id,
                'reference' => $reference,
                'type' => $type,
                'source_type' => $source ? $source->getMorphClass() : null,
                'source_id' => $source?->getKey(),
                'amount_minor' => $amountMinor,
                'balance_before_minor' => $balanceBefore,
                'balance_after_minor' => $balanceAfter,
                'litres' => $litres,
                'rate_per_litre_minor' => $rateMinor,
                'description' => $description,
                'meta' => $meta,
                'recorded_by_user_id' => $actor?->id,
            ]);

            $this->audit->created(
                $transaction,
                "Credited {$farmer->name}'s wallet with " . Money::format($amountMinor) . " ({$description})",
                'Farmer Wallet',
                [
                    'farmer_id' => $farmer->id,
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $balanceAfter,
                    'reference' => $reference,
                ],
                $actor,
            );

            return $transaction;
        });
    }

    /**
     * Debit a farmer's wallet (e.g. for payouts, withdrawals, or deductions).
     */
    public function debit(
        Farmer $farmer,
        int $amountMinor,
        string $type,
        ?Model $source,
        string $description,
        ?User $actor = null,
        array $meta = []
    ): FarmerWalletTransaction {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('Debit amount cannot be negative.');
        }

        return DB::transaction(function () use ($farmer, $amountMinor, $type, $source, $description, $actor, $meta) {
            $wallet = $this->getOrCreateWallet($farmer);

            /** @var FarmerWallet $lockedWallet */
            $lockedWallet = FarmerWallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = $lockedWallet->balance_minor;
            $balanceAfter = $balanceBefore - $amountMinor;

            $lockedWallet->balance_minor = $balanceAfter;
            $lockedWallet->total_debited_minor += $amountMinor;
            $lockedWallet->save();

            $reference = 'FWT-' . date('Ymd') . '-' . strtoupper(Str::random(6)) . '-' . $lockedWallet->id;

            $transaction = FarmerWalletTransaction::query()->create([
                'farmer_wallet_id' => $lockedWallet->id,
                'farmer_id' => $farmer->id,
                'reference' => $reference,
                'type' => $type,
                'source_type' => $source ? $source->getMorphClass() : null,
                'source_id' => $source?->getKey(),
                'amount_minor' => $amountMinor,
                'balance_before_minor' => $balanceBefore,
                'balance_after_minor' => $balanceAfter,
                'description' => $description,
                'meta' => $meta,
                'recorded_by_user_id' => $actor?->id,
            ]);

            $this->audit->created(
                $transaction,
                "Debited {$farmer->name}'s wallet with " . Money::format($amountMinor) . " ({$description})",
                'Farmer Wallet',
                [
                    'farmer_id' => $farmer->id,
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $balanceAfter,
                    'reference' => $reference,
                ],
                $actor,
            );

            return $transaction;
        });
    }

    /**
     * Automatically credit farmer wallets for all milk deliveries in a reconciled batch.
     *
     * @return array{total_credited_minor: int, deliveries_credited_count: int, farmers_count: int}
     */
    public function creditForReconciledBatch(Batch $batch, User $actor): array
    {
        $batch->loadMissing(['consignments.deliveries.farmer', 'consignments.grade']);

        $totalCreditedMinor = 0;
        $creditedDeliveriesCount = 0;
        $creditedFarmers = [];

        foreach ($batch->consignments as $consignment) {
            $rate = (int) ($consignment->rate_per_litre_minor ?? 0);

            // If consignment rate was not set, fallback to default or skip
            if ($rate <= 0) {
                continue;
            }

            foreach ($consignment->deliveries as $delivery) {
                $farmer = $delivery->farmer;
                if (!$farmer) {
                    continue;
                }

                $payableLitres = (string) $delivery->litres_payable;
                if (Volume::toCentilitres($payableLitres) <= 0) {
                    continue;
                }

                // Idempotency check: prevent duplicate crediting for the same delivery
                $alreadyCredited = FarmerWalletTransaction::query()
                    ->where('source_type', $delivery->getMorphClass())
                    ->where('source_id', $delivery->getKey())
                    ->where('type', FarmerWalletTransaction::TYPE_CREDIT)
                    ->exists();

                if ($alreadyCredited) {
                    continue;
                }

                $lineGrossMinor = Money::valueVolume($payableLitres, $rate);

                if ($lineGrossMinor <= 0) {
                    continue;
                }

                $formattedRate = Money::format($rate);
                $formattedLitres = Volume::format($payableLitres);
                $desc = "Milk reconciliation credit for Batch {$batch->reference} / Delivery {$delivery->reference} ({$formattedLitres} @ {$formattedRate}/L)";

                $meta = [
                    'batch_id' => $batch->id,
                    'batch_reference' => $batch->reference,
                    'consignment_id' => $consignment->id,
                    'consignment_reference' => $consignment->reference,
                    'delivery_id' => $delivery->id,
                    'delivery_reference' => $delivery->reference,
                    'grade' => $consignment->grade?->name,
                    'reconciled_at' => Wat::now()->toIso8601String(),
                ];

                $this->credit(
                    farmer: $farmer,
                    amountMinor: $lineGrossMinor,
                    type: FarmerWalletTransaction::TYPE_CREDIT,
                    source: $delivery,
                    description: $desc,
                    actor: $actor,
                    litres: $payableLitres,
                    rateMinor: $rate,
                    meta: $meta,
                );

                $totalCreditedMinor += $lineGrossMinor;
                $creditedDeliveriesCount++;
                $creditedFarmers[$farmer->id] = true;
            }
        }

        return [
            'total_credited_minor' => $totalCreditedMinor,
            'deliveries_credited_count' => $creditedDeliveriesCount,
            'farmers_count' => count($creditedFarmers),
        ];
    }
}
