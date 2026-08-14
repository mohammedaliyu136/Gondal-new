<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money actually handed over, and the evidence that it was.
 *
 * Cash at the collection point is the v1 channel because most smallholders in
 * the dairy belt have no bank account — `farmers` had no payout details at all
 * before Phase 7. `method` is a column so bank and mobile money are additive.
 *
 * The GPS columns are the same ones the field-capture work uses, with the same
 * meaning: evidence for a reviewer, never a gate. A payout with no fix is still
 * recordable, because refusing it would push the payout off the system entirely,
 * which is the opposite of what the evidence is for.
 */
class FarmerPaymentDisbursement extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const METHOD_CASH = 'cash';

    public const METHOD_BANK = 'bank';

    public const METHOD_MOBILE_MONEY = 'mobile_money';

    /** The network pays the cooperative; the treasurer pays the member. */
    public const METHOD_VIA_COOPERATIVE = 'via_cooperative';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_BANK,
        self::METHOD_MOBILE_MONEY,
        self::METHOD_VIA_COOPERATIVE,
    ];

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'farmer_payment_id', 'method', 'amount_minor', 'disbursed_at', 'paid_by_user_id',
        'received_by', 'received_by_relation', 'proxy_authority_ref',
        'external_reference', 'signature_evidence_id',
        'latitude', 'longitude', 'location_accuracy_m', 'located_at',
        'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'disbursed_at' => 'datetime',
            'located_at' => 'datetime',
            // Strings, as everywhere else a coordinate is stored: read and
            // compared, never arithmetic.
            'latitude' => 'string',
            'longitude' => 'string',
            'location_accuracy_m' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FarmerPayment::class, 'farmer_payment_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function signatureEvidence(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'signature_evidence_id');
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
