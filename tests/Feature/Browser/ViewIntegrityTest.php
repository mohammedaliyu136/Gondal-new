<?php

namespace Tests\Feature\Browser;

use App\Exceptions\RuleViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\GondalTestCase;

/**
 * Structural guarantees about the views themselves.
 *
 * These exist because a Blade template can be broken in ways that no feature test
 * covering a rule will ever notice. An unclosed directive does not fail at build
 * time — Laravel compiles views lazily, on first render — so a mangled template
 * ships green and fails the first time an operator opens that screen.
 *
 * That is not hypothetical. A copy edit produced "Settings@endcan", and because
 * Blade refuses to parse a directive that directly follows a word character (the
 * guard that stops an email address being read as a directive), the @can was left
 * unclosed and the entire factory reconciliation screen became a 500 — while the
 * rule suite stayed green, because it tests the reconciliation SERVICE.
 */
class ViewIntegrityTest extends GondalTestCase
{
    /** Every Blade template must compile to valid PHP. */
    public function test_every_view_compiles(): void
    {
        $compiler = app('blade.compiler');
        $broken = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $compiled = $compiler->compileString($file->getContents());

            $temporary = tempnam(sys_get_temp_dir(), 'blade').'.php';
            file_put_contents($temporary, $compiled);
            $lint = (string) shell_exec('php -l '.escapeshellarg($temporary).' 2>&1');
            @unlink($temporary);

            if (! str_contains($lint, 'No syntax errors')) {
                $broken[] = $file->getRelativePathname().' — '.trim(explode("\n", $lint)[0]);
            }
        }

        $this->assertSame([], $broken, "A view does not compile:\n".implode("\n", $broken));
    }

    /**
     * No directive may sit directly against a word character, because Blade will
     * silently render it as literal text instead of parsing it. The failure is
     * invisible in the source and fatal at runtime.
     */
    public function test_no_directive_is_swallowed_by_an_adjacent_word_character(): void
    {
        $directives = 'if|endif|else|elseif|unless|endunless|foreach|endforeach|forelse|empty|endforelse|'
            .'can|endcan|cannot|endcannot|isset|endisset|section|endsection|php|endphp|include|extends|yield';

        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            if (preg_match_all('/\w@('.$directives.')\b/', $markup, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = substr_count(substr($markup, 0, $match[1]), "\n") + 1;
                    $offenders[] = $file->getRelativePathname().':'.$line.' — '.$match[0];
                }
            }
        }

        $this->assertSame([], $offenders, "A Blade directive is glued to a word character:\n".implode("\n", $offenders));
    }

    /**
     * No specification identifier may reach an operator's screen.
     *
     * Checked against the SOURCE of the operational views rather than rendered
     * output, so it holds for every branch of every conditional rather than only
     * the ones a test happens to exercise. Blade comments are stripped first —
     * they are developer notes and never rendered.
     *
     * The administration screens are excluded on purpose: on the roles screens,
     * the permission-test register and the audit log, permission keys and rule
     * references ARE the content.
     */
    public function test_no_specification_text_reaches_an_operational_screen(): void
    {
        $exempt = ['admin/roles', 'admin/permission-tests', 'admin/audit-log'];
        $pattern = '/\b(?:BR|ARCH|DM|ST|REF|PERM|ROLE|SCOPE|SCR|AUTH|NOTIF|AUDIT|NFR|TEST|USER|NG)-\d+\b|§/u';

        $leaks = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());

            foreach ($exempt as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            if (preg_match_all($pattern, $markup, $matches)) {
                $leaks[] = $relative.' — '.implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertSame(
            [],
            $leaks,
            "Specification text is rendered to an operator:\n".implode("\n", $leaks),
        );
    }

    /** And the rule identifier never reaches a validation message either. */
    public function test_a_rule_violation_message_carries_no_rule_identifier(): void
    {
        $exception = RuleViolationException::make(
            'BR-11',
            'The variance is beyond tolerance. Record a supervisor note before releasing it.',
            [],
            'supervisor_notes',
        );

        $this->assertStringNotContainsString('BR-11', $exception->getMessage());

        // But it is still carried where it belongs: on the API payload and in the
        // session, for the audit trail and for support.
        $json = RuleViolationException::make('BR-11', 'Message.', [], 'field')
            ->render(Request::create('/api/deliveries', 'POST'));

        $this->assertSame('BR-11', $json->getData(true)['rule']);
        $this->assertStringNotContainsString('BR-11', $json->getData(true)['errors']['field'][0]);
    }

    /**
     * The searchable-select enhancement must stay progressive.
     *
     * Long pickers (1,842 farmers) are unusable as native selects, but the fix
     * cannot become a dependency: a rural link that drops one script file must
     * not cost an agent the ability to record milk. So the real <select> keeps its
     * name, its options and its required attribute, and the script only draws over
     * it.
     */
    public function test_searchable_selects_remain_usable_without_javascript(): void
    {
        $marked = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            if (preg_match_all('/<select\b[^>]*data-searchable[^>]*>/', $markup, $matches)) {
                foreach ($matches[0] as $tag) {
                    $marked[] = [$file->getRelativePathname(), $tag];
                }
            }
        }

        $this->assertNotEmpty($marked, 'The enhancement should be applied to the long pickers.');

        foreach ($marked as [$path, $tag]) {
            // It is still a real form control: without a name it submits nothing.
            $this->assertMatchesRegularExpression(
                '/\bname="/',
                $tag,
                $path.' — an enhanced select must keep its name: '.$tag,
            );

            // And it must not be disabled or hidden in the markup, which would
            // make the no-JavaScript fallback useless.
            $this->assertDoesNotMatchRegularExpression('/\bdisabled\b/', $tag, $path.' — '.$tag);
            $this->assertDoesNotMatchRegularExpression('/\btype="hidden"/', $tag, $path.' — '.$tag);
        }
    }

    /** The script is loaded deferred, so it never blocks a slow page. */
    public function test_the_combo_script_is_deferred_and_present(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('js/combo.js', $layout);
        $this->assertMatchesRegularExpression('/<script[^>]*js\/combo\.js[^>]*defer/', $layout);

        $this->assertFileExists(public_path('js/combo.js'));
    }

    /**
     * A button labelled "+ Something" must actually open that something.
     *
     * The dashboard's "+ Record Milk Intake" pointed at the deliveries LIST, so
     * the agent's most-used button landed them on a page with the form still
     * shut. Nothing errors, nothing is logged — the label simply lies, and the
     * only way to notice is to click it.
     *
     * The rule: an action link either carries the fragment of the modal it opens,
     * or points at a route whose name is a create/edit action.
     */
    public function test_every_action_button_actually_opens_something(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            // <a href="...">+ Label</a> — the house convention for an action.
            if (! preg_match_all('/<a\s+href="([^"]*)"[^>]*>\s*\+\s*([^<]+)</', $markup, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as [$whole, $href, $label]) {
                $opensAModal = str_contains($href, '#');
                $isCreateRoute = (bool) preg_match('/route\(\s*.[^\'\"]*\.(create|edit)./', $href);

                if (! $opensAModal && ! $isCreateRoute) {
                    $offenders[] = sprintf(
                        '%s — "+ %s" links to %s, which opens no form',
                        $file->getRelativePathname(),
                        trim($label),
                        trim($href),
                    );
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    /** And the fragments they name must exist on the screen they land on. */
    public function test_action_fragments_point_at_a_real_modal(): void
    {
        $modalIds = [];
        $referenced = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            if (preg_match_all('/<div\s+id="(modal-[\w-]+)"/', $markup, $found)) {
                foreach ($found[1] as $id) {
                    $modalIds[$id] = true;
                }
            }

            // Static fragments only — ones built from a loop variable cannot be
            // checked this way and are covered by the screens rendering at all.
            if (preg_match_all('/href="[^"]*#(modal-[a-z-]+)"/', $markup, $links)) {
                foreach ($links[1] as $id) {
                    $referenced[$id] = $file->getRelativePathname();
                }
            }
        }

        $dangling = [];

        foreach ($referenced as $id => $where) {
            if (! isset($modalIds[$id])) {
                $dangling[] = $where.' links to #'.$id.', which no view defines';
            }
        }

        $this->assertSame([], $dangling, implode("\n", $dangling));
    }

    /**
     * Every modal form must show its OWN errors.
     *
     * A modal form carries a `_modal` marker naming itself, and the error block
     * inside it is keyed by the same name. If the two disagree the form either
     * stays silent about why it refused, or — worse — displays the errors of a
     * different modal on the same screen.
     *
     * This is a real defect that shipped: a bulk edit anchored an error block on
     * the wrong `modal-body` and dropped a per-consignment include into the batch
     * form, where the loop variable it named did not exist. That view returned a
     * 500 on every request until it was found.
     */
    public function test_every_modal_form_shows_its_own_errors(): void
    {
        $problems = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            if (! preg_match_all('/<form\b.*?<\/form>/s', $markup, $forms)) {
                continue;
            }

            foreach ($forms[0] as $form) {
                $hasMarker = (bool) preg_match('/name="_modal"\s+value="([^"]+)"/', $form, $marker);
                $hasBlock = (bool) preg_match(
                    "/partials\.modal-errors',\s*\['modal'\s*=>\s*(.+?)\]\)/", $form, $block
                );

                /*
                 * A form whose body comes from a shared partial holds its error
                 * block there, out of reach of this file. The partial is covered
                 * by the same rule when it is scanned in its own right.
                 */
                if ($hasMarker && ! $hasBlock && str_contains($form, "@include('partials.")) {
                    continue;
                }

                $where = $file->getRelativePathname();

                if ($hasMarker && ! $hasBlock) {
                    $problems[] = $where.': a form marked '.$marker[1].' shows no errors';
                } elseif ($hasBlock && ! $hasMarker) {
                    $problems[] = $where.': a form shows modal errors but names no modal';
                } elseif ($hasMarker && $this->modalKey($marker[1]) !== $this->modalKey($block[1])) {
                    $problems[] = $where.': a form marked '.$marker[1]
                        .' shows the errors of '.trim($block[1]);
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Reduces both spellings of a modal id to the same key: `modal-x-{{ $y->id }}`
     * in an attribute, and `'modal-x-'.$y->id` in an include argument.
     */
    private function modalKey(string $raw): string
    {
        $key = preg_replace('/\{\{.*?\}\}/', '', trim($raw));
        $key = preg_replace("/'\s*\.\s*.*$/", '', (string) $key);

        return trim((string) $key, "' .");
    }
}
