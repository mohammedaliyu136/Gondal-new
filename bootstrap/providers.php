<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;

return [
    AppServiceProvider::class,
    // §5 — the two authorisation layers (ARCH-4). Registered explicitly so the
    // permission Gate and every policy exist before the first request is routed.
    AuthorizationServiceProvider::class,
];
