<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.2 / §9 — LGAs and communities are reference data an administrator edits,
 * never an enum. They come first because scope targets and almost every
 * operational table point at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgas', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 16)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();          // ARCH-8
        });

        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lga_id')->constrained('lgas')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lga_id', 'name']);
            $table->index('lga_id');        // NFR-3
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
        Schema::dropIfExists('lgas');
    }
};
