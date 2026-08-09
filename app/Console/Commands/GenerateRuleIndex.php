<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * §18.2 — "Every business rule for that module has a passing automated test,
 * referenced by rule ID."
 *
 * That is checkable, so it is checked. This command reads every numbered
 * requirement out of the PRD, reads every test method out of the suite, and
 * matches them on the rule ID that the test's docblock or body quotes. The result
 * is docs/RULE-INDEX.md.
 *
 * Run with --check to make it fail instead of writing: a rule with no referencing
 * test exits non-zero, which is what makes the definition of done enforceable in
 * CI rather than a promise in a document.
 */
class GenerateRuleIndex extends Command
{
    protected $signature = 'gondal:rule-index
                            {--check : Exit non-zero if any requirement has no referencing test}';

    protected $description = 'Regenerate docs/RULE-INDEX.md from PRD.md and the test suite';

    /** The section each rule prefix belongs to, for the report headings. */
    private const SECTIONS = [
        'ARCH' => 'Architecture (§3)',
        'AUDIT' => 'Audit log (§12)',
        'AUTH' => 'Authentication (§10)',
        'BR' => 'Business rules (§8)',
        'DM' => 'Data-model invariants (§6)',
        'NFR' => 'Non-functional requirements (§13)',
        'NG' => 'Explicit non-goals (§2)',
        'NOTIF' => 'Notifications (§11)',
        'PERM' => 'Permissions (§5.1)',
        'REF' => 'Reference data (§9)',
        'ROLE' => 'Roles (§5.2)',
        'SCOPE' => 'Data scope (§5.3)',
        'SCR' => 'Screens (§4)',
        'ST' => 'State machines (§8)',
        'TEST' => 'Permission testing protocol (§5.4)',
        'USER' => 'Users versus records (§5.5)',
    ];

    public function handle(): int
    {
        $prdPath = base_path('../PRD.md');

        if (! File::exists($prdPath)) {
            $this->components->error('PRD.md not found at '.$prdPath);

            return self::FAILURE;
        }

        $requirements = $this->requirements(File::get($prdPath));
        $tests = $this->testsByRule();

        $uncovered = array_values(array_filter(
            array_keys($requirements),
            fn (string $rule) => ! isset($tests[$rule]),
        ));

        if ($this->option('check')) {
            if ($uncovered !== []) {
                $this->components->error(sprintf(
                    '§18.2 — %d requirement(s) have no referencing test: %s',
                    count($uncovered),
                    implode(', ', $uncovered),
                ));

                return self::FAILURE;
            }

            $this->components->info(sprintf(
                '§18.2 — all %d requirements are referenced by at least one test.',
                count($requirements),
            ));

            return self::SUCCESS;
        }

        $path = base_path('docs/RULE-INDEX.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->render($requirements, $tests));

        $this->components->info(sprintf(
            'docs/RULE-INDEX.md written — %d requirements, %d covered, %d uncovered.',
            count($requirements),
            count($requirements) - count($uncovered),
            count($uncovered),
        ));

        return $uncovered === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every **RULE-N** marker in the PRD, with the statement that follows it.
     *
     * The statement runs from the marker to whichever comes first: the next
     * marker, or the next heading. Rules are written both as paragraphs and as
     * list items, so neither a blank line nor a line prefix is a reliable
     * terminator — the next marker is.
     *
     * @return array<string, string>
     */
    private function requirements(string $prd): array
    {
        preg_match_all('/\*\*([A-Z]{2,6}-\d+)\*\*/', $prd, $matches, PREG_OFFSET_CAPTURE);

        $markers = $matches[0];
        $ids = $matches[1];
        $requirements = [];

        foreach ($markers as $index => [$marker, $offset]) {
            $rule = $ids[$index][0];
            $start = $offset + strlen($marker);
            $end = $markers[$index + 1][1] ?? strlen($prd);

            $slice = substr($prd, $start, $end - $start);

            if (preg_match('/\n#{1,4} /', $slice, $heading, PREG_OFFSET_CAPTURE)) {
                $slice = substr($slice, 0, $heading[0][1]);
            }

            $text = trim((string) preg_replace('/\s+/', ' ', $slice), " -\u{2013}\u{2014}:");

            // A rule quoted twice keeps its fullest statement.
            if (! isset($requirements[$rule]) || strlen($text) > strlen($requirements[$rule])) {
                $requirements[$rule] = $text;
            }
        }

        uksort($requirements, function (string $a, string $b): int {
            [$aPrefix, $aNumber] = explode('-', $a);
            [$bPrefix, $bNumber] = explode('-', $b);

            return [$aPrefix, (int) $aNumber] <=> [$bPrefix, (int) $bNumber];
        });

        return $requirements;
    }

    /**
     * rule id => [[file, method], ...]
     *
     * A test "references" a rule when the ID appears in its docblock or its body,
     * which is the convention the whole suite is written to.
     *
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    private function testsByRule(): array
    {
        $found = [];

        foreach (File::allFiles(base_path('tests')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $pattern = '/(\/\*[\s\S]*?\*\/)?\s*public function (test_\w+)\([^)]*\): void\s*\{([\s\S]*?)\n    \}/';

            preg_match_all($pattern, $file->getContents(), $methods, PREG_SET_ORDER);

            foreach ($methods as $method) {
                preg_match_all('/\b([A-Z]{2,6}-\d+)\b/', $method[1].$method[3], $rules);

                foreach (array_unique($rules[1]) as $rule) {
                    $found[$rule][] = [$file->getFilenameWithoutExtension(), $method[2]];
                }
            }
        }

        foreach ($found as $rule => $tests) {
            sort($tests);
            $found[$rule] = $tests;
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $requirements
     * @param  array<string, array<int, array{0: string, 1: string}>>  $tests
     */
    private function render(array $requirements, array $tests): string
    {
        $lines = [
            '# Rule index',
            '',
            'Every numbered requirement in [`PRD.md`](../../PRD.md), and the test that proves it.',
            '',
            '§18.2 requires that *"every business rule for that module has a passing automated test,',
            'referenced by rule ID"*. This table is generated by matching rule IDs across the PRD text and',
            'the test suite, so a requirement with no referencing test appears as `—` rather than quietly',
            'passing.',
            '',
            'Regenerate with `php artisan gondal:rule-index`, or fail a build on a gap with',
            '`php artisan gondal:rule-index --check`.',
        ];

        $grouped = [];

        foreach ($requirements as $rule => $text) {
            $grouped[Str::before($rule, '-')][$rule] = $text;
        }

        $covered = 0;

        foreach ($grouped as $prefix => $rules) {
            $lines[] = '';
            $lines[] = '## '.(self::SECTIONS[$prefix] ?? $prefix);
            $lines[] = '';
            $lines[] = '| Rule | Requirement | Proven by |';
            $lines[] = '| --- | --- | --- |';

            foreach ($rules as $rule => $text) {
                $statement = Str::limit(str_replace('|', '\\|', $text), 170);
                $referencing = $tests[$rule] ?? [];

                if ($referencing !== []) {
                    $covered++;
                    $shown = array_slice($referencing, 0, 3);
                    $cell = implode('<br>', array_map(
                        fn (array $test) => '`'.$test[0].'::'.$test[1].'`',
                        $shown,
                    ));

                    if (count($referencing) > 3) {
                        $cell .= '<br>…and '.(count($referencing) - 3).' more';
                    }
                } else {
                    $cell = '—';
                }

                $lines[] = sprintf('| **%s** | %s | %s |', $rule, $statement, $cell);
            }
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = sprintf(
            '**%d of %d** requirements have at least one referencing test.',
            $covered,
            count($requirements),
        );

        return implode("\n", $lines)."\n";
    }
}
