<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.6 extension agents, field activities and quality follow-ups.
 *
 * BR-5 — reaching a rejection reason's threshold opens a quality_followup
 *        automatically and notifies the extension team.
 * Phase 5 acceptance — closing a follow-up requires a logged field activity,
 *        which is why closed_by_activity_id exists and closes_followup_id
 *        exists on the activity side too.
 * ARCH-2 / NG-3 — `source` and `synced_at` on field_activities exist so field
 *        capture from a future mobile client is a data concern, not a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_agents', function (Blueprint $table) {
            $table->id();

            // An extension agent IS a member of staff, so unlike farmers and
            // riders they do have an account (USER-1 lists who does not).
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('code', 24)->unique();
            $table->foreignId('reports_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('visit_target_monthly')->nullable();
            $table->unsignedInteger('enrolment_target_monthly')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('user_id');
            $table->index('status');
        });

        // SCOPE-1 — the `communities` scope type is exercised here: an agent
        // covers a list of communities.
        Schema::create('agent_community', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extension_agent_id')->constrained('extension_agents')->cascadeOnDelete();
            $table->foreignId('community_id')->constrained('communities')->cascadeOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['extension_agent_id', 'community_id']);
        });

        Schema::create('quality_followups', function (Blueprint $table) {
            $table->id();

            // Polymorphic: a farmer or a collection point.
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->foreignId('rejection_reason_id')->constrained('rejection_reasons')->restrictOnDelete();
            $table->unsignedInteger('trigger_count')->default(0);
            $table->unsignedInteger('threshold')->nullable();
            $table->unsignedInteger('window_days')->nullable();
            $table->timestamp('opened_at');

            $table->unsignedBigInteger('closed_by_activity_id')->nullable();  // FK added below
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('open');       // open|closed
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_type', 'subject_id', 'status']);
            $table->index(['status', 'opened_at']);
        });

        Schema::create('field_activities', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('extension_agent_id')->constrained('extension_agents')->restrictOnDelete();
            $table->foreignId('activity_type_id')->constrained('activity_types')->restrictOnDelete();
            $table->foreignId('community_id')->constrained('communities')->restrictOnDelete();
            $table->foreignId('farmer_id')->nullable()->constrained('farmers')->nullOnDelete();
            $table->date('activity_date');
            $table->unsignedInteger('farmers_reached')->default(0);
            $table->string('topic')->nullable();
            $table->text('findings')->nullable();

            // Phase 5 acceptance — the activity that closes a follow-up.
            $table->foreignId('closes_followup_id')->nullable()
                ->constrained('quality_followups')->nullOnDelete();

            // ARCH-2 — web today, API tomorrow.
            $table->string('source', 8)->default('web');        // web|api
            $table->timestamp('synced_at')->nullable();

            $table->boolean('is_test')->default(false);         // BR-35
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['extension_agent_id', 'activity_date']);
            $table->index(['community_id', 'activity_date']);
            $table->index('farmer_id');
            $table->index('is_test');
        });

        Schema::table('quality_followups', function (Blueprint $table) {
            $table->foreign('closed_by_activity_id')->references('id')->on('field_activities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_activities');
        Schema::dropIfExists('quality_followups');
        Schema::dropIfExists('agent_community');
        Schema::dropIfExists('extension_agents');
    }
};
