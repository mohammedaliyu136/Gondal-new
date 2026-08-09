<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Reporting\PeriodReports;
use App\Support\Report;
use App\Support\Wat;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §15.5 / NG-7 — the reporting screen.
 *
 * No permission on the route itself, deliberately. Every report carries its own
 * (PeriodReports::catalogue()), the picker lists only the ones this user may
 * run, and the service authorises again with the record-less Access call before
 * it touches a table — so the route is a shell and the gate is per report. A
 * single route permission would have had to be the loosest of the five, which
 * is how a Sales Officer ends up one URL away from the revenue report.
 */
class ReportController extends Controller
{
    public function __construct(private readonly PeriodReports $reports) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $this->currentUser();

        $available = $this->reports->availableTo($user);

        [$from, $to] = $this->period($request);

        // Default to the first report they may actually run, not to a fixed one
        // they may not.
        // Same trap as the revalidation queue: a Stringable is always truthy, so
        // `?:` cannot default it. Without this the screen silently showed no
        // report at all until one was picked by hand.
        $key = (string) $request->input('report', $available[0] ?? '');

        $result = in_array($key, $available, true)
            ? $this->reports->run($key, $from, $to)
            : null;

        return view('reports.index', [
            'catalogue' => array_intersect_key(PeriodReports::catalogue(), array_flip($available)),
            'selected' => $key,
            'from' => $from,
            'to' => $to,
            'result' => $result,
            // SCOPE-4 — say whose figures these are, so a centre officer does not
            // read their own centre's totals as the network's.
            'scopeLabel' => $user->overallScopeDescription(),
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        [$from, $to] = $this->period($request);

        // run() authorises against the authenticated user, so an export cannot
        // reach data the screen refuses.
        $result = $this->reports->run($report, $from, $to);

        return Report::csv($result, sprintf('gondal-%s-%s-to-%s.csv', $report, $from, $to));
    }

    /**
     * The period, defaulting to the current WAT month.
     *
     * Clamped so `to` is never before `from`: a reversed range silently returns
     * nothing, and an empty report is indistinguishable from a quiet month.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request): array
    {
        $from = $request->filled('from')
            ? Wat::of((string) $request->input('from'))->toDateString()
            : Wat::today()->startOfMonth()->toDateString();

        $to = $request->filled('to')
            ? Wat::of((string) $request->input('to'))->toDateString()
            : Wat::today()->toDateString();

        return $to < $from ? [$to, $from] : [$from, $to];
    }
}
