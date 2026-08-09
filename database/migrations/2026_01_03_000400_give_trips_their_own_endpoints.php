<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCOPE-1 — put the journey's endpoints on the journey.
 *
 * A trip's only location was its ROUTE's, and a route is a tariff template:
 * `routes.from_id` and `routes.to_id` are nullable with no foreign key, and the
 * generic point→center tariff rows are seeded with the types set and the ids
 * NULL. Neither branch of Trip::scopeConstraints() can match a NULL, so every
 * trip logged on a point→center leg was invisible to a point-, centre- or
 * LGA-scoped user and visible only to a network-scoped one — the exact inverse
 * of who runs the leg. A Logistics Officer scoped to their own centre opened
 * /logistics and saw an empty list.
 *
 * The second consequence is arithmetic rather than access: with no endpoint of
 * its own, transport cost per collection point cannot be computed at all, even
 * though `collection_points.transport_fee_minor` exists precisely to be
 * compared against it.
 *
 * The route stays the tariff template it always was. `routes.from_id`/`to_id`
 * are deliberately left alone — nothing writes them, and a route may legitimately
 * describe a class of leg rather than one pair of places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->foreignId('collection_point_id')->nullable()
                ->constrained('collection_points')->nullOnDelete();
            $table->foreignId('collection_center_id')->nullable()
                ->constrained('collection_centers')->nullOnDelete();
        });

        // NFR-3 — both are scope predicates on the trip list, so both lead an
        // index rather than relying on the foreign key to imply one.
        Schema::table('trips', function (Blueprint $table): void {
            $table->index('collection_point_id', 'trips_collection_point_id_index');
            $table->index('collection_center_id', 'trips_collection_center_id_index');
        });

        $this->backfillFromRouteEndpoints();
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropIndex('trips_collection_point_id_index');
            $table->dropIndex('trips_collection_center_id_index');
            $table->dropConstrainedForeignId('collection_point_id');
            $table->dropConstrainedForeignId('collection_center_id');
        });
    }

    /**
     * Recover what can be recovered: the route's endpoints, where the route
     * actually named one. A route with NULL ids never located its trips, so
     * there is nothing on those rows to recover and they stay unlocated until
     * someone re-records them — which is honest, and is what the empty columns
     * say. `routes` holds a handful of rows, so the per-route loop costs less
     * than the correlated update it replaces and works identically on SQLite.
     */
    private function backfillFromRouteEndpoints(): void
    {
        $routes = DB::table('routes')->get(['id', 'from_type', 'from_id', 'to_type', 'to_id']);

        foreach ($routes as $route) {
            $endpoints = array_filter([
                'collection_point_id' => $this->endpointId($route, 'collection_point'),
                'collection_center_id' => $this->endpointId($route, 'collection_center'),
            ], static fn (?int $id): bool => $id !== null);

            if ($endpoints === []) {
                continue;
            }

            DB::table('trips')->where('route_id', $route->id)->update($endpoints);
        }
    }

    private function endpointId(object $route, string $type): ?int
    {
        if ($route->from_type === $type && $route->from_id !== null) {
            return (int) $route->from_id;
        }

        if ($route->to_type === $type && $route->to_id !== null) {
            return (int) $route->to_id;
        }

        return null;
    }
};
