<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.6 cooperatives — created before farmers because farmers.cooperative_id
 * points here.
 *
 * USER-1 — chairman, secretary and treasurer are NAMES on this record. They are
 * not accounts and cannot sign in.
 *
 * NG-1 / §15.6 — loans and investments are deferred. No loan tables exist, and
 * only the two accounts §9 lists (general, social) are created per cooperative.
 *
 * §15.3 — the manual cooperative forms are outstanding from Muhammad Bello.
 * This is the §6.6 schema verbatim; extend it when the forms arrive rather than
 * inventing fields now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooperatives', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->date('registered_on')->nullable();
            $table->foreignId('community_id')->nullable()->constrained('communities')->nullOnDelete();
            $table->foreignId('lga_id')->nullable()->constrained('lgas')->nullOnDelete();

            $table->string('chairman_name')->nullable();
            $table->string('secretary_name')->nullable();
            $table->string('treasurer_name')->nullable();
            $table->string('contact_phone', 32)->nullable();

            $table->foreignId('collection_point_id')->nullable()->constrained('collection_points')->nullOnDelete();

            // BR-15 — the live percentages. They are SNAPSHOTTED onto each
            // payable calculation, so editing them never moves a past figure.
            $table->decimal('savings_deduction_pct', 5, 2)->default(0);
            $table->decimal('levy_pct', 5, 2)->default(0);
            $table->unsignedBigInteger('social_contribution_minor')->default(0);

            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'lga_id']);
            $table->index('community_id');
        });

        // §9 — "Accounts held per cooperative: general cooperative fund, social
        // fund". The loan book checkbox on settings.html is deliberately off.
        Schema::create('cooperative_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained('cooperatives')->cascadeOnDelete();
            $table->string('kind', 16);                       // general|social
            $table->bigInteger('balance_minor')->default(0);  // ARCH-6, signed
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['cooperative_id', 'kind']);
        });

        Schema::create('cooperative_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_account_id')->constrained('cooperative_accounts')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('description');
            $table->string('direction', 4);                   // in|out
            $table->unsignedBigInteger('amount_minor');
            $table->bigInteger('balance_after_minor');

            // Polymorphic provenance: a milk payment, a sale deduction, a manual entry.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['cooperative_account_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cooperative_entries');
        Schema::dropIfExists('cooperative_accounts');
        Schema::dropIfExists('cooperatives');
    }
};
