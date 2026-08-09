<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH-6 — mark the failures a successful sign-in has settled, so they stop
 * counting towards a future lockout without being erased from the log.
 *
 * "5 failures in 15 minutes locks the account" is meant to describe somebody
 * guessing. It stopped describing that because the count could see straight
 * through a correct password: four wrong attempts, a normal successful sign-in,
 * one typo a moment later, and the fifth "consecutive" failure locked an account
 * whose holder had just proved in between that they knew the password. Musa
 * Ibrahim hit exactly that on 7 Aug 2026 (11:07 and 11:14 while the account
 * genuinely had no password, a good sign-in at 11:20:17, one mistype at
 * 11:20:18, locked for thirty minutes).
 *
 * SigninThrottle::clear() was supposed to prevent it and its docblock said so,
 * but it only ever cleared `locked_until` — the LOCK, not the COUNT.
 *
 * WHY A COLUMN RATHER THAN A TIMESTAMP COMPARISON. The obvious fix is to count
 * only failures later than `users.last_signed_in_at`, and it works in production
 * where the two differ by seconds. It is not robust: at one-second timestamp
 * resolution a failure in the same second as the sign-in is ambiguous, and under
 * a frozen clock — which the test suite uses, and which is the only way to assert
 * "locked for exactly 30 minutes" — every row shares one instant and the boundary
 * disappears entirely. An explicit marker does not depend on clock resolution.
 *
 * WHY NOT DELETE THE ROWS. AUTH-6's first clause is that failed sign-ins are
 * LOGGED, and NFR-8 wants a lockout that can explain itself after the fact. The
 * rows keep their address, IP, user agent and reason; they are only excluded from
 * the arithmetic of the next lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('failed_signins', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('occurred_at');

            // The throttle's hot path is "this user's failures that still count".
            $table->index(['user_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('failed_signins', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'superseded_at']);
            $table->dropColumn('superseded_at');
        });
    }
};
