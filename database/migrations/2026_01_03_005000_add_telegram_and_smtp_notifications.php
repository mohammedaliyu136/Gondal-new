<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 64)->nullable()->after('email')->index();
            $table->string('telegram_username', 64)->nullable()->after('telegram_chat_id');
            $table->string('telegram_onboarding_token', 64)->nullable()->unique()->after('telegram_username');
        });

        Schema::table('notification_events', function (Blueprint $table) {
            $table->boolean('default_telegram')->default(false)->after('default_sms');
        });

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('telegram')->default(false)->after('sms');
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn('telegram');
        });

        Schema::table('notification_events', function (Blueprint $table) {
            $table->dropColumn('default_telegram');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_username', 'telegram_onboarding_token']);
        });
    }
};
