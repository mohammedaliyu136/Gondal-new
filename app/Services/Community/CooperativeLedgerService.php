<?php

namespace App\Services\Community;

use App\Exceptions\RuleViolationException;
use App\Models\Cooperative;
use App\Models\CooperativeAccount;
use App\Models\CooperativeEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §6.6 — the cooperative's running account.
 *
 * Every movement goes through here so three things stay true at once: the entry
 * is written, `balance_after_minor` is stamped onto it, and the account's own
 * balance agrees with the last entry. Writing entries inline from each caller is
 * how those three drift apart.
 *
 * `balance_after_minor` is STORED rather than recomputed on read, deliberately:
 * a correction made next month must not silently restate what a member was told
 * their balance was last month.
 */
class CooperativeLedgerService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Post a movement to a cooperative's account.
     *
     * `in` is money into the cooperative's account (milk supplied, levies
     * collected); `out` is money leaving it (goods taken on credit, payments
     * released). The direction is the account's, not the shop's.
     */
    public function post(
        CooperativeAccount $account,
        string $direction,
        int $amountMinor,
        string $description,
        ?Model $source = null,
        ?User $actor = null,
    ): CooperativeEntry {
        if (! in_array($direction, [CooperativeEntry::DIRECTION_IN, CooperativeEntry::DIRECTION_OUT], true)) {
            throw RuleViolationException::make(
                'REF-1',
                'A ledger entry must be in or out.',
                ['direction' => $direction],
            );
        }

        if ($amountMinor <= 0) {
            throw RuleViolationException::make(
                'REF-1',
                'A ledger entry of zero is not an entry.',
                ['amount_minor' => $amountMinor],
            );
        }

        return DB::transaction(function () use ($account, $direction, $amountMinor, $description, $source, $actor): CooperativeEntry {
            // Locked so two concurrent postings cannot both read the same opening
            // balance and stamp the same balance_after onto different entries.
            $locked = CooperativeAccount::query()->lockForUpdate()->findOrFail($account->getKey());

            $delta = $direction === CooperativeEntry::DIRECTION_IN ? $amountMinor : -$amountMinor;
            $balanceAfter = (int) $locked->balance_minor + $delta;

            $entry = CooperativeEntry::query()->create([
                'cooperative_account_id' => $locked->getKey(),
                'entry_date' => Wat::today()->toDateString(),
                'description' => $description,
                'direction' => $direction,
                'amount_minor' => $amountMinor,
                'balance_after_minor' => $balanceAfter,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'created_by_user_id' => $actor?->getKey(),
            ]);

            $locked->forceFill(['balance_minor' => $balanceAfter])->save();

            return $entry;
        });
    }

    /**
     * A credit sale from the shop lands on the cooperative's general account.
     *
     * Before this existed a credit sale recorded stock movement and a receipt and
     * touched no ledger at all — the cooperative's exposure grew with nothing
     * anywhere showing it, and the sales officer had no balance to look at before
     * extending more.
     */
    public function recordCreditSale(Cooperative $cooperative, Model $sale, int $amountMinor, User $actor): ?CooperativeEntry
    {
        $account = $cooperative->generalAccount();

        if ($account === null) {
            throw RuleViolationException::make(
                'BR-28',
                sprintf('%s has no general account, so a credit sale cannot be recorded against it.', $cooperative->name),
                ['cooperative' => $cooperative->name],
                'cooperative_id',
            );
        }

        $entry = $this->post(
            $account,
            // Goods leaving the shop on the cooperative's account draw the
            // account down, so this is an `out`.
            CooperativeEntry::DIRECTION_OUT,
            $amountMinor,
            'One-Stop Shop purchase on credit — '.($sale->receipt_no ?? '—'),
            $sale,
            $actor,
        );

        $this->audit->created(
            $entry,
            sprintf(
                '%s on credit to %s — balance now %s',
                Money::format($amountMinor),
                $cooperative->name,
                Money::format((int) $entry->balance_after_minor),
            ),
            'Community Engagement',
            ['cooperative' => $cooperative->name, 'sale' => $sale->receipt_no ?? null],
            $actor,
        );

        return $entry;
    }

    /** Reverse a posted entry, for a voided sale. The original is never deleted. */
    public function reverse(CooperativeEntry $entry, string $reason, User $actor): CooperativeEntry
    {
        $opposite = $entry->direction === CooperativeEntry::DIRECTION_IN
            ? CooperativeEntry::DIRECTION_OUT
            : CooperativeEntry::DIRECTION_IN;

        return $this->post(
            $entry->account,
            $opposite,
            (int) $entry->amount_minor,
            'Reversal — '.$reason,
            $entry,
            $actor,
        );
    }
}
