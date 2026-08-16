<?php

namespace App\Services\Payment\Contracts;

use App\Models\PaymentBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

interface ModulePaymentServiceInterface
{
    /**
     * Get the source module identifier key (e.g. 'payroll', 'milk_collection', 'requisition').
     */
    public function getModuleKey(): string;

    /**
     * Create and initialize a payment batch for a domain subject model.
     */
    public function createBatch(Model $subject, string $gateway, User $actor, ?string $notes = null): PaymentBatch;

    /**
     * Disburse an existing or newly created payment batch for the subject.
     */
    public function disburseBatch(PaymentBatch $batch, ?string $otp = null): PaymentBatch;
}
