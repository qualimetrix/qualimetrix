<?php

declare(strict_types=1);

/**
 * The named file set of round X18 package P1, reconciled from the two
 * populations of 205 that `03-cure.md` §P1.3 forbids gluing together.
 *
 * The two are not comparable in their own units. A site in
 * `measurement/form-deciding-sites.tsv` is a `(file, line)`; a declared pair in
 * `measurement/key-pairs.tsv` is a `(producer rule name, key path)` summed over
 * 54 producers, so one options class is counted once per rule it serves. The
 * only unit both project onto is the FILE, reached from a pair through the
 * class that declares it — which is exactly the reconciliation rule the plan
 * states: every class declaring a path from the axis-A denominator enters P1.
 *
 * Four sets, and every complement between them printed rather than assumed:
 *
 * - `D`  files of the classes reached from the declared pairs, via the live
 *        container and `ReflectionClass::getFileName()`;
 * - `S`  files carrying a subject site, i.e. a row of `form-deciding-sites.tsv`
 *        that is neither an exception candidate nor already inside
 *        `RuleOptionsFactory`;
 * - `N`  the contract and factory files the plan names by hand;
 * - `P1 = N ∪ S ∪ D`.
 *
 * The declared side is asked of the product, never hand-typed: the container
 * yields producers, each producer's options class states its own key set, and
 * the private halves of `RuleOptionKeySet` are read by reflection because
 * `acceptedForDisplay()` prints only one of them. Axis A counts the `accepted`
 * half alone; the `answered-by-the-class` half is reported separately so P1
 * knows it exists rather than discovering it later.
 *
 * The script is also the package's regression guard: run without `--write` it
 * regenerates the set and compares it with the artefact on disk, exiting 1 on
 * any difference.
 *
 * Usage: php scripts/promise-effect-p1-set.php [--write]
 *
 * Exit codes: 0 the artefact agrees with the tree, 1 it is stale or missing,
 * 2 a declaration cannot be judged at all — a hand-typed path no file stands
 * at, or a complement the run states must be empty and which is not.
 */

use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Builds `measurement/p1-file-set.tsv` and guards it against the tree.
 */
final class PromiseEffectP1Set
{
    private const string ROOT = __DIR__ . '/../';

    private const string MEASUREMENT = 'docs/internal/plans/promise-effect/measurement/';

    private const string OUTPUT = self::MEASUREMENT . 'p1-file-set.tsv';

    private const string SITES = self::MEASUREMENT . 'form-deciding-sites.tsv';

    private const string HIERARCHICAL = self::MEASUREMENT . 'hierarchical-options.tsv';

    private const string FROZEN_RANGES = self::MEASUREMENT . 'promise-ledger-frozen-ranges.tsv';

    private const string CONFIG_PATHS = self::MEASUREMENT . 'config-paths.tsv';

    /**
     * Package P2's file set (`03-cure.md`), which P1 must not touch.
     *
     * @var list<string>
     */
    private const array P2_FILES = [
        'src/Analysis/Configuration/Pipeline/ConfigDataNormalizer.php',
        'src/Analysis/Configuration/Loader/YamlConfigLoader.php',
    ];

    /**
     * Package P4's four named adapters; the rest of its set comes from the
     * `owner` column of `config-paths.tsv`.
     *
     * @var list<string>
     */
    private const array P4_ADAPTERS = [
        'src/Infrastructure/Console/ConfigurationInputAdapter.php',
        'src/Infrastructure/Console/CliOptionsParser.php',
        'src/Infrastructure/Console/RuleInputValidator.php',
        'src/Infrastructure/Console/ExitPolicy.php',
    ];

    /** @var list<string> owner cells of config-paths.tsv that name no loadable class */
    private array $unresolvedP4Owners = [];

    /**
     * Files the plan names by hand: the contract P1 rewrites, the two recognition
     * sites that consume it, and the one sub-tree key set nobody was given.
     *
     * @var array<string, string>
     */
    private const array NAMED = [
        'src/Analysis/Finding/Contract/Rule/RuleOptionKeySet.php' => 'the declaration itself: P1 absorbs value form into this set',
        'src/Analysis/Finding/Contract/Rule/RuleOptionsInterface.php' => 'contract stating acceptedOptionKeys()',
        'src/Analysis/Finding/Contract/Rule/LevelOptionsInterface.php' => 'contract stating a level slot key set',
        'src/Analysis/Finding/Contract/Rule/HierarchicalRuleOptionsInterface.php' => 'contract stating levelOptionsClasses(), the source of slot existence',
        'src/Analysis/Evidence/ComputedMetrics/Configuration/ComputedMetricEntryKeys.php' => 'builds a RuleOptionKeySet for the user sub-tree and is owned by no producer',
        'src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php' => 'the consumer: refusal must read the declaration, not a substring of the key name',
        'src/Analysis/Finding/RuleConfiguration/RuleOptionKeyRecognition.php' => 'the consumer asking the set what it knows',
        'src/Analysis/Finding/Contract/Rule/RuleOptionRefusalWording.php' => 'the words of every rule-option refusal, including the one about a value\'s form',
        'src/Analysis/Finding/Exclusion/RuleNamespaceExclusionProvider.php' => 'the throw site behind most malformed framework-key cells, in no other package set',
        'src/Analysis/Finding/Exclusion/RulePathExclusionProvider.php' => 'its neighbour, which judged no form at all',
    ];

    /**
     * The two rows the orchestrator added by decision rather than by the
     * plan's own list. Kept apart so the table still says which authority put
     * each file in the set.
     *
     * @var array<string, true>
     */
    private const array ADDED_BY_THE_ORCHESTRATOR = [
        'src/Analysis/Finding/Contract/Rule/RuleOptionRefusalWording.php' => true,
        'src/Analysis/Finding/Exclusion/RuleNamespaceExclusionProvider.php' => true,
        'src/Analysis/Finding/Exclusion/RulePathExclusionProvider.php' => true,
    ];

    /**
     * Frozen whole files: none of them may appear in P1 (`03-cure.md`).
     *
     * The first entry was named `RuleOptionThresholdModeResolver.php` when this
     * list was written and is the same file under the name its subject took in
     * X20 — it stopped evicting a mode and started unfolding a shorthand. The
     * path is updated rather than the entry dropped: the claim this list makes
     * is about a file that was frozen whole during that round, and a rename
     * does not retire it. A path naming nothing would make the check pass by
     * matching nothing, which is the failure mode this whole programme keeps
     * finding.
     *
     * @var list<string>
     */
    private const array FROZEN_FILES = [
        'src/Analysis/Finding/RuleConfiguration/RuleOptionThresholdShorthand.php',
        'src/Analysis/Finding/Configuration/FindingConfigurationResolver.php',
        'src/Analysis/Finding/RuleConfiguration/RuleThresholdKeyGroupRegistry.php',
        'src/Analysis/Finding/RuleConfiguration/RuleOptionsRegistry.php',
    ];

    /**
     * The five hierarchical classes that must be in P1, whose flat branch is
     * frozen by line instead of by file.
     *
     * @var list<string>
     */
    private const array HIERARCHICAL_REQUIRED = [
        'ComplexityOptions',
        'CognitiveComplexityOptions',
        'NpathComplexityOptions',
        'CboOptions',
        'InstabilityOptions',
    ];

    /**
     * Every hand-typed path this file declares, grouped by the constant that
     * declares it.
     *
     * The grouping exists so {@see self::missingDeclaredPaths()} can be one
     * rule over all of them. Two of these lists used to name a path no file
     * stood at — `Configuration/YamlConfigLoader.php` for what is really
     * `Configuration/Loader/YamlConfigLoader.php`, and
     * `Finding/FindingConfigurationResolver.php` for
     * `Finding/Configuration/FindingConfigurationResolver.php`. Both
     * disjointness claims are made with `array_intersect` over strings, so
     * those two files could never enter an intersection however deeply P1 had
     * touched them: the guard was blind exactly where it was pointed.
     *
     * @return array<string, list<string>>
     */
    public static function declaredPathGroups(): array
    {
        return [
            'P2_FILES' => self::P2_FILES,
            'P4_ADAPTERS' => self::P4_ADAPTERS,
            'FROZEN_FILES' => self::FROZEN_FILES,
            'NAMED' => array_keys(self::NAMED),
            'ADDED_BY_THE_ORCHESTRATOR' => array_keys(self::ADDED_BY_THE_ORCHESTRATOR),
        ];
    }

    /**
     * Declared paths no file stands at — the refusal that makes the blindness
     * above impossible to repeat.
     *
     * Pure, and takes its groups as an argument, so a control can plant a
     * group of its own and watch exactly its own path come back.
     *
     * @param array<string, list<string>> $groups
     *
     * @return list<string>
     */
    public static function missingDeclaredPaths(array $groups, string $root): array
    {
        $missing = [];

        foreach ($groups as $constant => $paths) {
            foreach ($paths as $path) {
                if (!is_file($root . $path)) {
                    $missing[] = $constant . ' names ' . $path . ', and no file stands there';
                }
            }
        }

        return $missing;
    }

    /**
     * @param list<string> $arguments
     */
    public static function main(array $arguments): int
    {
        $write = in_array('--write', $arguments, true);

        // Before anything is measured: a hand-typed path that does not exist
        // turns every claim made about it into a claim about a string, and
        // `array_intersect` says nothing about a string no file carries.
        $missing = self::missingDeclaredPaths(self::declaredPathGroups(), self::ROOT);

        if ($missing !== []) {
            foreach ($missing as $line) {
                fwrite(STDERR, 'DECLARED PATH: ' . $line . "\n");
            }

            return 2;
        }

        $set = new self();
        $declared = $set->declaredPairs();
        $sites = $set->subjectSites();
        $frozen = $set->frozenLines();

        $rows = $set->rows($declared, $sites, $frozen);
        $report = $set->report($declared, $sites, $rows);

        echo $report;

        if ($set->breaches() !== []) {
            foreach ($set->breaches() as $breach) {
                fwrite(STDERR, 'INVARIANT: ' . $breach . "\n");
            }

            return 2;
        }

        $rendered = $set->render($rows);
        $path = self::ROOT . self::OUTPUT;

        if ($write) {
            file_put_contents($path, $rendered);
            echo "\nwrote\t", self::OUTPUT, "\n";

            return 0;
        }

        if (!is_file($path)) {
            echo "\nMISSING\t", self::OUTPUT, " — run with --write\n";

            return 1;
        }

        $onDisk = (string) file_get_contents($path);
        if (self::dataRows($onDisk) === self::dataRows($rendered)) {
            echo "\nguard\tp1-file-set.tsv agrees with the tree\n";

            return 0;
        }

        echo "\nGUARD FAILED\tp1-file-set.tsv disagrees with the tree; diff:\n";
        foreach (self::diff(self::dataRows($onDisk), self::dataRows($rendered)) as $line) {
            echo $line, "\n";
        }

        return 1;
    }

    /**
     * Every declared `(producer, key path)` pair of axis A, keyed by the class
     * that declares it.
     *
     * @return array{
     *     pairs: list<array{rule: string, path: string, class: class-string<RuleOptionsInterface|LevelOptionsInterface>, half: string}>,
     *     producers: int,
     *     classes: list<string>
     * }
     */
    private function declaredPairs(): array
    {
        $container = (new ContainerFactory())->create();
        $execution = $container->get(RuleExecutionInterface::class);
        assert($execution instanceof RuleExecutionInterface);

        $pairs = [];
        $classes = [];
        $producers = 0;

        foreach ($execution->allRules() as $metadata) {
            ++$producers;
            $optionsClass = $metadata->optionsClass;
            $classes[$optionsClass] = true;

            foreach ($this->halves($optionsClass) as $half => $keys) {
                foreach ($keys as $key) {
                    $pairs[] = ['rule' => $metadata->name, 'path' => $key, 'class' => $optionsClass, 'half' => $half];
                }
            }

            if (!is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)) {
                continue;
            }

            foreach ($optionsClass::levelOptionsClasses() as $slot => $levelClass) {
                $classes[$levelClass] = true;

                foreach ($this->halves($levelClass) as $half => $keys) {
                    foreach ($keys as $key) {
                        $pairs[] = [
                            'rule' => $metadata->name,
                            'path' => $slot . '.' . $key,
                            'class' => $levelClass,
                            'half' => $half,
                        ];
                    }
                }
            }
        }

        $classNames = array_keys($classes);
        sort($classNames);

        return ['pairs' => $pairs, 'producers' => $producers, 'classes' => $classNames];
    }

    /**
     * Both halves of a class's key set. `acceptedForDisplay()` prints only the
     * accepted one, and axis A counts only that one, so the second is read by
     * reflection rather than left unnamed.
     *
     * @param class-string<RuleOptionsInterface|LevelOptionsInterface> $optionsClass
     *
     * @return array{accepted: list<string>, answered: list<string>}
     */
    private function halves(string $optionsClass): array
    {
        $keySet = $optionsClass::acceptedOptionKeys();
        assert($keySet instanceof RuleOptionKeySet);

        $reflection = new ReflectionClass($keySet);

        /** @var array<string, string> $accepted */
        $accepted = $reflection->getProperty('accepted')->getValue($keySet);
        /** @var array<string, string> $answered */
        $answered = $reflection->getProperty('answeredByTheClass')->getValue($keySet);

        return ['accepted' => array_values($accepted), 'answered' => array_values($answered)];
    }

    /**
     * Subject sites of `form-deciding-sites.tsv`, per file.
     *
     * Subject is not a column: it is what remains once the rows already inside
     * `RuleOptionsFactory` and the rows carrying an exception candidate are
     * taken out. The three counts are returned so the arithmetic can be shown
     * rather than asserted.
     *
     * @return array{
     *     perFile: array<string, int>,
     *     total: int,
     *     factory: int,
     *     exceptions: array<string, int>,
     *     missing: list<string>
     * }
     */
    private function subjectSites(): array
    {
        $lines = file(self::ROOT . self::SITES, FILE_IGNORE_NEW_LINES);
        assert($lines !== false);

        $perFile = [];
        $total = 0;
        $factory = 0;
        $exceptions = [];
        $seenHeader = false;
        $files = [];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$seenHeader) {
                $seenHeader = true;

                continue;
            }

            $columns = explode("\t", $line);
            $file = $columns[0];
            $inFactory = $columns[9] ?? '';
            $candidate = $columns[10] ?? '';
            $files[$file] = true;
            ++$total;

            if ($candidate !== '-') {
                $exceptions[$candidate] = ($exceptions[$candidate] ?? 0) + 1;

                continue;
            }

            if ($inFactory === 'yes') {
                ++$factory;

                continue;
            }

            $perFile[$file] = ($perFile[$file] ?? 0) + 1;
        }

        ksort($perFile);
        arsort($exceptions);

        $missing = [];
        foreach (array_keys($files) as $file) {
            if (!is_file(self::ROOT . $file)) {
                $missing[] = $file;
            }
        }
        sort($missing);

        return [
            'perFile' => $perFile,
            'total' => $total,
            'factory' => $factory,
            'exceptions' => $exceptions,
            'missing' => $missing,
        ];
    }

    /**
     * Line-granular freezes the set must carry with it: the flat branch of the
     * five hierarchical classes, and every promise-bearing docblock range.
     *
     * The ledger side is read WHOLE rather than narrowed to the files the plan
     * names, so that a file entering P1 for any other reason brings its freezes
     * with it instead of losing them silently; `rows()` keeps only what lands
     * in the set, and the report names the residue.
     *
     * `flat_branch_line` is a single line, not a range; it is carried exactly as
     * measured, because widening it here would invent a branch extent nobody
     * measured.
     *
     * @return array<string, list<string>>
     */
    private function frozenLines(): array
    {
        $frozen = [];

        $lines = file(self::ROOT . self::HIERARCHICAL, FILE_IGNORE_NEW_LINES);
        assert($lines !== false);

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (($columns[0] ?? '') !== 'hierarchical') {
                continue;
            }

            $frozen[$columns[2]][] = 'flat_branch_line:' . $columns[5];
        }

        $ranges = file(self::ROOT . self::FROZEN_RANGES, FILE_IGNORE_NEW_LINES);
        assert($ranges !== false);

        foreach ($ranges as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) < 3 || !str_starts_with($columns[0], 'src/')) {
                continue;
            }

            $frozen[$columns[0]][] = 'promise-docblock:' . $columns[1] . '-' . $columns[2];
        }

        foreach ($frozen as &$entries) {
            sort($entries);
        }

        ksort($frozen);

        return $frozen;
    }

    /**
     * One row per file of P1.
     *
     * @param array{pairs: list<array{rule: string, path: string, class: class-string<RuleOptionsInterface|LevelOptionsInterface>, half: string}>, producers: int, classes: list<string>} $declared
     * @param array{perFile: array<string, int>, total: int, factory: int, exceptions: array<string, int>, missing: list<string>} $sites
     * @param array<string, list<string>> $frozen
     *
     * @return list<array<string, string>>
     */
    private function rows(array $declared, array $sites, array $frozen): array
    {
        $byFile = [];

        foreach ($declared['pairs'] as $pair) {
            if ($pair['half'] !== 'accepted') {
                continue;
            }

            $file = $this->fileOf($pair['class']);
            $byFile[$file]['class'] = $this->shortName($pair['class']);
            $byFile[$file]['pairs'] = ($byFile[$file]['pairs'] ?? 0) + 1;
            $byFile[$file]['paths'][$pair['path']] = true;
        }

        $files = array_unique(array_merge(
            array_keys(self::NAMED),
            array_keys($sites['perFile']),
            array_keys($byFile),
        ));
        sort($files);

        $rows = [];
        foreach ($files as $file) {
            $declaredPaths = array_keys($byFile[$file]['paths'] ?? []);
            sort($declaredPaths);

            $reasons = [];
            if (isset(self::NAMED[$file])) {
                $reasons[] = (isset(self::ADDED_BY_THE_ORCHESTRATOR[$file])
                    ? 'added by the orchestrator: '
                    : 'named by 03-cure.md: ') . self::NAMED[$file];
            }
            if ($declaredPaths !== []) {
                $reasons[] = 'declares ' . ($byFile[$file]['pairs'] ?? 0)
                    . ' axis-A pairs over ' . count($declaredPaths) . ' key paths';
            }
            if (isset($sites['perFile'][$file])) {
                $reasons[] = 'carries ' . $sites['perFile'][$file] . ' subject sites of form-deciding-sites.tsv';
            }

            $rows[] = [
                'file' => $file,
                'role' => $this->role($file, $declaredPaths !== [], isset($sites['perFile'][$file])),
                'declared_pairs' => (string) ($byFile[$file]['pairs'] ?? 0),
                'declared_paths' => $declaredPaths === [] ? '-' : implode(',', $declaredPaths),
                'subject_sites' => (string) ($sites['perFile'][$file] ?? 0),
                'frozen_lines' => isset($frozen[$file]) ? implode(',', $frozen[$file]) : '-',
                'why' => implode('; ', $reasons),
            ];
        }

        return $rows;
    }

    private function role(string $file, bool $declares, bool $carries): string
    {
        if (isset(self::NAMED[$file])) {
            return str_contains($file, '/Contract/') ? 'contract' : 'consumer';
        }

        if ($declares && $carries) {
            return 'options-class';
        }

        if ($declares) {
            return 'options-class (no subject site)';
        }

        return 'form-deciding, declares nothing';
    }

    /**
     * @param class-string $class
     */
    private function fileOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        assert($file !== false);

        return str_replace(realpath(self::ROOT) . '/', '', (string) realpath($file));
    }

    /**
     * @param class-string $class
     */
    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * The reconciliation, printed in full: both populations, both projections,
     * and every complement that could hide a file.
     *
     * @param array{pairs: list<array{rule: string, path: string, class: class-string<RuleOptionsInterface|LevelOptionsInterface>, half: string}>, producers: int, classes: list<string>} $declared
     * @param array{perFile: array<string, int>, total: int, factory: int, exceptions: array<string, int>, missing: list<string>} $sites
     * @param list<array<string, string>> $rows
     */
    private function report(array $declared, array $sites, array $rows): string
    {
        $accepted = array_filter($declared['pairs'], static fn(array $p): bool => $p['half'] === 'accepted');
        $answered = array_filter($declared['pairs'], static fn(array $p): bool => $p['half'] === 'answered');
        $top = array_filter($accepted, static fn(array $p): bool => !str_contains($p['path'], '.'));
        $inLevels = array_filter($accepted, static fn(array $p): bool => str_contains($p['path'], '.'));

        $subjectTotal = array_sum($sites['perFile']);
        $exceptionTotal = array_sum($sites['exceptions']);

        $declaringFiles = [];
        foreach ($accepted as $pair) {
            $declaringFiles[$this->fileOf($pair['class'])] = true;
        }
        $declaringFiles = array_keys($declaringFiles);
        sort($declaringFiles);

        $subjectFiles = array_keys($sites['perFile']);
        $named = array_keys(self::NAMED);
        $p1 = array_column($rows, 'file');

        $out = [];
        $out[] = '## populations';
        $out[] = sprintf(
            "sites_total\t%d\t= exceptions %d + in_factory %d + subject %d",
            $sites['total'],
            $exceptionTotal,
            $sites['factory'],
            $subjectTotal,
        );
        $out[] = sprintf(
            "arithmetic\t%s",
            $exceptionTotal + $sites['factory'] + $subjectTotal === $sites['total'] ? 'holds' : 'DOES NOT HOLD',
        );
        foreach ($sites['exceptions'] as $reason => $count) {
            $out[] = sprintf("  exception\t%d\t%s", $count, $reason);
        }
        $out[] = sprintf(
            "declared_pairs\t%d\t= top-level %d + inside levels %d, over %d producers and %d classes",
            count($accepted),
            count($top),
            count($inLevels),
            $declared['producers'],
            count($declared['classes']),
        );
        $out[] = sprintf(
            "answered_by_the_class\t%d\toutside the axis-A denominator: %s",
            count($answered),
            implode(', ', array_map(
                fn(array $p): string => $this->shortName($p['class']) . '::' . $p['path'],
                array_values($answered),
            )),
        );

        $out[] = '';
        $out[] = '## projection onto files — the only comparable unit';
        $out[] = sprintf("D\tdeclaring files\t%d", count($declaringFiles));
        $out[] = sprintf("S\tsubject-site files\t%d", count($subjectFiles));
        $out[] = sprintf("N\tnamed by the plan\t%d", count($named));
        $out[] = sprintf("D∩S\t%d", count(array_intersect($declaringFiles, $subjectFiles)));
        $out[] = sprintf("P1 = N∪S∪D\t%d", count($p1));

        $out[] = '';
        $out[] = '## complements';
        $out[] = $this->complement('D \\ S  (declares, no subject site)', array_diff($declaringFiles, $subjectFiles));
        $out[] = $this->complement(
            'S \\ (D∪N)  (subject site, declares nothing, unnamed)',
            array_diff($subjectFiles, $declaringFiles, $named),
        );
        $out[] = $this->complement('N \\ S  (named, no subject site)', array_diff($named, $subjectFiles));

        $out[] = '';
        $out[] = '## freezes';
        $out[] = $this->mustBeEmpty('frozen whole files inside P1', array_intersect(self::FROZEN_FILES, $p1));
        $missingHierarchical = [];
        foreach (self::HIERARCHICAL_REQUIRED as $class) {
            $found = false;
            foreach ($p1 as $file) {
                if (str_ends_with($file, '/' . $class . '.php')) {
                    $found = true;
                }
            }
            if (!$found) {
                $missingHierarchical[] = $class;
            }
        }
        $out[] = $this->mustBeEmpty('hierarchical classes absent from P1', $missingHierarchical);
        $frozenRows = array_filter($rows, static fn(array $row): bool => $row['frozen_lines'] !== '-');
        $out[] = sprintf("files carrying a line-granular freeze\t%d", count($frozenRows));
        foreach ($frozenRows as $row) {
            $out[] = sprintf("  %s\t%s", $row['file'], $row['frozen_lines']);
        }

        $out[] = '';
        $out[] = '## disjointness with the sibling packages — the proof method of stage 01';
        $out[] = $this->mustBeEmpty('P1 ∩ P2', array_intersect(self::P2_FILES, $p1));
        $out[] = $this->mustBeEmpty('P1 ∩ P4', array_intersect($this->p4Files(), $p1));
        $out[] = $this->mustBeEmpty('config-paths.tsv owners naming no loadable class', $this->unresolvedP4Owners);

        $out[] = '';
        $out[] = $this->mustBeEmpty('paths of form-deciding-sites.tsv absent from the tree', $sites['missing']);

        return implode("\n", $out) . "\n";
    }

    /**
     * The files package P4 owns: the four adapters `03-cure.md` names, plus the
     * class named in the `owner` column of every row of `config-paths.tsv`,
     * resolved to a path through the autoloader rather than guessed. Rows whose
     * owner is a prose fragment rather than a class resolve to nothing and are
     * reported as unresolved instead of silently dropped.
     *
     * @return list<string>
     */
    private function p4Files(): array
    {
        $files = self::P4_ADAPTERS;
        $unresolved = [];

        $lines = file(self::ROOT . self::CONFIG_PATHS, FILE_IGNORE_NEW_LINES);
        assert($lines !== false);

        $seenHeader = false;
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$seenHeader) {
                $seenHeader = true;

                continue;
            }

            $owner = explode("\t", $line)[4] ?? '';
            $names = preg_split('# / #', $owner);
            assert($names !== false);

            foreach ($names as $name) {
                $name = trim($name);
                if ($name === '' || $name === '-') {
                    continue;
                }

                $resolved = $this->resolveOwner($name);
                if ($resolved === null) {
                    $unresolved[$name] = true;

                    continue;
                }

                $files[] = $resolved;
            }
        }

        $this->unresolvedP4Owners = array_keys($unresolved);
        sort($this->unresolvedP4Owners);

        return array_values(array_unique($files));
    }

    /**
     * An `owner` cell is a bare class name, a partly qualified one, or prose.
     * Only a name the autoloader can find becomes a path.
     */
    private function resolveOwner(string $name): ?string
    {
        $name = preg_replace('#\.php:\d+$#', '', $name) ?? $name;

        foreach (['Qualimetrix\\' . ltrim($name, '\\'), $name] as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate)) {
                return $this->fileOf($candidate);
            }
        }

        $short = str_contains($name, '\\') ? substr((string) strrchr($name, '\\'), 1) : $name;
        $matches = [];

        foreach (['src/*/*/*/*/*/', 'src/*/*/*/*/', 'src/*/*/*/'] as $depth) {
            $found = glob(self::ROOT . $depth . $short . '.php');

            if ($found !== false && $found !== []) {
                $matches = $found;

                break;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        return str_replace((string) realpath(self::ROOT) . '/', '', (string) realpath($matches[0]));
    }

    /**
     * @param iterable<string> $values
     */
    /**
     * A complement the run states must be empty — printed exactly as any other
     * one, and remembered, because a "(must be empty)" line that only ever
     * reached the report was a claim nothing could fail on.
     *
     * @var list<string>
     */
    private array $breaches = [];

    /** @param iterable<string> $values */
    private function mustBeEmpty(string $label, iterable $values): string
    {
        $listed = array_values([...$values]);

        if ($listed !== []) {
            $this->breaches[] = $label . ': ' . implode(' ', $listed);
        }

        return $this->complement($label . ' (must be empty)', $listed);
    }

    /** @return list<string> */
    public function breaches(): array
    {
        return $this->breaches;
    }

    /** @param iterable<string> $values */
    private function complement(string $label, iterable $values): string
    {
        $listed = array_values([...$values]);
        sort($listed);

        return sprintf("%s\t%d\t%s", $label, count($listed), $listed === [] ? '-' : implode(' ', $listed));
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function render(array $rows): string
    {
        $header = <<<'HEADER'
            # P1 FILE SET — the named product files of round X18 package P1.
            #
            # HOW OBTAINED: scripts/promise-effect-p1-set.php (this file is its output;
            #   run it without --write to have it re-measure and refuse a stale artefact).
            #   Declared side: the live container's RuleExecutionInterface::allRules(), each
            #   producer's options class asked for its own RuleOptionKeySet, both halves read
            #   by reflection, plus levelOptionsClasses() for the hierarchical ones. Site side:
            #   the rows of form-deciding-sites.tsv that are neither an exception candidate nor
            #   already inside RuleOptionsFactory. Second witness for the class set: Serena
            #   find_implementations over RuleOptionsInterface (38 in src/), LevelOptionsInterface
            #   (10) and HierarchicalRuleOptionsInterface (5) — the container agrees name for name.
            #
            # UNITS: a site is (file, line); a declared pair is (producer rule name, key path)
            #   summed over 54 producers. Neither is the other. They meet only after both are
            #   projected onto FILES, a pair through the class declaring it. That projection is
            #   this table.
            #
            # COLUMNS
            #   file           product file entering P1
            #   role           contract | consumer | options-class | options-class (no subject
            #                  site) | form-deciding, declares nothing
            #   declared_pairs axis-A pairs this file declares (a class serving N rules counts N
            #                  times, which is what makes the two 205s different numbers)
            #   declared_paths distinct key paths declared here, slot-qualified inside levels
            #   subject_sites  subject rows of form-deciding-sites.tsv carried by this file
            #   frozen_lines   line-granular freezes P1 must respect inside this file:
            #                  flat_branch_line:N from hierarchical-options.tsv (a START line,
            #                  not a range — the branch extent is not measured anywhere),
            #                  promise-docblock:A-B from promise-ledger-frozen-ranges.tsv
            #   why            why this row is in the set
            #
            # CONFIRMED BY THE ORCHESTRATOR: ThresholdParser.php and RuleOptionsParser.php stay in
            #   the set. They carry 8 of the 205 subject sites between them and declare no key of
            #   their own, so neither is one of "the options classes" the plan's prose names; they
            #   are the parsing side, and "the declaration is consumed by parsing" is what P1 does.
            #
            # ADDED BY THE ORCHESTRATOR: RuleNamespaceExclusionProvider.php,
            #   RulePathExclusionProvider.php and RuleOptionRefusalWording.php. The first is in no
            #   other package's set and is the throw site behind most malformed framework-key
            #   observations; the second is its neighbour, which judged no form at all; the third is
            #   the existing home of every rule-option refusal's words, so the sentence about a
            #   value's form belongs there rather than beside the judgement. All three carry zero
            #   subject sites, so they enter as named rows and not through the site projection.
            #
            # NOT CARRIED HERE: the inline door freeze of 03-cure.md (bodies of withOverride()
            #   and the inline appliers) is by code unit, not by line, and 27 files of this set
            #   carry such a body. It is named in the plan and deliberately absent as a column.

            HEADER;

        $columns = ['file', 'role', 'declared_pairs', 'declared_paths', 'subject_sites', 'frozen_lines', 'why'];
        $lines = [implode("\t", $columns)];

        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(static fn(string $column): string => $row[$column], $columns));
        }

        return $header . implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private static function dataRows(string $content): array
    {
        return array_values(array_filter(
            explode("\n", $content),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
    }

    /**
     * @param list<string> $onDisk
     * @param list<string> $fresh
     *
     * @return list<string>
     */
    private static function diff(array $onDisk, array $fresh): array
    {
        $lines = [];

        foreach (array_diff($onDisk, $fresh) as $line) {
            $lines[] = '- ' . $line;
        }

        foreach (array_diff($fresh, $onDisk) as $line) {
            $lines[] = '+ ' . $line;
        }

        return $lines;
    }
}

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];

// Runs only when this file IS the command. The control on the declared-path
// refusal has to reach {@see PromiseEffectP1Set::missingDeclaredPaths()} as a
// function, and a file that measured the whole container on include would cost
// the control the run it is supposed to be cheaper than.
if (realpath((string) ($argv[0] ?? '')) === __FILE__) {
    exit(PromiseEffectP1Set::main(array_slice($argv, 1)));
}
