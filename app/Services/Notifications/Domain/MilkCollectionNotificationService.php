<?php

namespace App\Services\Notifications\Domain;

use App\Models\Batch;
use App\Models\CollectionPoint;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Services\Notifications\Contracts\MilkCollectionNotificationServiceInterface;
use App\Services\Notifications\Contracts\NotificationServiceInterface;
use App\Support\Volume;

class MilkCollectionNotificationService implements MilkCollectionNotificationServiceInterface
{
    public function __construct(private readonly NotificationServiceInterface $notifications) {}

    public function notifyConsignmentAwaitingConfirmation(Consignment $consignment): int
    {
        $recipients = $this->notifications->usersWithPermission('milk.consignment.confirm.view', $consignment);

        $litres = Volume::format($consignment->dispatched_litres ?? 0);
        $title = "Consignment {$consignment->reference} awaiting confirmation";
        $body = "A milk consignment of {$litres} from {$consignment->sourceName()} has arrived and is awaiting physical intake verification.";
        $actionUrl = route('consignments.show', $consignment);

        return $this->notifications->send(
            eventCode: 'consignment.awaiting_confirmation',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $consignment,
        );
    }

    public function notifyBatchDiscrepancy(Batch $batch, float $discrepancyLitres): int
    {
        $recipients = $this->notifications->usersWithPermission('milk.reconciliation.view', $batch);

        $varianceText = Volume::format($discrepancyLitres);
        $title = "Batch discrepancy detected on {$batch->reference}";
        $body = "Factory intake reconciliation flagged a discrepancy of {$varianceText} on batch {$batch->reference}. Immediate audit required.";
        $actionUrl = route('reconciliation.show', $batch);

        return $this->notifications->send(
            eventCode: 'batch.discrepancy',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $batch,
        );
    }

    public function notifyRejectionAtPoint(CollectionPoint $point, string $reason, float $litres): int
    {
        $recipients = $this->notifications->usersWithPermission('milk.rejection.view', $point);

        $volume = Volume::format($litres);
        $title = "Milk Rejected at {$point->name} ({$volume})";
        $body = "{$volume} of milk was rejected at {$point->name}. Reason: {$reason}.";
        $actionUrl = route('collection-points.show', $point);

        return $this->notifications->send(
            eventCode: 'rejection.at_point',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $point,
        );
    }

    public function notifyDeliveryRecorded(Delivery $delivery): int
    {
        $recipients = $this->notifications->usersWithPermission('milk.deliveries.view', $delivery);

        $farmerName = $delivery->farmer?->full_name ?? 'Farmer';
        $litres = Volume::format($delivery->litres ?? 0);
        $title = "Milk Delivery Recorded: {$delivery->reference}";
        $body = "Delivery of {$litres} from {$farmerName} was recorded successfully.";
        $actionUrl = route('deliveries.show', $delivery);

        return $this->notifications->send(
            eventCode: 'milk.delivery_recorded',
            recipients: $recipients,
            title: $title,
            body: $body,
            actionUrl: $actionUrl,
            subject: $delivery,
        );
    }
}
