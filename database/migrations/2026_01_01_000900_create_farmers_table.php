<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.2 farmers.
 *
 * USER-1 / USER-2 — a farmer is a RECORD, not an account. There is no password
 * column, no email login, no portal and no farmer-facing notification. Staff
 * record on their behalf, and `enrolled_by_user_id` says who.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->string('gender', 16)->nullable();
            $table->unsignedSmallInteger('year_of_birth')->nullable();
            $table->string('phone', 32)->nullable();

            $table->foreignId('community_id')->constrained('communities')->restrictOnDelete();
            $table->foreignId('lga_id')->constrained('lgas')->restrictOnDelete();
            $table->foreignId('cooperative_id')->nullable()->constrained('cooperatives')->nullOnDelete();
            $table->string('cooperative_member_no', 32)->nullable();

            $table->foreignId('default_collection_point_id')->nullable()
                ->constrained('collection_points')->nullOnDelete();

            $table->unsignedInteger('herd_size')->nullable();
            $table->unsignedInteger('lactating_count')->nullable();

            $table->foreignId('enrolled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('enrolled_on')->nullable();
            $table->string('status', 16)->default('active');     // active|dormant|exited

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'lga_id']);
            $table->index(['community_id', 'status']);
            $table->index('cooperative_id');
            $table->index('default_collection_point_id');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmers');
    }
};
