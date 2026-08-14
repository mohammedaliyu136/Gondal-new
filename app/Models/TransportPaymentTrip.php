<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A trip claimed by a transport payment.
 *
 * `trip_id` is UNIQUE. That single constraint is what makes "a trip is paid
 * exactly once, ever" true no matter what a service, a console command or a
 * hand-written query tries to do. Reversal DELETES these rows rather than
 * flagging them, because a tombstone would satisfy the constraint and make the
 * leg unpayable forever.
 */
class TransportPaymentTrip extends Model
{
    protected $fillable = [
        'transport_payment_id', 'trip_id', 'fee_minor', 'litres_carried', 'route_id',
    ];

    protected function casts(): array
    {
        return ['litres_carried' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(TransportPayment::class, 'transport_payment_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
