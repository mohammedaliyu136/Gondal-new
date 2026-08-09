<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARCH-7, at record granularity.
 *
 * The Idempotency-Key middleware replays a whole REQUEST, which is the right
 * unit for one POST from a browser. A field phone does not send one POST: it
 * sends a batch of everything it captured while it had no signal, and the batch
 * it retries is rarely the batch it sent — a delivery recorded in the meantime
 * joins it, so the request fingerprint differs and the replay cache correctly
 * declines to help. Without a per-record ledger, that retry duplicates every
 * record the first attempt already wrote.
 *
 * So each record carries a `client_uuid` generated on the phone, and this table
 * remembers what that uuid became. A second arrival returns the original id
 * instead of writing again — which is exactly what ARCH-7 promises, applied to
 * the unit the client actually re-sends.
 *
 * Deliberately NOT the 24-hour idempotency_keys table: a phone in a community
 * with no signal can be days from a sync, and an expiry shorter than the
 * disconnection it exists to survive is not idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_sync_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->uuid('client_uuid');
            $table->string('record_type', 32);       // milk_collections, field_visits, …

            // What it became. Morphed, because one batch writes deliveries,
            // farmers, sales and activities, and a column per type would grow
            // with every capture flow the app gains.
            $table->nullableMorphs('subject');

            $table->timestamps();

            // The uniqueness that makes the retry safe. Per user, so two agents
            // whose clients somehow generate the same uuid do not collide into
            // each other's records.
            $table->unique(['user_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_sync_records');
    }
};
