<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.9 + §11 notifications.
 *
 * NOTIF-2 — "a user is never notified about something they could not open."
 *   The permission that gates each event is a COLUMN on notification_events, so
 *   the filter is data-driven and cannot drift from the permission catalogue.
 * NOTIF-3 — the seeded event list is rows in notification_events.
 * NOTIF-5 — every send is queued. Nothing here is written synchronously with a
 *   request beyond the in-app row itself.
 * USER-2 — no farmer-facing notifications exist; `user_id` is always staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('module', 48)->nullable();

            // NOTIF-2 — null means "any authenticated user may receive it".
            $table->string('required_permission', 80)->nullable();

            // Channel defaults, overridable per user in notification_preferences.
            $table->boolean('default_in_app')->default(true);
            $table->boolean('default_email')->default(false);
            $table->boolean('default_sms')->default(false);

            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 48);                       // notification_events.code
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();

            // Which channels this particular send actually used.
            $table->json('channel_flags')->nullable();

            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // NOTIF-1 — per-user, per-event preferences.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(false);
            $table->boolean('sms')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_events');
    }
};
