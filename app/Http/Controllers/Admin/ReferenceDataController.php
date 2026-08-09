<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityType;
use App\Models\AdjustmentReason;
use App\Models\Community;
use App\Models\Lga;
use App\Models\QualityTestDefinition;
use App\Models\ValidationReason;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * §9's registers, edited where §9 says they are edited.
 *
 * "Every value is a ROW an administrator edits through Settings" — but six of
 * these registers rendered read-only, so adding a visit type, an adjustment
 * reason, a quality test or an LGA needed a developer with database access. The
 * rule was true of the SCHEMA and false of the SYSTEM, which is the more
 * expensive half to get wrong: §18.7's test proves no reference value is
 * hardcoded, and could not notice that no value could be changed either.
 *
 * ONE screen, driven by a registry, rather than six near-identical controllers.
 * The registry describes the SHAPE of each register — which model, which
 * columns, what they are called — and never its VALUES, so it is metadata about
 * the schema in the same way PeriodReports::catalogue() is, and §18.7 is
 * untouched. Adding the seventh register is a registry entry.
 *
 * REF-1 — a reference row is retired, never deleted, because rows already
 * pointing at it must keep resolving. Nothing here deletes.
 */
class ReferenceDataController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The registers this screen owns.
     *
     * Deliberately NOT grades, rejection reasons, sequences or workflows: each
     * carries columns that decide a business rule (BR-13's effective-dated
     * rates, BR-1's per-stage availability and BR-5's thresholds), and a generic
     * form would let somebody produce a row that satisfies the database and
     * breaks the rule. Those keep their bespoke handlers in SettingsController.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registers(): array
    {
        return [
            'adjustment-reasons' => [
                'model' => AdjustmentReason::class,
                'label' => 'Adjustment reasons',
                'help' => 'Why a litre count or a stock figure was corrected. BR-7 requires one on every adjustment.',
                'fields' => [
                    'code' => ['label' => 'Code', 'rules' => ['required', 'string', 'max:24']],
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'help_text' => ['label' => 'Help text', 'rules' => ['nullable', 'string', 'max:255']],
                    // §18.7 — the vocabulary is NOT restated here. An earlier
                    // draft wrote `in:volume,stock` and the seeded rows say
                    // `consignment|any|stock`, so every edit was refused: the
                    // controller had quietly become a second, wrong copy of §9.
                    'applies_to' => ['label' => 'Applies to', 'optionsFrom' => 'applies_to'],
                ],
            ],
            'activity-types' => [
                'model' => ActivityType::class,
                'label' => 'Field activity types',
                'help' => 'What kind of visit happened. BR-5 — only the types flagged here can close a quality follow-up.',
                'fields' => [
                    'code' => ['label' => 'Code', 'rules' => ['required', 'string', 'max:24']],
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'help_text' => ['label' => 'Help text', 'rules' => ['nullable', 'string', 'max:255']],
                    'closes_quality_followup' => ['label' => 'Can close a follow-up', 'rules' => ['nullable', 'boolean'], 'type' => 'boolean'],
                ],
            ],
            'validation-reasons' => [
                'model' => ValidationReason::class,
                'label' => 'Revalidation reasons',
                'help' => 'Why a farmer was put on the revalidation list. BR-36.',
                'fields' => [
                    'code' => ['label' => 'Code', 'rules' => ['required', 'string', 'max:24']],
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'help_text' => ['label' => 'Help text', 'rules' => ['nullable', 'string', 'max:255']],
                    'is_automatic' => ['label' => 'Raised by the system', 'rules' => ['nullable', 'boolean'], 'type' => 'boolean'],
                ],
            ],
            'quality-tests' => [
                'model' => QualityTestDefinition::class,
                'label' => 'Quality tests',
                'help' => 'BR-4 — a grade may not be assigned until every required test here has a result.',
                'fields' => [
                    'code' => ['label' => 'Code', 'rules' => ['required', 'string', 'max:24']],
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'kind' => ['label' => 'Kind', 'optionsFrom' => 'kind'],
                    'min_value' => ['label' => 'Minimum', 'rules' => ['nullable', 'numeric']],
                    'max_value' => ['label' => 'Maximum', 'rules' => ['nullable', 'numeric']],
                    'unit' => ['label' => 'Unit', 'rules' => ['nullable', 'string', 'max:16']],
                    'is_required' => ['label' => 'Required before grading', 'rules' => ['nullable', 'boolean'], 'type' => 'boolean'],
                ],
            ],
            'lgas' => [
                'model' => Lga::class,
                'label' => 'LGAs',
                'help' => 'Local government areas. A community belongs to one, and a scope can be drawn round it.',
                'statusless' => true,
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'code' => ['label' => 'Code', 'rules' => ['nullable', 'string', 'max:24']],
                ],
            ],
            'communities' => [
                'model' => Community::class,
                'label' => 'Communities',
                'help' => 'Where farmers are enrolled and extension agents are scoped. The field app refuses a community that is not on this list.',
                'statusless' => true,
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => ['required', 'string', 'max:255']],
                    'lga_id' => ['label' => 'LGA', 'rules' => ['required', 'exists:lgas,id'], 'relation' => Lga::class],
                ],
            ],
        ];
    }

    /**
     * The values a column already holds, as the picker's options.
     *
     * §18.7 forbids §9's reference data appearing as a constant, and a list of
     * permitted values written into this controller is exactly that — a second
     * copy of the vocabulary, free to drift from the rows. Reading the distinct
     * values back means the picker is always the vocabulary the data actually
     * uses, and an administrator cannot invent a word the rules do not handle.
     *
     * @return array<string, string>
     */
    public static function optionsFor(string $model, string $column): array
    {
        /** @var class-string<Model> $model */
        $values = $model::query()->distinct()->orderBy($column)->pluck($column)
            ->filter()->map(fn ($value) => (string) $value)->all();

        return array_combine($values, array_map(
            static fn (string $value) => ucfirst(str_replace('_', ' ', $value)),
            $values,
        ));
    }

    public function index(Request $request): View
    {
        $this->authorizeAccess('admin.settings.edit', null, 'Reference data');

        $registers = self::registers();
        $key = (string) $request->input('register', array_key_first($registers));

        abort_unless(isset($registers[$key]), 404);

        $definition = $registers[$key];
        /** @var class-string<Model> $model */
        $model = $definition['model'];

        $query = $model::query();

        // Communities and LGAs are ordered by name; the rest carry an explicit
        // position because the order they appear in a picker is the
        // administrator's decision, not the alphabet's.
        $rows = ($definition['statusless'] ?? false)
            ? $query->orderBy('name')->get()
            : $query->orderBy('position')->orderBy('name')->get();

        // Resolved here rather than in the registry so the picker shows the
        // vocabulary the rows actually use (§18.7).
        foreach ($definition['fields'] as $field => $spec) {
            if (isset($spec['optionsFrom'])) {
                $definition['fields'][$field]['options'] = self::optionsFor($model, $spec['optionsFrom']);
            }
        }

        return view('admin.reference.index', [
            'registers' => $registers,
            'selected' => $key,
            'definition' => $definition,
            'rows' => $rows,
            'lgas' => Lga::query()->orderBy('name')->get(),
            'canEdit' => $this->allows('admin.settings.edit'),
        ]);
    }

    public function store(Request $request, string $register): RedirectResponse
    {
        [$definition, $model] = $this->resolve($register);

        $this->authorizeAccess('admin.settings.edit', null, 'Add to '.$definition['label']);

        $row = $model::query()->create($this->attributes($request, $definition));

        $this->audit->created(
            $row,
            sprintf('%s: %s added', $definition['label'], $row->name),
            'Administration',
            ['register' => $register],
            $this->currentUser(),
        );

        return back()->with('success', $row->name.' added.');
    }

    public function update(Request $request, string $register, int $id): RedirectResponse
    {
        [$definition, $model] = $this->resolve($register);

        $this->authorizeAccess('admin.settings.edit', null, 'Edit '.$definition['label']);

        /** @var Model $row */
        $row = $model::query()->findOrFail($id);

        $attributes = $this->attributes($request, $definition);
        $before = $row->only(array_keys($attributes));

        $row->forceFill($attributes)->save();

        /*
         * Audited with both sides because these rows are read by rules. Flipping
         * "can close a follow-up" changes which visits satisfy BR-5, and moving
         * a quality test's minimum changes which milk BR-4 lets through — so the
         * change needs to be as answerable-for as a grade rate.
         */
        $this->audit->edited(
            $row,
            sprintf('%s: %s updated', $definition['label'], $row->name),
            'Administration',
            $before,
            $attributes,
            $this->currentUser(),
        );

        return back()->with('success', $row->name.' updated.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: array<string, mixed>, 1: class-string<Model>}
     */
    private function resolve(string $register): array
    {
        $definition = self::registers()[$register] ?? null;

        abort_if($definition === null, 404);

        return [$definition, $definition['model']];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function attributes(Request $request, array $definition): array
    {
        $rules = [];

        foreach ($definition['fields'] as $field => $spec) {
            $rules[$field] = $spec['rules']
                // A column whose vocabulary comes from the data validates
                // against that same list, so the two can never disagree.
                ?? ['required', Rule::in(
                    array_keys(self::optionsFor($definition['model'], $spec['optionsFrom'])),
                )];
        }

        if (! ($definition['statusless'] ?? false)) {
            // REF-1 — retire, never delete.
            $rules['status'] = ['required', 'in:active,retired'];
            $rules['position'] = ['nullable', 'integer', 'min:0'];
        }

        $validated = $request->validate($rules);

        // An unchecked checkbox is absent from the payload, not false, so a
        // boolean left out would keep its old value instead of being cleared.
        foreach ($definition['fields'] as $field => $spec) {
            if (($spec['type'] ?? null) === 'boolean') {
                $validated[$field] = $request->boolean($field);
            }
        }

        return $validated;
    }
}
