<?php

namespace App\Authorization;

use App\Models\User;

/**
 * The mobile client's vocabulary, and the one place it maps to §5's.
 *
 * A field client cannot ask "does this user hold `milk.deliveries.create`?" for
 * every button it draws — it would then own a copy of the permission catalogue,
 * and PERM-1's whole point is that the catalogue is rows in a table, not a
 * constant in somebody's source tree. So the API answers in capabilities: a
 * small, stable set of questions a phone actually needs answered ("may this
 * person record an intake?"), each resolved HERE against the real permission.
 *
 * Two rules keep this from becoming a second permission system:
 *
 *   1. A capability is a QUESTION, never a grant. Every entry below is a lookup
 *      into effectivePermissionKeys(); nothing here can widen anything.
 *   2. The map is one-to-one with a real permission key. No capability is the
 *      union of two permissions, because "can_do_milk_things" is precisely the
 *      bundled authority ROLE-4 retired two roles for.
 *
 * The raw permission keys travel alongside these flags in the same payload, so a
 * client that wants to check the real thing always can — the capabilities are a
 * convenience over the truth, not a replacement for it.
 */
class MobileCapabilities
{
    /**
     * capability => the single permission key that answers it.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            /* ---- Community: the Extension Agent's and CEO's day ---- */
            'can_view_farmers' => 'community.farmers.view',
            'can_register_farmers' => 'community.farmers.create',
            /*
             * BR-36 — the field act of checking a farmer against reality, and
             * deliberately NOT `edit`. A Collection Agent holds this and not
             * `community.farmers.edit`, so they can confirm the person in front
             * of them without gaining the run of the register.
             */
            'can_validate_farmers' => 'community.farmers.validate',
            // Seeing the queue M&E manages is a different authority from
            // working it — the leads hold this, the field workers do not.
            'can_manage_validations' => 'community.validation.create',
            'can_log_field_visits' => 'community.extension.create',
            'can_manage_extension_agents' => 'community.extension.edit',
            'can_view_cooperatives' => 'community.cooperatives.view',
            'can_manage_cooperatives' => 'community.cooperatives.edit',
            // PERM-2 sensitive — the Extension Agent must never see this.
            'can_record_coop_savings' => 'community.coop.savings.create',

            /* ---- Milk: the Collection Agent's day ---- */
            'can_view_deliveries' => 'milk.deliveries.view',
            'can_record_milk_intake' => 'milk.deliveries.create',
            'can_reject_milk' => 'milk.rejection.create',
            'can_dispatch_consignment' => 'milk.consignment.confirm.create',

            /* ---- Milk: the Collection Officer's day ---- */
            'can_confirm_consignment' => 'milk.consignment.confirm.edit',
            'can_record_adjustment' => 'milk.adjustment.create',
            'can_grade_milk' => 'milk.grade.create',
            // Held apart from grading on purpose: assigning a grade is the
            // morning's work, changing one already assigned moves money after
            // the fact and lands on the exceptions list.
            'can_regrade_milk' => 'milk.grade.edit',
            'can_dispatch_batch' => 'milk.batch.dispatch.create',
            'can_reconcile_batches' => 'milk.reconciliation.edit',

            /* ---- Logistics ---- */
            'can_record_trips' => 'logistics.trips.create',

            /* ---- One-Stop Shop ---- */
            'can_view_catalog' => 'shop.inventory.view',
            'can_issue_oss_credit' => 'shop.sales.create',
            'can_manage_inventory' => 'shop.inventory.edit',

            /* ---- Aggregates. BR-29 — a Sales Officer holds neither. ---- */
            'can_view_analytics' => 'milk.totals.network.view',
            'can_view_shop_revenue' => 'shop.revenue.view',

            /* ---- Approvals and self-service ---- */
            'can_approve_requisitions' => 'purchase.requisitions.approve',
            'can_raise_requisitions' => 'purchase.requisitions.create',
            'can_request_own_leave' => 'hr.leave.own.create',
            'can_view_own_payslip' => 'hr.payslip.own.view',
        ];
    }

    /**
     * The flags for one user. Every capability appears, true or false — a client
     * that receives a partial map cannot tell "denied" from "this build of the
     * server has never heard of that", and the safe reading of the second is not
     * obvious. Sending all of them makes it obvious.
     *
     * @return array<string, bool>
     */
    public static function for(User $user): array
    {
        $held = array_flip($user->effectivePermissionKeys());

        $capabilities = [];

        foreach (self::map() as $capability => $permissionKey) {
            $capabilities[$capability] = isset($held[$permissionKey]);
        }

        return $capabilities;
    }
}
