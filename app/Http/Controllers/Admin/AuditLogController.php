<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * audit-log.html.
 *
 * AUDIT-6 — "The log is readable only with admin.audit.view and is never editable
 *   through any interface." There is no update or delete action on this
 *   controller, and none exists anywhere: the model refuses both and the database
 *   has triggers (DM-3).
 * AUDIT-1 — retention is 24 months minimum; nothing here prunes.
 */
class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $entries = AuditEntry::query()
            ->with('actor')
            ->ofEvent($request->string('event')->toString())
            ->ofModule($request->string('module')->toString())
            ->when($request->filled('actor'), fn ($query) => $query->where('actor_user_id', $request->integer('actor')))
            ->when($request->filled('reference'), fn ($query) => $query->where('reference', $request->string('reference')))
            /*
             * ARCH-9 / AUDIT-5 — the pickers post WAT calendar dates and
             * `occurred_at` is a UTC instant. Read as UTC, "from 5 Aug" began at
             * 01:00 WAT on the 5th and "to 5 Aug" ended at 00:59 WAT on the 6th, so
             * an auditor searching the day an entry was raised missed its first
             * hour and got an hour of the next. A denied user who quotes DENY-0004
             * has to be findable on the day they say it happened.
             */
            ->when($request->filled('from'), fn ($query) => $query
                ->where('occurred_at', '>=', Wat::dayStart($request->string('from')->toString())))
            // Half-open: the WAT day AFTER `to`, with a strict `<`.
            ->when($request->filled('to'), fn ($query) => $query
                ->where('occurred_at', '<', Wat::dayStart($request->string('to')->toString())->addDay()))
            // TEST-4 — test activity is tagged and can be filtered out.
            ->when(! $request->boolean('include_test'), fn ($query) => $query->excludingTestData())
            /*
             * AUDIT-5 — the search must match the QUOTABLE REFERENCE too.
             *
             * A denied user is shown "DENY-0004" and told to quote it. When they
             * do, the auditor pastes it into the search box on this screen and
             * got nothing back, because the box only looked at `summary`. There
             * was a separate `reference` filter, but nobody reading a reference
             * off a screenshot knows to use a different field — and the whole
             * point of a quotable reference is that quoting it finds the entry.
             */
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = (string) $request->string('q');

                $query->where(fn ($inner) => $inner
                    ->where('summary', 'like', '%'.$term.'%')
                    ->orWhere('reference', 'like', '%'.$term.'%'));
            })
            ->latest('occurred_at')
            ->latest('id')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        /*
         * ARCH-9 — Wat::today() is a WAT-ZONED Carbon, and Connection::prepareBindings
         * formats a DateTimeInterface in the object's own timezone. Binding it
         * against a UTC column compared the WAT wall clock "2026-07-07 00:00:00" to
         * UTC instants, so the three counters below started an hour late. dayStart()
         * returns the same moment already in UTC.
         */
        $since = Wat::dayStart(Wat::today()->subDays(30));

        return view('admin.audit-log', [
            'entries' => $entries,
            'eventTypes' => AuditEntry::EVENTS,
            'modules' => AuditEntry::query()->whereNotNull('module')->distinct()->orderBy('module')->pluck('module'),
            'actors' => User::query()->orderBy('name')->get(['id', 'name']),
            'counts' => [
                // BR-34 / AUDIT-5 — the number an administrator actually watches.
                'blocked' => AuditEntry::query()
                    ->where('event_type', AuditEntry::EVENT_BLOCKED_ACCESS)
                    ->where('occurred_at', '>=', $since)
                    ->count(),
                'permission_changes' => AuditEntry::query()
                    ->where('event_type', AuditEntry::EVENT_PERMISSION_CHANGE)
                    ->where('occurred_at', '>=', $since)
                    ->count(),
                'failed_signins' => AuditEntry::query()
                    ->where('event_type', AuditEntry::EVENT_FAILED_SIGNIN)
                    ->where('occurred_at', '>=', $since)
                    ->count(),
                'total' => AuditEntry::query()->count(),
            ],
            'retentionMonths' => (int) config('gondal.audit_retention_months', 24),
        ]);
    }
}
