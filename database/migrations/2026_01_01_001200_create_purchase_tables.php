<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.4 purchases.
 *
 * NG-6 / §15.5 — vendor registry, purchase orders and goods-received notes are
 * a known gap deliberately out of v1. `suggested_vendor` is therefore a plain
 * string, not a foreign key to a vendors table that does not exist.
 *
 * BR-20 — a rejection returns the requisition to the requester; resubmission
 *         starts a NEW workflow instance and the old one is retained, which is
 *         why workflow_instance_id points at the current instance only and the
 *         history lives in workflow_instances.subject_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('title')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('urgency', 16)->default('normal');       // low|normal|high
            $table->date('needed_by')->nullable();

            // NG-6 — free text until a vendor registry exists.
            $table->string('suggested_vendor')->nullable();

            $table->unsignedBigInteger('total_minor')->default(0);

            // BR-22 — set by the chain; never above total_minor.
            $table->unsignedBigInteger('approved_total_minor')->nullable();

            $table->foreignId('workflow_instance_id')->nullable()
                ->constrained('workflow_instances')->nullOnDelete();

            // §8 — draft|in_review|approved|rejected|cancelled
            $table->string('status', 16)->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            // BR-20 — resubmission chains back to the requisition it revises.
            $table->foreignId('revises_requisition_id')->nullable()
                ->constrained('requisitions')->nullOnDelete();

            $table->boolean('is_test')->default(false);             // BR-35
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'department_id']);
            $table->index(['requester_user_id', 'status']);
            $table->index('submitted_at');
            $table->index('is_test');
        });

        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->string('item');
            $table->string('purpose')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 24)->nullable();
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('requisition_id');
        });

        // Shared by requisitions, deliveries, batches, leave requests, sales.
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('filename');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mime', 128)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->text('body');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['commentable_type', 'commentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisitions');
    }
};
