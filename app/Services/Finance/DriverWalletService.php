<?php

namespace App\Services\Finance;

use App\Models\Driver;
use App\Models\DriverWallet;
use App\Models\DriverWalletTransaction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service managing Driver & Rider electronic balance wallets and transaction activities.
 */
class DriverWalletService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Get or initialize a driver's wallet.
     */
    public function getOrCreateWallet(Driver $driver): DriverWallet
    {
        return $driver->getOrCreateWallet();
    }

    /**
     * Credit a driver's wallet with an auditable transaction.
     */
    public function credit(
        Driver $driver,
        int $amountMinor,
        string $type,
        ?Model $source,
        string $description,
        ?User $actor = null,
        array $meta = []
    ): DriverWalletTransaction {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('Credit amount cannot be negative.');
        }

        return DB::transaction(function () use ($driver, $amountMinor, $type, $source, $description, $actor, $meta) {
            $wallet = $this->getOrCreateWallet($driver);

            /** @var DriverWallet $lockedWallet */
            $lockedWallet = DriverWallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = $lockedWallet->balance_minor;
            $balanceAfter = $balanceBefore + $amountMinor;

            $lockedWallet->balance_minor = $balanceAfter;
            $lockedWallet->total_credited_minor += $amountMinor;
            $lockedWallet->save();

            $reference = 'DWT-' . date('Ymd') . '-' . strtoupper(Str::random(6)) . '-' . $lockedWallet->id;

            $transaction = DriverWalletTransaction::query()->create([
                'driver_wallet_id' => $lockedWallet->id,
                'driver_id' => $driver->id,
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
                "Credited {$driver->name}'s wallet with " . Money::format($amountMinor) . " ({$description})",
                'Driver Wallet',
                [
                    'driver_id' => $driver->id,
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
     * Debit a driver's wallet (e.g. for payouts, disbursements, or penalties).
     */
    public function debit(
        Driver $driver,
        int $amountMinor,
        string $type,
        ?Model $source,
        string $description,
        ?User $actor = null,
        array $meta = []
    ): DriverWalletTransaction {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('Debit amount cannot be negative.');
        }

        return DB::transaction(function () use ($driver, $amountMinor, $type, $source, $description, $actor, $meta) {
            $wallet = $this->getOrCreateWallet($driver);

            /** @var DriverWallet $lockedWallet */
            $lockedWallet = DriverWallet::query()
                ->where('id', $wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = $lockedWallet->balance_minor;
            $balanceAfter = $balanceBefore - $amountMinor;

            $lockedWallet->balance_minor = $balanceAfter;
            $lockedWallet->total_debited_minor += $amountMinor;
            $lockedWallet->save();

            $reference = 'DWT-' . date('Ymd') . '-' . strtoupper(Str::random(6)) . '-' . $lockedWallet->id;

            $transaction = DriverWalletTransaction::query()->create([
                'driver_wallet_id' => $lockedWallet->id,
                'driver_id' => $driver->id,
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
                "Debited {$driver->name}'s wallet with " . Money::format($amountMinor) . " ({$description})",
                'Driver Wallet',
                [
                    'driver_id' => $driver->id,
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $balanceAfter,
                    'reference' => $reference,
                ],
                $actor,
            );

            return $transaction;
        });
    }
}
