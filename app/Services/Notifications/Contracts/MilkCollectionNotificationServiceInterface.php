<?php

namespace App\Services\Notifications\Contracts;

use App\Models\Batch;
use App\Models\CollectionPoint;
use App\Models\Consignment;
use App\Models\Delivery;

interface MilkCollectionNotificationServiceInterface
{
    /**
     * Notify plant / intake supervisors that a dispatch consignment has arrived and is awaiting confirmation.
     */
    public function notifyConsignmentAwaitingConfirmation(Consignment $consignment): int;

    /**
     * Notify plant supervisors and QC managers when a reconciliation batch discrepancy is identified.
     */
    public function notifyBatchDiscrepancy(Batch $batch, float $discrepancyLitres): int;

    /**
     * Notify point supervisors and extension officers when milk is rejected at a collection point.
     */
    public function notifyRejectionAtPoint(CollectionPoint $point, string $reason, float $litres): int;

    /**
     * Notify supervisors when a new milk delivery has been recorded.
     */
    public function notifyDeliveryRecorded(Delivery $delivery): int;
}
