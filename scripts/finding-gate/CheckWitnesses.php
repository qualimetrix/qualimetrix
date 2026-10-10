<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * Each of the gate's checks, seen raising its failure class in a whole run, and
 * each of its modes, seen deciding what it writes.
 *
 * A check whose body is removed only removes failures, so neither a green
 * control nor a red one whose required class comes from elsewhere notices.
 * What notices is a run built to trip exactly that check: a {@see SyntheticTree}
 * carrying one planted defect per witness, driven through
 * {@see GateModes::run()} — the door the command line uses — and judged by the
 * {@see GateReport} it filled. The public entry point is the point: these
 * witnesses must hold unchanged while the checks move between files.
 *
 * Each witness names the failures its plant must raise (`expect`: class, scope
 * glob, including the side where applicable)
 * and the side effects it may raise (`tolerate`: class and scope glob). A
 * run is held to both directions: an expected failure missing, a failure
 * nothing names, a toleration nothing used and an unknown failure class are each red. A scenario that runs
 * a writing mode is also held to its exit code and to exactly the declarations
 * it changed. The clean tree underneath all of them must be GREEN, or every
 * observation below could be the stand's own defect.
 *
 * @phpstan-import-type Specification from SyntheticTree
 *
 * @phpstan-type Expected array{0: string, 1: string}
 * @phpstan-type Tolerated array{0: string, 1: string}
 * @phpstan-type Witness array{
 *     id: string,
 *     scenario: string,
 *     plant: callable(Specification): Specification,
 *     expect: list<Expected>,
 *     tolerate: list<Tolerated>,
 *     prepare?: callable(string):void,
 * }
 * @phpstan-type Failure array{class: string, scope: string, detail: string}
 * @phpstan-type Run array{failures: list<Failure>, exit: int, changed: list<string>}
 */
final class CheckWitnesses
{
    /** `env-mismatch` returns before the reference is run, so what it stops cannot share its run. */
    public const string BEFORE_THE_REFERENCE = 'pre-reference';

    public const string WHOLE_RUN = 'whole';

    /**
     * `map-stale` is judged only on a run that raised no `run-failed` and no
     * `reference-input-untranslated`, so the declarations the run is held to
     * cannot share a run with the plants that raise either.
     */
    public const string DECLARATIONS = 'declarations';

    private const string NORMALIZATION_REFUSED = 'derive-normalization refused';

    private const string NORMALIZATION_WRITTEN = 'derive-normalization written';

    private const string DECLARED_DELTA_REFUSED = 'derive-declarations refused';

    private const string DECLARED_DELTA_WRITTEN = 'derive-declarations written';

    private const string TUPLE_WRITTEN = 'derive-tuple written';

    /**
     * Scenario => the mode's flag, the exit code it must end with, and the
     * declarations under `finding-gate/` it must change (globs), or null for a
     * comparison, which is judged by its failures alone.
     *
     * @var array<string, array{flags: list<string>, exit: int, writes: list<string>}|null>
     */
    private const array MODES = [
        self::BEFORE_THE_REFERENCE => null,
        self::WHOLE_RUN => null,
        self::DECLARATIONS => null,
        self::NORMALIZATION_REFUSED => [
            'flags' => ['--derive-normalization'],
            'exit' => GateModes::MEASUREMENT_FAILED,
            'writes' => [],
        ],
        self::NORMALIZATION_WRITTEN => [
            'flags' => ['--derive-normalization'],
            'exit' => GateModes::WROTE,
            'writes' => ['finding-gate/normalization.tsv'],
        ],
        self::DECLARED_DELTA_REFUSED => [
            'flags' => ['--derive-declarations'],
            'exit' => GateModes::MEASUREMENT_FAILED,
            'writes' => [],
        ],
        self::DECLARED_DELTA_WRITTEN => [
            'flags' => ['--derive-declarations'],
            'exit' => GateModes::WROTE,
            'writes' => ['finding-gate/declared-delta.tsv', 'finding-gate/declared-delta/*'],
        ],
        self::TUPLE_WRITTEN => [
            'flags' => ['--derive-tuple'],
            'exit' => GateModes::WROTE,
            'writes' => [EquivalenceTuple::TRACKED_PATH],
        ],
    ];

    /**
     * Modes no scenario drives: the PHPUnit test that runs the mode as a
     * subprocess (file under this directory, method), or null and why the
     * scenarios reach it anyway.
     *
     * @var array<string, array{0: string|null, 1: string|null, 2: string}>
     */
    private const array WITNESSED_OUTSIDE_A_SCENARIO = [
        Options::MODE_SELF_TEST => [
            'tests/GateModesTest.php',
            'itExitsOneWhenTheSelfTestFails',
            'a self-test cannot see its own exit code, so a copy of the gate with a failing self-test runs as a subprocess',
        ],
        Options::MODE_CASE_WORKER => [
            null,
            null,
            'every scenario that runs a tree runs each of its cases in a worker of this mode',
        ],
    ];

    private const string NO_DIFF = "a diff nobody measured\n";

    /**
     * `observed` holds classes matched with their required side-specific scopes.
     *
     * @return array{failures: list<string>, observed: list<string>}
     */
    public static function observe(): array
    {
        $failures = self::unwitnessedModes();
        $observed = [];

        $clean = self::run(SyntheticTree::clean(), null);

        if (\is_string($clean) || $clean['failures'] !== [] || $clean['exit'] !== GateReport::EXIT_GREEN) {
            $failures[] = \sprintf(
                'check witness clean-tree: the unplanted synthetic tree is not GREEN, so no witness below can be told'
                . ' from a defect of the stand: %s',
                \is_string($clean) ? $clean : self::describe($clean['failures']) . ', exit ' . $clean['exit'],
            );
        }

        foreach (self::witnesses() as $witness) {
            $scenario = $witness['scenario'];
            $specification = ($witness['plant'])(SyntheticTree::clean());
            $mode = self::MODES[$scenario];
            $run = self::run($specification, $mode['flags'] ?? null, $witness['prepare'] ?? null);

            if (\is_string($run)) {
                $failures[] = \sprintf('check witness %s: the %s run did not complete: %s', $witness['id'], $scenario, $run);

                continue;
            }

            $judged = self::judge($scenario, [$witness], $run['failures']);
            $failures = [...$failures, ...$judged['failures'], ...self::unknownFailures($run['failures'])];
            $observed = [...$observed, ...$judged['observed']];

            if ($mode !== null) {
                $failures = [...$failures, ...self::judgeMode($scenario, $mode, $run)];
            }
        }

        return [
            'failures' => $failures,
            'observed' => array_values(array_unique($observed)),
        ];
    }

    /**
     * Every mode the command line has is driven by a scenario, or says why it
     * need not be.
     *
     * @return list<string>
     */
    private static function unwitnessedModes(): array
    {
        $driven = [Options::MODE_COMPARE];

        foreach (self::MODES as $mode) {
            foreach ($mode['flags'] ?? [] as $flag) {
                $driven[] = substr($flag, 2);
            }
        }

        $problems = [];
        $modes = [];

        foreach ((new ReflectionClass(Options::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'MODE_') && \is_string($value)) {
                $modes[] = $value;
            }
        }

        foreach ($modes as $mode) {
            if (!\in_array($mode, $driven, true) && !isset(self::WITNESSED_OUTSIDE_A_SCENARIO[$mode])) {
                $problems[] = \sprintf(
                    'check witness modes: the gate mode "%s" is driven by no scenario, so what it decides and writes is'
                    . ' seen by nothing.',
                    $mode,
                );
            }
        }

        foreach (self::WITNESSED_OUTSIDE_A_SCENARIO as $mode => [$file, $method, $reason]) {
            if (!\in_array($mode, $modes, true)) {
                $problems[] = \sprintf('check witness modes: "%s" is listed as witnessed outside a scenario and is no gate mode.', $mode);

                continue;
            }

            if (\in_array($mode, $driven, true)) {
                $problems[] = \sprintf(
                    'check witness modes: "%s" is listed as witnessed outside a scenario and a scenario drives it.'
                    . ' Remove the entry.',
                    $mode,
                );
            }

            if ($file === null) {
                continue;
            }

            $path = __DIR__ . '/' . $file;

            if (!is_file($path) || preg_match('~function ' . preg_quote((string) $method, '~') . '\\(~', Fs::read($path)) !== 1) {
                $problems[] = \sprintf(
                    'check witness modes: "%s" is witnessed by %s::%s, which does not exist (%s).',
                    $mode,
                    $file,
                    $method,
                    $reason,
                );
            }
        }

        return $problems;
    }

    /**
     * @param array{flags: list<string>, exit: int, writes: list<string>} $mode
     * @param Run $run
     *
     * @return list<string>
     */
    private static function judgeMode(string $scenario, array $mode, array $run): array
    {
        $problems = [];

        if ($run['exit'] !== $mode['exit']) {
            $problems[] = \sprintf('check witness %s run: exited %d, and the mode decides %d here.', $scenario, $run['exit'], $mode['exit']);
        }

        foreach ($mode['writes'] as $pattern) {
            if (array_filter($run['changed'], static fn(string $path): bool => fnmatch($pattern, $path)) === []) {
                $problems[] = \sprintf('check witness %s run: wrote nothing matching %s.', $scenario, $pattern);
            }
        }

        foreach ($run['changed'] as $path) {
            if (array_filter($mode['writes'], static fn(string $pattern): bool => fnmatch($pattern, $path)) === []) {
                $problems[] = \sprintf(
                    'check witness %s run: changed %s, which this mode must leave alone here.',
                    $scenario,
                    $path,
                );
            }
        }

        return $problems;
    }

    /**
     * The witnesses of the gate's own checks, then each declaration form's,
     * from its wiring file.
     *
     * @return list<Witness>
     */
    private static function witnesses(): array
    {
        return [...self::ownWitnesses(), ...self::registered(Wiring::gate())];
    }

    /**
     * The witnesses the forms register, each driven by a scenario this class
     * runs — an unknown one would silently become a plain comparison.
     *
     * @return list<Witness>
     */
    public static function registered(Wiring $wiring): array
    {
        $witnesses = [];

        foreach ($wiring->factories('witnesses', __NAMESPACE__) as $factory) {
            /** @var list<Witness> $registered */
            $registered = $factory();

            foreach ($registered as $witness) {
                if (!\array_key_exists($witness['scenario'], self::MODES)) {
                    throw new GateError(\sprintf(
                        'Check witness %s names the scenario "%s", which is no scenario of CheckWitnesses::MODES.',
                        $witness['id'],
                        $witness['scenario'],
                    ));
                }
            }

            $witnesses = [...$witnesses, ...$registered];
        }

        return $witnesses;
    }

    /** @return list<Witness> */
    private static function ownWitnesses(): array
    {
        return [
            self::repeatedCaptureWitness(self::WHOLE_RUN),
            self::repeatedCaptureWitness(self::NORMALIZATION_REFUSED),
            self::witness(
                'case-capture-refusal',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => '{"violationsMeta":{"total":-1,"truncated":false}}'];
                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'candidate-2 / alpha']],
                [
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:json'],
                    [FailureClass::CANDIDATE_INPUT_REFUSED, 'case:alpha'],
                    [FailureClass::COVERAGE_SHORTFALL, 'corpus'],
                ],
            ),
            self::witness(
                'declaration-capture-refusal',
                self::DECLARED_DELTA_REFUSED,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => '{"violationsMeta":{"total":-1,"truncated":false}}'];
                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'candidate-2 / alpha']],
                [
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:json'],
                    [FailureClass::CANDIDATE_INPUT_REFUSED, 'case:alpha'],
                    [FailureClass::COVERAGE_SHORTFALL, 'corpus'],
                ],
            ),
            self::witness(
                'normalization-capture-refusal',
                self::NORMALIZATION_REFUSED,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => '{"violationsMeta":{"total":-1,"truncated":false}}'];
                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'derive-1 / alpha']],
            ),
            self::witness(
                'normalization-complete-shape',
                self::NORMALIZATION_REFUSED,
                static fn(array $tree): array => SelfTestRecords::rankingWitnessTree($tree, 'shape'),
                [
                    [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-shape|format:json'],
                    [FailureClass::RUN_FAILED, 'derive-1 / ranking-shape'],
                ],
            ),
            self::witness(
                'declaration-complete-shape',
                self::DECLARED_DELTA_REFUSED,
                static fn(array $tree): array => SelfTestRecords::rankingWitnessTree($tree, 'shape'),
                [
                    [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-shape|format:json'],
                    [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-shape|format:json'],
                    [FailureClass::RUN_FAILED, 'candidate-2 / ranking-shape'],
                ],
                [[FailureClass::SURFACE_MISMATCH, 'case:ranking-shape|format:json'], [FailureClass::SURFACE_MISMATCH, 'case:ranking-shape|check:output:file']],
            ),
            self::witness(
                'normalization-duplicate-ambiguity',
                self::NORMALIZATION_REFUSED,
                static fn(array $tree): array => SelfTestRecords::rankingWitnessTree($tree, 'ambiguity'),
                [
                    [FailureClass::RECORD_AMBIGUOUS, 'candidate / case:ranking-ambiguity|format:json'],
                    [FailureClass::RUN_FAILED, 'derive-1 / ranking-ambiguity'],
                ],
            ),

            self::witness(
                'derive-normalization-outcome-mismatch',
                self::NORMALIZATION_REFUSED,
                static function (array $tree): array {
                    $tree['declarations']['cases/alpha/case.json'] = self::json([
                        'id' => 'alpha', 'description' => 'Declared incomplete analysis must actually be incomplete.', 'paths' => ['src'],
                        'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'],
                        'outcome' => ['kind' => CaseOutcome::INCOMPLETE, 'exit' => 4],
                    ]);

                    return $tree;
                },
                [
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'derive-1 / alpha'],
                ],
            ),
            self::witness(
                'env-mismatch',
                self::BEFORE_THE_REFERENCE,
                static function (array $tree): array {
                    $tree['candidateLock'] = "{\"replay\": \"another lock\"}\n";

                    return $tree;
                },
                [[FailureClass::ENV_MISMATCH, 'reference tree']],
            ),
            self::witness(
                'tuple-drift',
                self::BEFORE_THE_REFERENCE,
                static function (array $tree): array {
                    $tree['tuple'] = array_values(array_diff($tree['tuple'], ['message']));

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, 'candidate-2 / alpha'],
                    [FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH],
                    [FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / alpha / finding #0'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json'],
                    [FailureClass::FINDING_TUPLE_MISMATCH, 'reference / alpha / finding #0'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|format:json'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|check:output:file'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json'],
                ],
            ),
            self::witness(
                'tuple-fingerprint-licence',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['tuple'] = array_values(array_diff($tree['tuple'], ['edge']));
                    $tree['published'] = array_values(array_diff($tree['published'], ['edge']));

                    foreach ($tree['findings'] as $case => $findings) {
                        foreach ($findings as $index => $finding) {
                            unset($tree['findings'][$case][$index]['edge']);
                        }
                    }

                    return $tree;
                },
                [
                    [FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|baseline-file'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|format:json'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|baseline-file'],
                ],
            ),
            self::witness(
                'determinism.differs',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $tree['candidateAnswers']['case:alpha|format:text'] = $answers['case:alpha|format:text'];
                    $tree['candidateAnswers']['case:alpha|format:text']['stdout'] = ($tree['candidateAnswers']['case:alpha|format:text']['stdout'] ?? throw new GateError('A renderer-backed witness requires stdout.')) . 'replayed text ' . SyntheticTree::RANDOM . "\n";

                    return $tree;
                },
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|format:text']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:text']],
            ),
            self::witness(
                'determinism.one-run-only',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, []);
                    $tree['candidateAnswers']['case:alpha|format:summary'] = [
                        ...$answers['case:alpha|format:summary'],
                        'stderr' => "replayed warning\n",
                        'stderrOnce' => true,
                    ];

                    return $tree;
                },
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|stderr:format:summary']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|stderr:format:summary']],
            ),
            self::witness(
                'normalization-scope',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:metrics', 'summary.channel', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [[FailureClass::NORMALIZATION_OVERREACH, 'format:metrics / summary.channel']],
                [[FailureClass::NORMALIZATION_STALE, 'format:metrics / summary.channel']],
            ),
            self::witness(
                'normalization-leaves-findings',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:json', 'violations', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [
                    [FailureClass::NORMALIZATION_OVERREACH, 'candidate / case:alpha|format:json'],
                    [FailureClass::NORMALIZATION_OVERREACH, 'reference / case:alpha|format:json'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json'],
                    [FailureClass::NORMALIZATION_OVERREACH, 'candidate / case:alpha|format:json'],
                    [FailureClass::NORMALIZATION_OVERREACH, 'reference / case:alpha|format:json'],
                ],
            ),
            self::witness(
                'stale-normalization',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:json', 'never.present', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [[FailureClass::NORMALIZATION_STALE, 'format:json / never.present']],
            ),
            self::witness(
                'path-leak',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, []);
                    $tree['answers']['case:alpha|format:github'] = $answers['case:alpha|format:github'];
                    $tree['answers']['case:alpha|format:github']['stdout'] = ($tree['answers']['case:alpha|format:github']['stdout'] ?? throw new GateError('A renderer-backed witness requires stdout.')) . 'replayed github from ' . SyntheticTree::TREE . "\n";

                    return $tree;
                },
                [[FailureClass::PATH_LEAK, 'reference / case:alpha|format:github']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:github']],
            ),
            self::witness(
                'single-producer',
                self::WHOLE_RUN,
                static fn(array $tree): array => self::withCase($tree, 'beta', 'replay.alpha', declared: true),
                [[FailureClass::COVERAGE_MULTIPLICITY, 'corpus']],
            ),
            self::witness(
                'finding-key-set',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'gamma', 'replay.gamma', declared: true);
                    $tree['findings']['gamma'][0]['unpublished'] = 'a key the tuple does not name';

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, 'candidate-2 / gamma'],
                    [FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / gamma / finding #0'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:json'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:gamma|format:json'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|check:output:file'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:json'],
                ],
                [[FailureClass::FINDING_TUPLE_MISMATCH, 'reference / gamma / finding #0']],
            ),
            self::witness(
                'coverage-surplus',
                self::WHOLE_RUN,
                static fn(array $tree): array => self::withCase($tree, 'delta', 'replay.undeclared', declared: false),
                [[FailureClass::COVERAGE_SURPLUS, 'corpus']],
            ),
            self::witness(
                'witness-disagreement',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['fixture']['replay.fixture-only'] = ['class'];

                    return $tree;
                },
                [[FailureClass::WITNESS_DISAGREEMENT, 'governance/Channel/Fixtures/declared.txt']],
            ),
            self::witness(
                'level-vocabulary-drift',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['levels'][] = 'replayed-level';

                    return $tree;
                },
                [[FailureClass::LEVEL_VOCABULARY_DRIFT, 'scripts/finding-gate/SubjectLevel.php']],
            ),
            self::witness(
                'malformed-whole-invocations',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:html'] = ['stdout' => "<html>no payload</html>\n"];
                    $tree['candidateAnswers']['case:alpha|format:sarif'] = ['stdout' => "not json\n"];

                    return $tree;
                },
                [
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:html'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:sarif'],
                ],
            ),
            self::witness(
                'no-findings-section',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'omega', 'replay.omega', declared: true);
                    $tree['answers']['case:omega|format:json'] = ['stdout' => '{"violationsMeta":{"total":0,"truncated":false,"byRule":{}}}'];

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, 'candidate-2 / omega'],
                    [FailureClass::CANDIDATE_INPUT_REFUSED, 'case:omega'],
                ],
                [
                    [FailureClass::COVERAGE_SHORTFALL, 'corpus'],
                ],
            ),
            self::witness(
                'truncated-findings',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['truncated'][] = 'alpha';
                    $answer = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], true, [])['case:alpha|format:json'];
                    $answer['physical']['stdout'] = '{"violations":[]}';
                    $tree['answers']['case:alpha|format:json'] = $answer;

                    return $tree;
                },
                [
                    [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json'],
                    [FailureClass::RUN_FAILED, 'candidate-2 / alpha'],
                    [FailureClass::RUN_FAILED, '* / alpha'],
                    [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json'],
                    [FailureClass::RANKING_PROJECTION_MISMATCH, 'reference / case:alpha|format:json'],
                ],
                [
                    [FailureClass::RECORD_PROJECTION_MISMATCH, '* / case:alpha|baseline-file'],
                    [FailureClass::PUBLISHED_ORDER_DRIFT, 'case:alpha|format:json'],
                    [FailureClass::CASE_CLAIM_MISMATCH, 'case:alpha'],
                    [FailureClass::COVERAGE_SHORTFALL, 'corpus'],
                ],
            ),
            self::witness(
                'baseline-exit',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => "{\"replayed\": \"alpha\"}\n", 'exit' => 1];

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, '* / alpha / baseline:generate'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|baseline-file'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|baseline-file'],
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'candidate / alpha / baseline:generate'],
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'reference / alpha / baseline:generate'],
                ],
            ),
            self::witness(
                'baseline-empty',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'eta', 'replay.eta', declared: true);
                    $tree['answers']['case:eta|baseline-file'] = ['stdout' => ''];

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, '* / eta / baseline-file'],
                ],
            ),
            self::witness(
                'reference-input',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, []);
                    $tree['answers']['case:alpha|format:text-detail'] = [...$answers['case:alpha|format:text-detail'], 'exit' => 3];
                    $tree['candidateAnswers']['case:alpha|format:text-detail'] = $answers['case:alpha|format:text-detail'];

                    return $tree;
                },
                [
                    [FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'reference / case:alpha'],
                ],
            ),
            self::witness(
                'map-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['maps']['symbols'][] = "Replay\\Nowhere\tReplay\\Elsewhere\tself-test";

                    return $tree;
                },
                [[FailureClass::MAP_STALE, '*Replay\Nowhere*']],
            ),
            self::witness(
                'split-unmapped',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['maps']['channels'][] = "replay.alpha#replay.never-one\treplay.split-one#replay.split-one\tself-test";
                    $tree['maps']['channels'][] = "replay.alpha#replay.never-two\treplay.split-two#replay.split-two\tself-test";

                    return $tree;
                },
                [
                    [FailureClass::SPLIT_UNMAPPED, 'case:alpha'],
                    [FailureClass::SPLIT_UNMAPPED, 'case:alpha|format:json'],
                ],
                [[FailureClass::MAP_STALE, '*replay.never-*']],
            ),
            self::witness(
                'case-claim',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['cases']['alpha'][] = SubjectLevel::claim('replay.alpha', 'class');

                    return $tree;
                },
                [[FailureClass::CASE_CLAIM_MISMATCH, 'case:alpha']],
            ),
            self::witness(
                'coverage-shortfall',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['static']['replay.unfired'] = ['callable'];
                    $tree['fixture']['replay.unfired'] = ['callable'];

                    return $tree;
                },
                [[FailureClass::COVERAGE_SHORTFALL, 'corpus']],
            ),
            self::witness(
                'published-order',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $subject = $tree['findings']['alpha'][0]['subject'];
                    $tree['cases']['alpha'][] = SubjectLevel::claim('replay.zulu', 'callable');
                    $tree['findings']['alpha'][] = SyntheticTree::finding($tree['tuple'], 'replay.zulu', $subject);
                    $tree['static']['replay.zulu'] = ['callable'];
                    $tree['fixture']['replay.zulu'] = ['callable'];
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, []);
                    $document = json_decode($answers['case:alpha|baseline-file']['stdout'] ?? throw new GateError('A baseline witness requires stdout.'), true, 512, \JSON_THROW_ON_ERROR);
                    $document['entries'][$subject] = array_reverse($document['entries'][$subject]);
                    $payload = self::json($document);
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => $payload, 'file' => $payload];

                    return $tree;
                },
                [[FailureClass::PUBLISHED_ORDER_DRIFT, 'case:alpha|baseline-file']],
            ),
            self::witness(
                'surface-one-side',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $tree['candidateAnswers']['case:alpha|format:github'] = [...$answers['case:alpha|format:github'], 'stderr' => "replayed warning\n"];

                    return $tree;
                },
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|stderr:format:github']],
            ),
            self::witness(
                'surface-differs',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $tree['candidateAnswers']['case:alpha|format:checkstyle'] = $answers['case:alpha|format:checkstyle'];
                    $tree['candidateAnswers']['case:alpha|format:checkstyle']['stdout'] = ($tree['candidateAnswers']['case:alpha|format:checkstyle']['stdout'] ?? throw new GateError('A renderer-backed witness requires stdout.')) . "\n<!-- replayed checkstyle difference -->\n";

                    return $tree;
                },
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:checkstyle']],
            ),
            self::witness(
                'finding-count',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'zeta', 'replay.zeta', declared: true);
                    $tree['candidateFindings']['zeta'] = [
                        ...$tree['findings']['zeta'],
                        SyntheticTree::finding($tree['tuple'], 'replay.zeta', 'declaration:callable:Replay\Zeta::walk@src/Zeta.php'),
                    ];

                    return $tree;
                },
                [
                    [FailureClass::FINDING_COUNT_MISMATCH, 'case:zeta'],
                    [FailureClass::RECORD_UNDECLARED, 'case:zeta|format:json'],
                    [FailureClass::SURFACE_MISMATCH, 'case:zeta|baseline-file'],
                    [FailureClass::SURFACE_MISMATCH, 'case:zeta|check:output:file'],
                    [FailureClass::SURFACE_MISMATCH, 'case:zeta|show-suppressed'],
                ],
                [[FailureClass::SURFACE_MISMATCH, 'case:zeta|format:*']],
            ),
            self::witness(
                'delta-mismatch',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $key = 'case:alpha|format:metrics';
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $baseline = $answers[$key]['stdout'] ?? throw new GateError('A metrics witness requires stdout.');
                    $tree['candidateAnswers'][$key] = ['stdout' => rtrim($baseline) . " \n"];
                    $tree['declaredDelta'][$key] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|format:metrics']],
            ),
            self::witness(
                'exact-surface-mismatch',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|rules'] = ['stdout' => "Changed rule listing\n"];
                    $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
                        ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'The complete rule listing changes.'],
                    ]);
                    $tree['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = self::NO_DIFF;
                    return $tree;
                },
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|rules']],
            ),
            self::witness(
                'exact-surface-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['candidateDeclarations'][DeclaredExactSurfaces::INDEX] = Tsv::render(DeclaredExactSurfaces::COLUMNS, [
                        ['alpha', 'rules', 'declared-exact-surfaces/rules.diff', 'The complete rule listing changes.'],
                    ]);
                    $tree['candidateDeclarations']['declared-exact-surfaces/rules.diff'] = self::NO_DIFF;
                    return $tree;
                },
                [[FailureClass::DELTA_STALE, 'case:alpha|rules']],
            ),
            self::witness(
                'delta-too-large',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $key = 'case:alpha|format:metrics';
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $baseline = $answers[$key]['stdout'] ?? throw new GateError('A metrics witness requires stdout.');
                    $tree['candidateAnswers'][$key] = ['stdout' => rtrim($baseline) . "\n" . str_repeat(" \n", DeclaredDelta::MAX_CHANGED_LINES + 2)];
                    $tree['declaredDelta'][$key] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_TOO_LARGE, 'case:alpha|format:metrics']],
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|format:metrics']],
            ),
            self::witness(
                'delta-overreach',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $moved = $tree['findings']['alpha'];
                    $moved[0]['message'] = 'moved';
                    $tree['candidateFindings']['alpha'] = $moved;
                    $tree['declaredDelta']['case:alpha|format:json'] = self::NO_DIFF;

                    return $tree;
                },
                [
                    [FailureClass::DELTA_OVERREACH, 'case:alpha|format:json'],
                    [FailureClass::VALUE_MISMATCH, 'case:alpha|format:json|record:{"channel":"replay.alpha","subject":"declaration:callable:Replay\\\\Alpha::run@src/Alpha.php","occurrence":null,"edge":null}'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|check:output:file'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:checkstyle'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:github'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:gitlab'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:html'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:sarif'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:summary'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:text'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:text-detail'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|show-suppressed'],
                ],
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|format:json']],
            ),
            self::witness(
                'delta-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['declaredDelta']['case:alpha|format:health'] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_STALE, 'case:alpha|format:health']],
            ),
            self::witness(
                'delta-class-requires-each-case-to-move',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['declaredDelta']['format:health'] = self::NO_DIFF;

                    return $tree;
                },
                [
                    [FailureClass::DELTA_STALE, 'case:alpha|format:health'],
                    [FailureClass::DELTA_STALE, 'format:health'],
                ],
            ),
            self::witness(
                'field-move-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['fieldMoves'][] = ['case:alpha|format:json', 'message', 'nowhere', 'elsewhere'];

                    return $tree;
                },
                [[FailureClass::FIELD_MOVE_STALE, 'case:alpha|format:json']],
            ),
            self::witness(
                'stale-record-declarations',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $record = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\Alpha::run@src/Alpha.php');
                    $record['message'] = 'a record no side publishes';
                    $tree['declarations'][DeclaredRecords::INDEX] = Tsv::render(DeclaredRecords::COLUMNS, [
                        [DeclaredRecords::INTRODUCED, 'alpha', 'json', 'format:json', DeclaredRecords::canonical(['message' => $record['message']]), 'self-test'],
                    ]);
                    $tree['declarations'][DeclaredRecords::DERIVED] = Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [
                        [DeclaredRecords::INTRODUCED, 'alpha', 'json', 'format:json', DeclaredRecords::canonical($record)],
                    ]);

                    return $tree;
                },
                [
                    [FailureClass::RECORD_STALE, 'case:alpha|format:json'],
                    [FailureClass::RECORD_STALE, DeclaredRecords::INDEX],
                ],
            ),
            self::witness(
                'stale-value-declaration',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [
                        [DeclaredValues::METRIC, 'ccn', DeclaredValues::EVERY_LEVEL, 'self-test'],
                    ]);

                    return $tree;
                },
                [[FailureClass::VALUE_STALE, DeclaredValues::INDEX]],
            ),

            self::witness(
                'stale-structural-map',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['declarations'][DeclaredStructuralMaps::INDEX] = Tsv::render(DeclaredStructuralMaps::COLUMNS, [
                        ['config', 'old.key', 'new.key', DeclaredStructuralMaps::SHAPE_SAME, 'self-test'],
                    ]);

                    return $tree;
                },
                [[FailureClass::STRUCTURAL_MAP_STALE, DeclaredStructuralMaps::INDEX]],
            ),
            self::witness(
                'field-declaration-stale-empty-variant',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
                    $emptyAnswers = SyntheticTree::caseAnswers('alpha', [], false, []);
                    $emptySource = $emptyAnswers['case:alpha|format:json'];
                    $emptyBaseline = "{\"version\":13,\"scope\":[\"src\"],\"entries\":{}}\n";
                    $tree['answers']['case:alpha|check:baseline-source'] = $emptySource;
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => $emptyBaseline, 'file' => $emptyBaseline];
                    $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, [
                        [DeclaredFields::REMOVED, 'json', 'check:baseline-source', 'retired', 'an unused field license on the observed empty variant'],
                    ]);

                    return $tree;
                },
                [[FailureClass::FIELD_DECLARATION_STALE, DeclaredFields::INDEX]],
            ),
            self::witness(
                'derive-normalization-refuses-a-dead-pass',
                self::NORMALIZATION_REFUSED,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'eta', 'replay.eta', declared: true);
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => "{\"replayed\": \"alpha\"}\n", 'exit' => 1];
                    $tree['answers']['case:eta|baseline-file'] = ['stdout' => ''];
                    // A row no pass fires: a list measured anyway would drop it, so a write cannot hide.
                    $tree['normalization'][] = ['format:json', 'never.present', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [
                    [
                        FailureClass::RUN_FAILED,
                        'derive-* / alpha / baseline:generate',
                    ],
                    [
                        FailureClass::RUN_FAILED,
                        'derive-* / eta / baseline-file',
                    ],
                ],
            ),
            self::witness(
                'derive-normalization-writes-a-measured-list',
                self::NORMALIZATION_WRITTEN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:json', 'never.present', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [],
            ),
            self::witness(
                'derive-declarations-refuses-a-failed-comparison',
                self::DECLARED_DELTA_REFUSED,
                static function (array $tree): array {
                    $tree['candidateLock'] = "{\"replay\": \"another lock\"}\n";

                    return $tree;
                },
                [[FailureClass::ENV_MISMATCH, 'reference tree']],
            ),
            self::witness(
                'derive-declarations-writes-a-measured-diff',
                self::DECLARED_DELTA_WRITTEN,
                static function (array $tree): array {
                    $key = 'case:alpha|format:metrics';
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $baseline = $answers[$key]['stdout'] ?? throw new GateError('A metrics witness requires stdout.');
                    $tree['candidateAnswers'][$key] = ['stdout' => rtrim($baseline) . " \n"];
                    $tree['declaredDelta'][$key] = self::NO_DIFF;

                    return $tree;
                },
                [],
            ),
            self::witness(
                'derive-declarations-refuses-conflicting-class-diffs',
                self::DECLARED_DELTA_REFUSED,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'beta', 'replay.beta', declared: true);
                    foreach (['alpha', 'beta'] as $case) {
                        $key = 'case:' . $case . '|format:metrics';
                        $answers = SyntheticTree::caseAnswers($case, $tree['findings'][$case], false, []);
                        $baseline = $answers[$key]['stdout'] ?? throw new GateError('A metrics witness requires stdout.');
                        $tree['candidateAnswers'][$key] = ['stdout' => rtrim($baseline) . ($case === 'alpha' ? " \n" : "  \n")];
                    }
                    $tree['declaredDelta']['format:metrics'] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_MISMATCH, 'case:beta|format:metrics']],
            ),
            self::witness(
                'derive-tuple-writes-the-published-fields',
                self::TUPLE_WRITTEN,
                static function (array $tree): array {
                    $tree['tuple'] = array_values(array_diff($tree['tuple'], ['message']));

                    return $tree;
                },
                [],
            ),
        ];
    }

    /** @return Witness */
    private static function repeatedCaptureWitness(string $scenario): array
    {
        return self::witness(
            'repeated-full-values-' . $scenario,
            $scenario,
            static function (array $tree): array {
                $tree['declarations']['cases/alpha/case.json'] = self::json([
                    'id' => 'alpha', 'description' => 'Hidden values must repeat.', 'paths' => ['src'],
                    'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'], 'args' => ['--top=0'],
                ]);
                return $tree;
            },
            [
                [FailureClass::NONDETERMINISM_UNDECLARED, 'baseline eligibility'],
                [FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|format:json'],
            ],
            [],
            static function (string $root): void {
                $file = $root . '/bin/qmx';
                $source = Fs::read($file);
                $anchor = '$stderr = (string) ($answer[\'stderr\'] ?? \'\');';
                $fault = $anchor . "\n" . <<<'PHP'
                    if ($capture === 'ranked') {
                        $marker = $tree . '/replay/repeated-values-' . md5($key);
                        $seen = is_file($marker) ? (int) file_get_contents($marker) : 0;
                        file_put_contents($marker, (string) ($seen + 1));
                        if ($seen === 1) {
                            $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                            $payload['topIssues'][0]['impactScore'] += 1;
                            $stdout = json_encode($payload, JSON_THROW_ON_ERROR);
                        }
                    }
                    PHP;
                if (substr_count($source, $anchor) !== 1) {
                    throw new GateError('The repeated-value witness has no unique private capture site.');
                }
                Fs::write($file, str_replace($anchor, $fault, $source));

                $probe = $root . '/vendor/autoload.php';
                $source = Fs::read($probe);
                $anchor = "return (object) ['baseline' => (object) ['entries' => \$entries], 'uncaptured' => []];";
                $marker = var_export($root . '/replay/baseline-partition-pass', true);
                $fault = "                        \$marker = " . $marker . ";\n"
                    . "                        \$seen = is_file(\$marker) ? (int) file_get_contents(\$marker) : 0;\n"
                    . "                        file_put_contents(\$marker, (string) (\$seen + 1));\n"
                    . "                        \$uncaptured = \$seen === 1 ? [array_shift(\$entries)] : [];\n"
                    . "                        return (object) ['baseline' => (object) ['entries' => \$entries], 'uncaptured' => \$uncaptured];";
                if (substr_count($source, $anchor) !== 1) {
                    throw new GateError('The repeated-value witness has no unique baseline generator site.');
                }
                Fs::write($probe, str_replace($anchor, $fault, $source));
            },
        );
    }

    /**
     * @param callable(Specification): Specification $plant
     * @param list<Expected> $expect
     * @param list<Tolerated> $tolerate
     * @param callable(string):void|null $prepare
     *
     * @return Witness
     */
    public static function witness(string $id, string $scenario, callable $plant, array $expect, array $tolerate = [], ?callable $prepare = null): array
    {
        $witness = ['id' => $id, 'scenario' => $scenario, 'plant' => $plant, 'expect' => $expect, 'tolerate' => $tolerate];
        if ($prepare !== null) {
            $witness['prepare'] = $prepare;
        }
        return $witness;
    }

    /**
     * One more authoritative case, firing one finding of `$channel`, which the
     * product declares (and the fixture agrees) only when `$declared`.
     *
     * @param Specification $tree
     *
     * @return Specification
     */
    private static function withCase(array $tree, string $case, string $channel, bool $declared): array
    {
        $claim = SubjectLevel::claim($channel, 'callable');
        $tree['cases'][$case] = [$claim];
        $tree['findings'][$case] = [SyntheticTree::finding(
            $tree['tuple'],
            $channel,
            \sprintf('declaration:callable:Replay\%s::run@src/%s.php', ucfirst($case), ucfirst($case)),
        )];

        if ($declared) {
            $tree['static'][$channel] = ['callable'];
            $tree['fixture'][$channel] = ['callable'];
        }

        return $tree;
    }

    /**
     * The failures a run raised, each with its class and side-specific scope, the
     * exit code the mode ended with and the declarations it changed — or why
     * it did not finish.
     *
     * @param Specification $specification
     * @param list<string>|null $flags the mode's flags, or null for a comparison
     * @param callable(string):void|null $prepare
     *
     * @return Run|string
     */
    private static function run(array $specification, ?array $flags, ?callable $prepare = null): array|string
    {
        $root = SyntheticTree::create($specification);
        try {
            if ($prepare !== null) {
                $prepare($root);
            }
            $before = self::declarations($root);
            $report = new GateReport();
            ob_start();

            try {
                $exit = GateModes::run(
                    Options::parse(['self-test', '--candidate=' . $root, '--reference=HEAD', '--jobs=4', ...$flags ?? []], $root),
                    $report,
                );
            } finally {
                ob_end_clean();
            }

            $failures = [];

            foreach ($report->raised() as $raised) {
                $failures[] = [
                    'class' => $raised['class'],
                    'scope' => $raised['scope'],
                    'detail' => $raised['detail'],
                ];
            }

            if ($flags === null && $failures === [] && $exit !== GateReport::EXIT_GREEN) {
                return 'the run reported no failure and still exited ' . $exit;
            }

            $after = self::declarations($root);
            $changed = array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after));
            sort($changed);

            return ['failures' => $failures, 'exit' => $exit, 'changed' => $changed];
        } catch (Throwable $error) {
            return $error::class . ': ' . $error->getMessage();
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /**
     * The tracked declarations a writing mode may change, by path under the
     * tree, with their bytes.
     *
     * @return array<string, string>
     */
    private static function declarations(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/finding-gate', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = Fs::read($file->getPathname());
            }
        }

        return $files;
    }

    /**
     * @param list<Witness> $witnesses
     * @param list<Failure> $failures
     *
     * @return array{failures: list<string>, observed: list<string>}
     */
    private static function judge(string $scenario, array $witnesses, array $failures): array
    {
        $problems = [];
        $observed = [];
        $accounted = [];

        foreach ($witnesses as $witness) {
            foreach ($witness['expect'] as $pattern) {
                $matched = self::matching($pattern, $failures);

                if ($matched === []) {
                    $problems[] = \sprintf(
                        'check witness %s: the %s run raised no %s @ %s. It raised: %s',
                        $witness['id'],
                        $scenario,
                        $pattern[0],
                        $pattern[1],
                        self::describe($failures),
                    );

                    continue;
                }

                $observed[] = $pattern[0];
                $accounted = [...$accounted, ...$matched];
            }

            foreach ($witness['tolerate'] as $pattern) {
                $matched = self::matching($pattern, $failures);

                if ($matched === []) {
                    $problems[] = \sprintf(
                        'check witness %s: tolerates %s @ %s, which the %s run did not raise. A side effect nobody'
                        . ' measured widens what the witness accepts.',
                        $witness['id'],
                        $pattern[0],
                        $pattern[1],
                        $scenario,
                    );
                }

                $accounted = [...$accounted, ...$matched];
            }
        }

        foreach ($failures as $index => $failure) {
            if (!\in_array($index, $accounted, true)) {
                $problems[] = \sprintf(
                    'check witness %s run: %s @ %s is named by no witness of that run: %s',
                    $scenario,
                    $failure['class'],
                    $failure['scope'],
                    substr($failure['detail'], 0, 300),
                );
            }
        }

        return ['failures' => $problems, 'observed' => $observed];
    }

    /**
     * @param Expected|Tolerated $pattern
     * @param list<Failure> $failures
     *
     * @return list<int> the indexes of the failures it matches
     */
    private static function matching(array $pattern, array $failures): array
    {
        $matched = [];

        foreach ($failures as $index => $failure) {
            if ($failure['class'] === $pattern[0] && fnmatch($pattern[1], $failure['scope'], \FNM_NOESCAPE)
            ) {
                $matched[] = $index;
            }
        }

        return $matched;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param list<Failure> $failures */
    private static function describe(array $failures): string
    {
        if ($failures === []) {
            return 'nothing';
        }

        return implode('; ', array_map(
            static fn(array $failure): string => $failure['class'] . ' @ ' . $failure['scope'],
            $failures,
        ));
    }

    /** @param list<Failure> $failures
     * @return list<string>
     */
    private static function unknownFailures(array $failures): array
    {
        $problems = [];
        foreach ($failures as $failure) {
            if (!\in_array($failure['class'], FailureClass::ALL, true)) {
                $problems[] = 'witness registry: unknown observed failure class ' . $failure['class'] . ' @ ' . $failure['scope'];
            }
        }
        return $problems;
    }
}
