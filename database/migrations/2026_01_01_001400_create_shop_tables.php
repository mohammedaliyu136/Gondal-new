<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.7 the One-Stop Shop.
 *
 * G-5 / BR-25 — product categories are ADMINISTRATOR-DEFINED rows. Nothing in
 *   the codebase may enumerate them. The behavioural flags that used to be
 *   per-category `if` statements (prescription, expiry, credit, manager
 *   approval) are columns on the category.
 * BR-26 — stock decrements atomically; a sale that would go negative is refused.
 * BR-29 — a user with shop.sales but not shop.revenue sees no aggregate value,
 *   in API responses as well as UI. Cost prices live on `products` and
 *   `product_batches` and are stripped by the resource layer.
 * §15.4 — further One-Stop Shop detail is outstanding from Muhammad Bello. This
 *   is the §6.7 schema; extend it when that arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_unit', 24)->nullable();
            $table->unsignedInteger('default_reorder_level')->nullable();

            // BR-27 and friends — category behaviour as data.
            $table->boolean('requires_prescription')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->boolean('allow_credit')->default(false);
            $table->boolean('requires_manager_approval')->default(false);

            // BR-25 — retire, never delete.
            $table->string('status', 16)->default('active');    // active|retired
            $table->timestamp('retired_at')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 32)->unique();
            $table->string('name');
            $table->foreignId('product_category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('unit', 24)->nullable();

            // BR-29 — cost price is a shop.revenue figure.
            $table->unsignedBigInteger('cost_price_minor')->default(0);
            $table->unsignedBigInteger('selling_price_minor')->default(0);

            $table->unsignedInteger('reorder_level')->nullable();
            $table->string('preferred_supplier')->nullable();

            // Denormalised running balance, kept in step by stock_movements
            // inside the same transaction (BR-26).
            $table->decimal('quantity_on_hand', 12, 2)->default(0);

            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['product_category_id', 'status']);
            $table->index('name');
        });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('batch_no', 48);
            $table->string('supplier')->nullable();
            $table->date('received_on')->nullable();
            $table->date('expiry_on')->nullable();
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->decimal('quantity_remaining', 12, 2)->default(0);
            $table->unsignedBigInteger('unit_cost_minor')->default(0);

            // NG-6 / §15.5 — `product_batches` references a goods-received-note
            // concept that has no screen in v1. The requisition link is the only
            // provenance available until a GRN exists.
            $table->foreignId('requisition_id')->nullable()->constrained('requisitions')->nullOnDelete();

            $table->string('status', 16)->default('active');    // active|depleted|expired|quarantined
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['product_id', 'batch_no']);
            $table->index(['product_id', 'expiry_on']);
            $table->index('status');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 32)->unique();
            $table->string('customer_type', 16);                // farmer|cooperative|walkin|internal
            $table->foreignId('farmer_id')->nullable()->constrained('farmers')->nullOnDelete();
            $table->foreignId('cooperative_id')->nullable()->constrained('cooperatives')->nullOnDelete();
            $table->string('customer_name')->nullable();

            $table->string('payment_method', 20);               // cash|transfer|credit|milk_deduction
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('amount_received_minor')->default(0);

            // BR-27 — required when any line's category requires a prescription.
            $table->string('prescription_reference', 64)->nullable();

            $table->foreignId('sales_officer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('sold_at');

            $table->boolean('is_test')->default(false);         // BR-35
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['sold_at', 'sales_officer_user_id']);
            $table->index(['customer_type', 'sold_at']);
            $table->index('farmer_id');
            $table->index('is_test');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('amount_minor');

            // BR-29 — snapshotted so margin can be computed later without a
            // live join, and stripped from responses without shop.revenue.
            $table->unsignedBigInteger('unit_cost_minor_snapshot')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('sale_id');
            $table->index('product_id');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();

            $table->string('movement_type', 16);                // stock_in|sale|adjustment|return
            $table->string('reference', 48)->nullable();
            $table->decimal('quantity_in', 12, 2)->default(0);
            $table->decimal('quantity_out', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2);

            // BR-28 — an adjustment needs a reason AND an explanation.
            $table->foreignId('reason_id')->nullable()->constrained('adjustment_reasons')->nullOnDelete();
            $table->text('explanation')->nullable();

            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('workflow_instance_id')->nullable()
                ->constrained('workflow_instances')->nullOnDelete();

            $table->boolean('is_test')->default(false);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['product_id', 'created_at']);
            $table->index('movement_type');
        });

        // BR-30 — a milk_deduction sale creates a pending deduction against the
        // farmer's next payment. Phase 7 (§15.1) will consume these; capturing
        // them now is what BR-13..BR-16 demand regardless of where payment lands.
        Schema::create('pending_farmer_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('description');
            $table->string('status', 16)->default('pending');   // pending|settled|cancelled
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['farmer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_farmer_deductions');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
