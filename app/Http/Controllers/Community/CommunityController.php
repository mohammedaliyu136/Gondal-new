<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Lga;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Communities — the settlements farmers belong to and collection points sit in.
 *
 * There was no screen for these at all: the 26 seeded communities were the only
 * ones that could ever exist, so a supervisor could create a collection point but
 * not the community it stands in, and an engagement officer could enrol farmers
 * only into places somebody had seeded months earlier.
 *
 * TWO AUDIENCES, one screen. A community is where the collection network and the
 * community-engagement programme meet: the Milk Collection Supervisor creates one
 * because a new point needs somewhere to be, and the Community Engagement Officer
 * creates one because a new settlement has joined the cooperative. Both are
 * legitimate, so either grant opens it — the same reasoning as the collection
 * centre screen.
 */
class CommunityController extends Controller
{
    private const GRANTS = ['community.cooperatives.create', 'milk.points.create'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $communities = Community::query()
            ->with('lga')
            ->withCount(['farmers', 'collectionPoints'])
            ->when($request->filled('lga'), fn ($query) => $query->where('lga_id', $request->integer('lga')))
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('community.communities.index', [
            'communities' => $communities,
            'lgas' => Lga::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $this->allowsAny(self::GRANTS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAnyAccess(self::GRANTS, null, 'Add a community');

        $validated = $request->validate($this->rules());

        $community = Community::query()->create($validated);

        $this->audit->created(
            $community,
            sprintf('Community "%s" added in %s', $community->name, $community->lga?->name ?? 'its LGA'),
            'Community Engagement',
            ['lga' => $community->lga?->name],
            $this->currentUser(),
        );

        return back()->with('success', $community->name.' added.');
    }

    public function update(Request $request, Community $community): RedirectResponse
    {
        $this->authorizeAnyAccess(self::GRANTS, $community, 'Community → '.$community->name);

        $validated = $request->validate($this->rules($community));

        $before = $community->only(['name', 'lga_id']);

        $community->fill($validated)->save();

        $this->audit->edited(
            $community,
            sprintf('Community "%s" updated', $community->name),
            'Community Engagement',
            $before,
            $community->only(array_keys($before)),
            $this->currentUser(),
        );

        return back()->with('success', $community->name.' updated.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Community $community = null): array
    {
        return [
            'lga_id' => ['required', 'exists:lgas,id'],
            /*
             * Unique within its LGA, matching the table's own constraint. Two
             * villages of the same name in different LGAs is ordinary in Adamawa;
             * two in the SAME one is a data-entry mistake that would split a
             * community's farmers across duplicate records.
             */
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('communities', 'name')
                    ->where('lga_id', (int) request()->integer('lga_id'))
                    ->ignore($community?->getKey()),
            ],
        ];
    }
}
