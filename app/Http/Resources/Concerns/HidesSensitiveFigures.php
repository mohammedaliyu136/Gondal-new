<?php

namespace App\Http\Resources\Concerns;

use App\Authorization\Access;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * G-6 / BR-29 — "Users holding shop.sales but not shop.revenue see their own
 * transactions and no aggregate revenue, margin or stock-value figure — IN API
 * RESPONSES AS WELL AS UI."
 *
 * That last clause is why this trait exists. A sensitive figure is omitted from
 * the payload entirely rather than nulled, so a client cannot infer its presence
 * and a future UI cannot accidentally render it.
 */
trait HidesSensitiveFigures
{
    protected function maySee(Request $request, string $permissionKey): bool
    {
        $user = $request->user();

        return $user instanceof User && app(Access::class)->allows($user, $permissionKey);
    }

    /**
     * Include $value only when the caller holds $permissionKey.
     */
    protected function whenPermitted(Request $request, string $permissionKey, mixed $value): mixed
    {
        return $this->when($this->maySee($request, $permissionKey), $value);
    }
}
