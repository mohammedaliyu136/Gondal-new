<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The claim ledger: this delivery, paid on this payment, at this rate.
 *
 * `delivery_id` is UNIQUE at the database level, which is the whole design.
 * "A litre is paid exactly once, ever" is not a rule a service enforces and a
 * refactor can lose — it is a constraint the database refuses to break, and it
 * is what makes ragged, catch-up and late-confirmation runs safe for free.
 *
 * Everything here is SNAPSHOTTED rather than joined. A consignment can be
 * re-graded after the fact (BR-4's control break); what a farmer was paid must
 * not move when it is.
 */
class FarmerPaymentDelivery extends Model
{
    protected $table = 'farmer_payment_deliveries';

    protected $fillable = [
        'farmer_payment_id', 'delivery_id', 'litres_payable',
        'rate_per_litre_minor', 'grade_id', 'consignment_id', 'line_gross_minor',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(FarmerPayment::class, 'farmer_payment_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }
}
