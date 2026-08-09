<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.3 logistics.
 *
 * USER-1 — riders and drivers are records, not accounts. `drivers` has no
 * credentials and no link to `users`.
 *
 * §15.1 — trips.payment_run_id is specified by §6.3 but the payment module's
 * placement is an OPEN DECISION and Phase 7 is blocked. The column exists so
 * transport fees are captured correctly now (BR-13..BR-16), and deliberately
 * carries no foreign key until §15.1 is answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('registration', 32)->unique();
            $table->string('type', 16);                          // motorcycle|commercial|company
            $table->decimal('capacity_litres', 10, 2)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('licence_no', 48)->nullable();
            $table->string('type', 16)->default('rider');        // rider|driver
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // §9 — transport tariffs are reference data, edited in Settings.
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('from_type', 32);                     // collection_point|collection_center|factory
            $table->unsignedBigInteger('from_id')->nullable();
            $table->string('to_type', 32);
            $table->unsignedBigInteger('to_id')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->unsignedBigInteger('tariff_minor')->default(0);
            $table->string('vehicle_type', 16)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['from_type', 'from_id']);
            $table->index(['to_type', 'to_id']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('route_id')->constrained('routes')->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('logged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('litres_carried', 10, 2)->default(0);

            // BR-2 — the fee is snapshotted from the route tariff at logging
            // time and never recomputed from a live join.
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('route_tariff_minor_snapshot')->nullable();

            $table->string('payment_status', 16)->default('queued');   // queued|approved|paid

            // §15.1 — intentionally unconstrained. See the class docblock.
            $table->unsignedBigInteger('payment_run_id')->nullable();

            // BR-35 / TEST-1 — denormalised from the logging user so aggregates
            // can exclude test activity with a single indexed predicate.
            $table->boolean('is_test')->default(false);

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['payment_status', 'departed_at']);
            $table->index('departed_at');
            $table->index('is_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};
