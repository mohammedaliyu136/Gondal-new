<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.2 — collection centers and the points that feed them.
 *
 * SCOPE-1 — both tables are scope targets: `center` means "that center and the
 * points feeding it", which is why collection_points.collection_center_id is
 * mandatory rather than optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->foreignId('lga_id')->constrained('lgas')->restrictOnDelete();
            $table->foreignId('officer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('logistics_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('cold_storage_litres', 10, 2)->nullable();      // ARCH-6
            $table->decimal('distance_to_factory_km', 8, 2)->nullable();
            $table->unsignedBigInteger('transport_fee_minor')->nullable();  // ARCH-6
            $table->string('status', 16)->default('active');                // active|suspended
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'lga_id']);
        });

        Schema::create('collection_points', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->foreignId('community_id')->constrained('communities')->restrictOnDelete();
            $table->foreignId('lga_id')->constrained('lgas')->restrictOnDelete();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('collection_center_id')->constrained('collection_centers')->restrictOnDelete();

            // BR-3 — the point's own cut-off. Null means "use the default from
            // Settings"; a value must not exceed the latest permitted override.
            $table->time('cutoff_time')->nullable();

            $table->unsignedBigInteger('transport_fee_minor')->nullable();
            $table->string('status', 16)->default('active');                // active|idle|suspended
            $table->date('opened_on')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['collection_center_id', 'status']);
            $table->index(['lga_id', 'status']);
            $table->index('community_id');
            $table->index('agent_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_points');
        Schema::dropIfExists('collection_centers');
    }
};
