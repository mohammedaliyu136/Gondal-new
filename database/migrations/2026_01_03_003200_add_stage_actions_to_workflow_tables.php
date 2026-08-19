<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->string('stage_action')->nullable()->after('is_submission');
            $table->json('stage_action_config')->nullable()->after('stage_action');
        });

        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->json('action_payload')->nullable()->after('comment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workflow_actions', function (Blueprint $table) {
            $table->dropColumn('action_payload');
        });

        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn(['stage_action', 'stage_action_config']);
        });
    }
};
