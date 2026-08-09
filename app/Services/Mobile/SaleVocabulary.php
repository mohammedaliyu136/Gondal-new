<?php

namespace App\Services\Mobile;

use App\Models\Sale;

/**
 * The sale codes a field client may choose from, with the words a Sales Officer
 * reads on the picker.
 *
 * §18.7 forbids §9's reference data living as a constant. These are not that —
 * `Sale`'s own docblock is explicit that customer type and payment method are
 * the shape of the sale record (§6.7), which is why they are constants there and
 * grades are rows. What this class adds is only the label, and only for the
 * mobile picker.
 *
 * The CODES are never restated here: they are read from Sale, so a method added
 * to the model appears in the picker with a derived label rather than silently
 * missing from it. That direction matters — the failure this exists to prevent
 * was the app offering 'Cooperative Credit' while SaleService compared against
 * `credit`, which skipped BR-25's allow_credit check and left the cooperative's
 * account undrawn on every credit sale taken in the field.
 */
final class SaleVocabulary
{
    /** @return array<int, array{code: string, label: string}> */
    public static function paymentMethods(): array
    {
        return self::listing(Sale::PAYMENT_METHODS, [
            Sale::PAYMENT_CASH => 'Cash',
            Sale::PAYMENT_TRANSFER => 'Bank transfer',
            Sale::PAYMENT_CREDIT => 'Cooperative credit',
            // BR-30 — the farmer buys against what the co-op will pay them for
            // their milk. The phone could not offer this at all before.
            Sale::PAYMENT_MILK_DEDUCTION => 'Deduct from milk payment',
        ]);
    }

    /** @return array<int, array{code: string, label: string}> */
    public static function customerTypes(): array
    {
        return self::listing(Sale::CUSTOMER_TYPES, [
            Sale::CUSTOMER_FARMER => 'Farmer',
            Sale::CUSTOMER_COOPERATIVE => 'Cooperative',
            Sale::CUSTOMER_WALKIN => 'Walk-in customer',
            Sale::CUSTOMER_INTERNAL => 'Internal',
        ]);
    }

    /**
     * @param  array<int, string>  $codes
     * @param  array<string, string>  $labels
     * @return array<int, array{code: string, label: string}>
     */
    private static function listing(array $codes, array $labels): array
    {
        return array_map(static fn (string $code) => [
            'code' => $code,
            // An unlabelled code still reaches the picker. A field worker seeing
            // "Milk deduction" spelled oddly is a smaller failure than a payment
            // method the ERP accepts being unavailable in the field.
            'label' => $labels[$code] ?? ucfirst(str_replace('_', ' ', $code)),
        ], $codes);
    }
}
