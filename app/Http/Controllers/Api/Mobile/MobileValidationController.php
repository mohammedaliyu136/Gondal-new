<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;
use App\Models\FarmerValidation;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "What has M&E asked me to go and check?"
 *
 * This replaces the field app's previous answer to that question, which was to
 * look at its own cache and guess: any farmer whose locally-stored flag did not
 * say `validated`. Nobody assigned anything, nothing was due, and two agents
 * could drive to the same household while a third farmer went unchecked for a
 * year. The queue is now the server's, because deciding who needs checking is
 * M&E's job and no phone can do it.
 */
class MobileValidationController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorizeAccess('community.farmers.validate', null, 'Mobile: my revalidation queue');

        $assignments = FarmerValidation::query()
            ->with(['farmer.community', 'farmer.cooperative', 'reason', 'round', 'assignedBy'])
            ->forFieldWorker($user)
            // Overdue first, then by due date, then oldest assignment. A field
            // worker planning a morning should not have to sort this themselves.
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')
            ->orderBy('assigned_at')
            ->limit(500)
            ->get();

        return $this->ok([
            'assignments' => $assignments->map(fn (FarmerValidation $validation) => [
                'id' => $validation->id,
                'reference' => $validation->reference,
                'status' => $validation->status,
                'reason' => $validation->reason?->name,
                'reason_help' => $validation->reason?->help_text,
                'round' => $validation->round?->name,
                'due_on' => $validation->due_on?->toDateString(),
                'is_overdue' => $validation->isOverdue(),
                'assigned_at' => $validation->assigned_at?->toIso8601String(),
                'assigned_by' => $validation->assignedBy?->name,
                // Present when M&E sent it back — the field worker needs to know
                // what was wrong before they drive out a second time.
                'review_note' => $validation->status === FarmerValidation::STATUS_RETURNED
                    ? $validation->review_note
                    : null,
                // Null on a pool assignment: anyone who covers this farmer may
                // take it, and the app says so rather than implying ownership.
                'assigned_to_me' => $validation->assigned_to_user_id === $user->getKey(),
                'farmer' => $validation->farmer === null ? null : [
                    'id' => $validation->farmer->id,
                    'code' => $validation->farmer->code,
                    'name' => $validation->farmer->name,
                    'phone' => $validation->farmer->phone,
                    'gender' => $validation->farmer->gender,
                    'year_of_birth' => $validation->farmer->year_of_birth,
                    'herd_size' => $validation->farmer->herd_size,
                    'lactating_count' => $validation->farmer->lactating_count,
                    'community' => $validation->farmer->community?->name,
                    'cooperative' => $validation->farmer->cooperative?->name,
                    'last_validated_on' => $validation->farmer->last_validated_on?->toDateString(),
                    // BR-36 — shown so the field worker can tell the farmer why
                    // their payment is waiting, and that this visit releases it.
                    'payment_held' => $validation->farmer->paymentIsHeldPendingValidation(),
                ],
            ]),

            // The vocabulary the server will accept, so the phone's picker and
            // the server's guard cannot drift.
            'outcomes' => [
                ['code' => FarmerValidation::OUTCOME_CONFIRMED, 'label' => 'Details are correct'],
                ['code' => FarmerValidation::OUTCOME_CORRECTED, 'label' => 'Details corrected'],
                ['code' => FarmerValidation::OUTCOME_NOT_FOUND, 'label' => 'Farmer could not be found'],
                ['code' => FarmerValidation::OUTCOME_REFUSED, 'label' => 'Farmer declined'],
            ],

            'server_time_wat' => Wat::now()->toIso8601String(),
        ]);
    }
}
