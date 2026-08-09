<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the field worker was standing when they recorded it.
 *
 * WHY THIS IS WORTH A COLUMN. A field visit and a revalidation are both claims
 * about somewhere the worker went. The register already records that a visit
 * happened, who logged it and when it synced — but nothing about it can be
 * checked after the fact, and these are records that release a payment hold
 * (BR-36) and close a quality follow-up (BR-5). A coordinate is what makes the
 * claim reviewable at all.
 *
 * NULLABLE, AND DELIBERATELY SO. Adamawa's dairy belt has places with no fix
 * and phones with no working GPS, and a visit that genuinely happened must
 * still be recordable — refusing it would push the work back to paper, which is
 * the failure this whole system exists to end. `located_at` separates "we have
 * no coordinate" from "we have one, taken at this moment": a fix carried over
 * from a previous screen is worth less than one taken as the form was saved,
 * and only the timestamp can tell them apart.
 *
 * `location_accuracy_m` is stored because a 2,000-metre fix and a 5-metre fix
 * are not the same evidence, and a reviewer looking at a coordinate has no way
 * to know which they are holding otherwise.
 *
 * NOT A GATE. Nothing in the rules reads these columns. They are evidence for a
 * human reviewing an exception, not an input to any automatic decision — a
 * coordinate that silently refused a visit would be a rule written in a
 * migration, and §9 says rules are rows.
 */
return new class extends Migration
{
    /** decimal(10, 7) — ~11 mm at the equator, far beyond any phone's fix. */
    private const TABLES = ['field_activities', 'farmer_validations'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'latitude')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->decimal('latitude', 10, 7)->nullable();
                $blueprint->decimal('longitude', 10, 7)->nullable();
                $blueprint->unsignedInteger('location_accuracy_m')->nullable();
                $blueprint->timestamp('located_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'latitude')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['latitude', 'longitude', 'location_accuracy_m', 'located_at']);
            });
        }
    }
};
