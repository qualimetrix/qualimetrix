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
 * glob and the {@see RaiseSites} identity — site and caller — it must come
 * from) and the side effects it may raise (`tolerate`: class and scope glob). A
 * run is held to both directions: an expected failure missing, a failure
 * nothing names, a toleration nothing used and a failure raised from a place
 * the scan of the source does not enumerate are each red. A scenario that runs
 * a writing mode is also held to its exit code and to exactly the declarations
 * it changed. The clean tree underneath all of them must be GREEN, or every
 * observation below could be the stand's own defect.
 *
 * @phpstan-import-type Specification from SyntheticTree
 *
 * @phpstan-type Expected array{0: string, 1: string, 2: string}
 * @phpstan-type Tolerated array{0: string, 1: string}
 * @phpstan-type Witness array{
 *     id: string,
 *     scenario: string,
 *     plant: callable(Specification): Specification,
 *     expect: list<Expected>,
 *     tolerate: list<Tolerated>,
 * }
 * @phpstan-type Failure array{class: string, scope: string, detail: string, identity: string}
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
     * `observed` holds only the identities an expectation actually matched.
     *
     * @return array{failures: list<string>, observed: list<string>}
     */
    public static function observe(RaiseSites $sites): array
    {
        $failures = self::unwitnessedModes();
        $observed = [];

        foreach (self::witnesses() as $witness) {
            foreach ($witness['expect'] as $pattern) {
                if (!isset($sites->sites[$pattern[2]])) {
                    $failures[] = \sprintf(
                        'check witness %s: expects %s from %s, which is no raise site in the gate\'s source.',
                        $witness['id'],
                        $pattern[0],
                        $pattern[2],
                    );
                }
            }
        }

        $clean = self::run(SyntheticTree::clean(), null, $sites);

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
            $run = self::run($specification, $mode['flags'] ?? null, $sites);

            if (\is_string($run)) {
                $failures[] = \sprintf('check witness %s: the %s run did not complete: %s', $witness['id'], $scenario, $run);

                continue;
            }

            $judged = self::judge($scenario, [$witness], $run['failures']);
            $failures = [...$failures, ...$judged['failures'], ...self::unenumerated($scenario, $run['failures'], $sites)];
            $observed = [...$observed, ...$judged['observed']];

            if ($mode !== null) {
                $failures = [...$failures, ...self::judgeMode($scenario, $mode, $run)];
            }
        }

        return ['failures' => $failures, 'observed' => array_values(array_unique($observed))];
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
     * A raise the scan of the source did not enumerate is a check no witness
     * could be required for — a callable, a wrapper, a file the scan skipped.
     *
     * @param list<Failure> $failures
     *
     * @return list<string>
     */
    private static function unenumerated(string $scenario, array $failures, RaiseSites $sites): array
    {
        $problems = [];

        foreach ($failures as $failure) {
            if (!isset($sites->sites[$failure['identity']])) {
                $problems[] = \sprintf(
                    'witness registry: the %s run raised %s @ %s at %s, which the scan of the gate\'s source does not'
                    . ' enumerate, so no witness is required for it.',
                    $scenario,
                    $failure['class'],
                    $failure['scope'],
                    $failure['identity'],
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
            self::witness(
                'case-capture-refusal',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => '{"violationsMeta":{"total":-1,"truncated":false}}'];
                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'candidate-1', 'Gate::runTree <- GateModes::compare']],
            ),
            self::witness(
                'declaration-capture-refusal',
                self::DECLARED_DELTA_REFUSED,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => '{"violationsMeta":{"total":-1,"truncated":false}}'];
                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'candidate-1', 'Gate::runTree <- GateModes::deriveDeclarations']],
            ),
            self::witness(
                'normalization-capture-refusal',
                self::NORMALIZATION_REFUSED,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => '{"violationsMeta":{"total":-1,"truncated":false}}'];
                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'derive-1', 'Gate::runTree <- GateModes::deriveNormalization']],
            ),
            self::witness(
                'existing-reference-outcome-refusal',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $refusal = SelfTestOutcomes::fixture();
                    $tree['answers'] = $refusal['candidateAnswers'];
                    $tree['candidateAnswers'] = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, []);
                    $tree['candidateDeclarations'][DeclaredOutcomes::INDEX] = Tsv::render(DeclaredOutcomes::COLUMNS, [
                        ['alpha', DeclaredOutcomes::REFUSAL_TO_ANALYSIS, 'declared-outcomes/alpha.json', 'Existing reference inputs must be translated.'],
                    ]);
                    $snapshot = [];
                    foreach ($refusal['candidateAnswers'] as $key => $answer) {
                        $snapshot[$key] = ['stdout' => $answer['stdout'] ?? '', 'stderr' => $answer['stderr'] ?? '', 'exit' => (string) ($answer['exit'] ?? 0)];
                    }
                    $tree['candidateDeclarations']['declared-outcomes/alpha.json'] = self::json($snapshot);

                    return $tree;
                },
                [
                    [FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'case:alpha', 'CaseOutcomeCheck::checkRun <- Gate::compare'],
                    [FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'reference / case:alpha', 'RenameMapCheck::checkReferenceInput <- Gate::compare'],
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
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'derive-1 / alpha', 'CaseOutcomeCheck::mismatch <- Gate::deriveNormalization'],
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'derive-2 / alpha', 'CaseOutcomeCheck::mismatch <- Gate::deriveNormalization'],
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'derive-3 / alpha', 'CaseOutcomeCheck::mismatch <- Gate::deriveNormalization'],
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'derive-4 / alpha', 'CaseOutcomeCheck::mismatch <- Gate::deriveNormalization'],
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'derive-5 / alpha', 'CaseOutcomeCheck::mismatch <- Gate::deriveNormalization'],
                ],
            ),
            self::witness(
                'env-mismatch',
                self::BEFORE_THE_REFERENCE,
                static function (array $tree): array {
                    $tree['candidateLock'] = "{\"replay\": \"another lock\"}\n";

                    return $tree;
                },
                [[FailureClass::ENV_MISMATCH, 'reference tree', 'Gate::compare <- GateModes::compare']],
            ),
            self::witness(
                'tuple-drift',
                self::BEFORE_THE_REFERENCE,
                static function (array $tree): array {
                    $tree['tuple'] = array_values(array_diff($tree['tuple'], ['message']));

                    return $tree;
                },
                [
                    [FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH, 'TupleCheck::checkTuple#1 <- Gate::compare'],
                    [FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / alpha / finding #0', 'TupleCheck::checkTupleAgainstFindings <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::FINDING_TUPLE_MISMATCH, 'reference / alpha / finding #0', 'TupleCheck::checkTupleAgainstFindings <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|baseline-file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|check:output:file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:checkstyle', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:github', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:gitlab', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:html', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:sarif', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:summary', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:text', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:text-verbose', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|show-suppressed', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
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
                    [FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH, 'TupleCheck::checkTuple#2 <- Gate::compare'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|baseline-file', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|baseline-file', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|baseline-file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
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
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|format:text', 'NormalizationCheck::checkDeterminism <- Gate::compare']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:text']],
            ),
            self::witness(
                'determinism.one-run-only',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:summary'] = [
                        'stdout' => "replayed summary of alpha\n",
                        'stderr' => "replayed warning\n",
                        'stderrOnce' => true,
                    ];

                    return $tree;
                },
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|stderr:format:summary', 'NormalizationCheck::checkDeterminism <- Gate::compare']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|stderr:format:summary']],
            ),
            self::witness(
                'normalization-scope',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:metrics', 'summary.channel', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [[FailureClass::NORMALIZATION_OVERREACH, 'format:metrics / summary.channel', 'NormalizationCheck::checkNormalizationScope <- Gate::compare']],
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
                    [FailureClass::NORMALIZATION_OVERREACH, 'candidate / case:alpha|format:json', 'NormalizationCheck::checkNormalizationLeavesFindings <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|baseline-file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:checkstyle', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:github', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:gitlab', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:html', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:json', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:sarif', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:summary', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:text', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:text-verbose', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|show-suppressed', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                ],
                [[FailureClass::NORMALIZATION_OVERREACH, '* / case:*|format:json']],
            ),
            self::witness(
                'stale-normalization',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:json', 'never.present', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [[FailureClass::NORMALIZATION_STALE, 'format:json / never.present', 'NormalizationCheck::checkStaleNormalization <- Gate::compare']],
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
                [[FailureClass::PATH_LEAK, 'reference / case:alpha|format:github', 'SurfaceComparison::checkPathLeaks <- Gate::compare']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:github']],
            ),
            self::witness(
                'single-producer',
                self::WHOLE_RUN,
                static fn(array $tree): array => self::withCase($tree, 'beta', 'replay.alpha', declared: true),
                [[FailureClass::COVERAGE_MULTIPLICITY, 'corpus', 'CoverageCheck::checkSingleProducer <- Gate::compare']],
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
                    [FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / gamma / finding #0', 'TupleCheck::checkTupleAgainstFindings <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:gamma|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|baseline-file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|check:output:file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:checkstyle', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:github', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:gitlab', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:html', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:json', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:sarif', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:summary', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:text', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|format:text-verbose', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:gamma|show-suppressed', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                ],
                [[FailureClass::FINDING_TUPLE_MISMATCH, 'reference / gamma / finding #0']],
            ),
            self::witness(
                'coverage-surplus',
                self::WHOLE_RUN,
                static fn(array $tree): array => self::withCase($tree, 'delta', 'replay.undeclared', declared: false),
                [[FailureClass::COVERAGE_SURPLUS, 'corpus', 'ChannelCoverage::reportSurplus <- CoverageCheck::checkCoverage']],
            ),
            self::witness(
                'witness-disagreement',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['fixture']['replay.fixture-only'] = ['class'];

                    return $tree;
                },
                [[FailureClass::WITNESS_DISAGREEMENT, 'governance/Channel/Fixtures/declared.txt', 'ChannelWitness::checkAgreement <- CoverageCheck::checkChannelWitnesses']],
            ),
            self::witness(
                'level-vocabulary-drift',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['levels'][] = 'replayed-level';

                    return $tree;
                },
                [[FailureClass::LEVEL_VOCABULARY_DRIFT, 'scripts/finding-gate/SubjectLevel.php', 'ChannelWitness::checkLevelVocabulary <- CoverageCheck::checkChannelWitnesses']],
            ),
            self::witness(
                'report-payload-unreadable',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:html'] = ['stdout' => "<html>no payload</html>\n"];

                    return $tree;
                },
                [
                    [FailureClass::REPORT_PAYLOAD_UNREADABLE, 'case:alpha|format:html', 'SurfaceComparison::extractPayload <- Gate::compare'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:html', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|format:html', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                ],
            ),
            self::witness(
                'run-failed',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:sarif'] = ['stdout' => "not json\n"];

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, 'candidate / alpha / format:sarif', 'FingerprintCheck::decodeFingerprintSurface <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:sarif', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|format:sarif', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|format:sarif', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                ],
                [[FailureClass::RUN_FAILED, 'reference / alpha / format:sarif']],
            ),
            self::witness(
                'no-findings-section',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'omega', 'replay.omega', declared: true);
                    $tree['answers']['case:omega|format:json'] = ['stdout' => "not json\n"];

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, '* / omega', 'CaseOutcomeCheck::findingsOf#1 <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|baseline-file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:checkstyle', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:github', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:gitlab', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:html', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:json', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:sarif', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:summary', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:text', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|format:text-verbose', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:omega|show-suppressed', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::CANDIDATE_INPUT_REFUSED, 'case:omega', 'CoverageCheck::inputRefused <- Gate::compare'],
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

                    return $tree;
                },
                [[FailureClass::RUN_FAILED, '* / alpha', 'CaseOutcomeCheck::findingsOf#2 <- Gate::checkFindings']],
            ),
            self::witness(
                'baseline-exit',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => "{\"replayed\": \"alpha\"}\n", 'exit' => 1];

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, '* / alpha / baseline:generate', 'CaseOutcomeCheck::checkBaselineSurface#1 <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|baseline-file', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:alpha|baseline-file', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:alpha|baseline-file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::SURFACE_MISMATCH, 'candidate / case:alpha|baseline-file', 'CaptureCheck::publicationFailure <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'reference / case:alpha|baseline-file', 'CaptureCheck::publicationFailure <- Gate::compare'],
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
                    [FailureClass::RUN_FAILED, '* / eta / baseline-file', 'CaseOutcomeCheck::checkBaselineSurface#2 <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:eta|baseline-file', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'reference / case:eta|baseline-file', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                    [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:eta|baseline-file', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
                    [FailureClass::SURFACE_MISMATCH, 'candidate / case:eta|baseline-file', 'CaptureCheck::publicationFailure <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'reference / case:eta|baseline-file', 'CaptureCheck::publicationFailure <- Gate::compare'],
                ],
            ),
            self::witness(
                'reference-input',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, []);
                    $tree['answers']['case:alpha|format:text-verbose'] = [...$answers['case:alpha|format:text-verbose'], 'exit' => 3];
                    $tree['candidateAnswers']['case:alpha|format:text-verbose'] = $answers['case:alpha|format:text-verbose'];

                    return $tree;
                },
                [
                    [FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'reference / case:alpha', 'RenameMapCheck::checkReferenceInput <- Gate::compare'],
                    [FailureClass::VALUE_MISMATCH, 'case:alpha|format:text-verbose', 'ValueCheck::measure <- ValueStage::applyStage'],
                    [FailureClass::SURFACE_MISMATCH, 'reference / case:alpha|format:text-verbose', 'CaptureCheck::publicationFailure <- Gate::compare'],
                ],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|exit:format:text-verbose']],
            ),
            self::witness(
                'map-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['maps']['symbols'][] = "Replay\\Nowhere\tReplay\\Elsewhere\tself-test";

                    return $tree;
                },
                [[FailureClass::MAP_STALE, '*Replay\Nowhere*', 'RenameMapCheck::checkStaleMaps <- Gate::compare']],
            ),
            self::witness(
                'split-unmapped',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['maps']['channels'][] = "replay.alpha#replay.never-one\treplay.split-one#replay.split-one\tself-test";
                    $tree['maps']['channels'][] = "replay.alpha#replay.never-two\treplay.split-two#replay.split-two\tself-test";

                    return $tree;
                },
                [[FailureClass::SPLIT_UNMAPPED, 'case:alpha', 'RenameMapCheck::checkSplitExplanation <- Gate::compare']],
                [[FailureClass::MAP_STALE, '*replay.never-*']],
            ),
            self::witness(
                'case-claim',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['cases']['alpha'][] = SubjectLevel::claim('replay.alpha', 'class');

                    return $tree;
                },
                [[FailureClass::CASE_CLAIM_MISMATCH, 'case:alpha', 'CoverageCheck::checkCaseClaim <- Gate::compare']],
            ),
            self::witness(
                'coverage-shortfall',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['static']['replay.unfired'] = ['callable'];
                    $tree['fixture']['replay.unfired'] = ['callable'];

                    return $tree;
                },
                [[FailureClass::COVERAGE_SHORTFALL, 'corpus', 'ChannelCoverage::reportShortfall <- CoverageCheck::checkCoverage']],
            ),
            self::witness(
                'fingerprint-mismatch',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, []);
                    $document = json_decode(($answers['case:alpha|format:sarif']['stdout'] ?? throw new GateError('A SARIF witness requires stdout.')), true, 512, \JSON_THROW_ON_ERROR);
                    $document['runs'][0]['results'][0]['partialFingerprints']['primaryLocationLineHash'] = 'not the recomputed identity';
                    $tree['answers']['case:alpha|format:sarif'] = ['stdout' => self::json($document)];

                    return $tree;
                },
                [[FailureClass::FINGERPRINT_MISMATCH, '* / alpha / sarif partialFingerprints', 'FingerprintCheck::checkFingerprints <- Gate::checkFindings']],
            ),
            self::witness(
                'fingerprint-opaque',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $hash = Fingerprints::md5Of(Fingerprints::expected($tree['findings']['alpha']))[0];
                    $tree['findings']['alpha'][0]['message'] = $hash;

                    return $tree;
                },
                [[FailureClass::FINGERPRINT_OPAQUE, '* / case:alpha|format:gitlab', 'FingerprintCheck::substituteFingerprints <- SurfaceComparison::fingerprints']],
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
                [[FailureClass::PUBLISHED_ORDER_DRIFT, 'case:alpha|baseline-file', 'SurfaceComparison::checkPublishedOrder <- Gate::compare']],
            ),
            self::witness(
                'surface-one-side',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $tree['candidateAnswers']['case:alpha|format:github'] = [...$answers['case:alpha|format:github'], 'stderr' => "replayed warning\n"];

                    return $tree;
                },
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|stderr:format:github', 'SurfaceComparison::mismatch <- Gate::compare']],
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
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:checkstyle', 'SurfaceComparison::mismatch <- Gate::compare']],
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
                    [FailureClass::FINDING_COUNT_MISMATCH, 'case:zeta', 'SurfaceComparison::compareFindingCounts <- Gate::compare'],
                    [FailureClass::RECORD_UNDECLARED, 'case:zeta|format:json', 'RecordCheck::observeResidual <- RecordStage::countInputs'],
                    [FailureClass::SURFACE_MISMATCH, 'case:zeta|baseline-file', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:zeta|check:output:file', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:zeta|show-suppressed', 'SurfaceComparison::mismatch <- Gate::compare'],
                ],
                [[FailureClass::SURFACE_MISMATCH, 'case:zeta|format:*']],
            ),
            self::witness(
                'delta-mismatch',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:summary'] = ['stdout' => "another summary of alpha\n"];
                    $tree['declaredDelta']['case:alpha|format:summary'] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|format:summary', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#3 <- SurfaceComparison::compareFinalBytes']],
            ),
            self::witness(
                'delta-too-large',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $lines = '';

                    for ($line = 0; $line <= DeclaredDelta::MAX_CHANGED_LINES; ++$line) {
                        $lines .= 'line ' . $line . "\n";
                    }

                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $tree['candidateAnswers']['case:alpha|format:text-verbose'] = $answers['case:alpha|format:text-verbose'];
                    $tree['candidateAnswers']['case:alpha|format:text-verbose']['stdout'] = ($tree['candidateAnswers']['case:alpha|format:text-verbose']['stdout'] ?? throw new GateError('A renderer-backed witness requires stdout.')) . $lines;
                    $tree['declaredDelta']['case:alpha|format:text-verbose'] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_TOO_LARGE, 'case:alpha|format:text-verbose', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#1 <- SurfaceComparison::compareFinalBytes']],
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|format:text-verbose']],
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
                    [FailureClass::DELTA_OVERREACH, 'case:alpha|format:json', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#2 <- SurfaceComparison::compareFinalBytes'],
                    [FailureClass::VALUE_MISMATCH, 'case:alpha|format:json|record:{"channel":"replay.alpha","subject":"declaration:callable:Replay\\\\Alpha::run@src/Alpha.php","occurrence":null,"edge":null}', 'ValueCheck::measure <- RecordCheck::prepare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|check:output:file', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:checkstyle', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:github', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:gitlab', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:html', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:sarif', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:text', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|format:text-verbose', 'SurfaceComparison::mismatch <- Gate::compare'],
                    [FailureClass::SURFACE_MISMATCH, 'case:alpha|show-suppressed', 'SurfaceComparison::mismatch <- Gate::compare'],
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
                [[FailureClass::DELTA_STALE, 'case:alpha|format:health', 'DeclaredDeltaCheck::checkStaleDeclaredDelta <- Gate::compare']],
            ),
            self::witness(
                'delta-class-requires-each-case-to-move',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['declaredDelta']['format:health'] = self::NO_DIFF;

                    return $tree;
                },
                [
                    [FailureClass::DELTA_STALE, 'case:alpha|format:health', 'DeclaredDeltaCheck::observeEqual <- SurfaceComparison::compareFinalBytes'],
                    [FailureClass::DELTA_STALE, 'format:health', 'DeclaredDeltaCheck::checkStaleDeclaredDelta <- Gate::compare'],
                ],
            ),
            self::witness(
                'field-move-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['fieldMoves'][] = ['case:alpha|format:json', 'message', 'nowhere', 'elsewhere'];

                    return $tree;
                },
                [[FailureClass::FIELD_MOVE_STALE, 'case:alpha|format:json', 'DeclaredDeltaCheck::checkStaleFieldMoves <- Gate::compare']],
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
                    [FailureClass::RECORD_STALE, 'case:alpha|format:json', 'StaleDeclarationCheck::checkStaleDeclarations#1 <- Gate::compare'],
                    [FailureClass::RECORD_STALE, DeclaredRecords::INDEX, 'StaleDeclarationCheck::checkStaleDeclarations#1 <- Gate::compare'],
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
                [[FailureClass::VALUE_STALE, DeclaredValues::INDEX, 'StaleDeclarationCheck::checkStaleDeclarations#2 <- Gate::compare']],
            ),
            self::witness(
                'stale-outcome-declaration',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree = SelfTestOutcomes::fixture();
                    $snapshot = [];
                    foreach ($tree['candidateAnswers'] as $key => $answer) {
                        $snapshot[$key] = ['stdout' => $answer['stdout'] ?? '', 'stderr' => $answer['stderr'] ?? '', 'exit' => (string) ($answer['exit'] ?? 0)];
                    }
                    $snapshot['case:alpha|format:json']['stderr'] = "a refusal nobody printed\n";
                    $tree['candidateDeclarations']['declared-outcomes/alpha.json'] = self::json($snapshot);

                    return $tree;
                },
                [
                    [FailureClass::OUTCOME_DECLARATION_STALE, 'case:alpha', 'StaleDeclarationCheck::checkStaleDeclarations#4 <- Gate::compare'],
                    [FailureClass::CASE_OUTCOME_MISMATCH, 'case:alpha', 'CaseOutcomeCheck::mismatch <- Gate::compare'],
                ],
            ),
            self::witness(
                'stale-surface-declaration',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree = SelfTestCapture::withdrawnFixture();
                    $tree['candidateDeclarations']['declared-surfaces/retired.json'] = self::json(['stdout' => '', 'stderr' => 'a refusal nobody printed', 'exit' => '3']);

                    return $tree;
                },
                [
                    [FailureClass::SURFACE_DECLARATION_STALE, 'format:retired', 'StaleDeclarationCheck::checkStaleDeclarations#5 <- Gate::compare'],
                    [FailureClass::SURFACE_WITHDRAWAL_MISMATCH, 'case:alpha|format:retired', 'CaptureCheck::withdrawal <- Gate::compare'],
                ],
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
                [[FailureClass::STRUCTURAL_MAP_STALE, DeclaredStructuralMaps::INDEX, 'StaleDeclarationCheck::checkStaleDeclarations#6 <- Gate::compare']],
            ),
            self::witness(
                'field-declaration-stale-empty-variant',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
                    $emptySource = "{\"violations\": []}\n";
                    $emptyBaseline = "{\"version\":13,\"scope\":[\"src\"],\"entries\":{}}\n";
                    $tree['answers']['case:alpha|check:baseline-source'] = ['stdout' => $emptySource];
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => $emptyBaseline, 'file' => $emptyBaseline];
                    $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, [
                        [DeclaredFields::REMOVED, 'json', 'check:baseline-source', 'retired', 'an unused field license on the observed empty variant'],
                    ]);

                    return $tree;
                },
                [[FailureClass::FIELD_DECLARATION_STALE, DeclaredFields::INDEX, 'StaleDeclarationCheck::checkStaleDeclarations#3 <- Gate::compare']],
            ),
            self::witness(
                'derive-normalization-refuses-a-dead-pass',
                self::NORMALIZATION_REFUSED,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'omega', 'replay.omega', declared: true);
                    $tree = self::withCase($tree, 'eta', 'replay.eta', declared: true);
                    $tree['answers']['case:omega|format:json'] = ['stdout' => "not json\n"];
                    $tree['truncated'][] = 'alpha';
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => "{\"replayed\": \"alpha\"}\n", 'exit' => 1];
                    $tree['answers']['case:eta|baseline-file'] = ['stdout' => ''];
                    // A row no pass fires: a list measured anyway would drop it, so a write cannot hide.
                    $tree['normalization'][] = ['format:json', 'never.present', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [
                    [FailureClass::RUN_FAILED, 'derive-* / omega', 'CaseOutcomeCheck::findingsOf#1 <- Gate::deriveNormalization'],
                    [FailureClass::RUN_FAILED, 'derive-* / alpha', 'CaseOutcomeCheck::findingsOf#2 <- Gate::deriveNormalization'],
                    [
                        FailureClass::RUN_FAILED,
                        'derive-* / alpha / baseline:generate',
                        'CaseOutcomeCheck::checkBaselineSurface#1 <- Gate::deriveNormalization',
                    ],
                    [
                        FailureClass::RUN_FAILED,
                        'derive-* / eta / baseline-file',
                        'CaseOutcomeCheck::checkBaselineSurface#2 <- Gate::deriveNormalization',
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
                [[FailureClass::ENV_MISMATCH, 'reference tree', 'Gate::compare <- GateModes::deriveDeclarations']],
            ),
            self::witness(
                'derive-declarations-writes-a-measured-diff',
                self::DECLARED_DELTA_WRITTEN,
                static function (array $tree): array {
                    $answers = SyntheticTree::caseAnswers('alpha', $tree['candidateFindings']['alpha'] ?? $tree['findings']['alpha'], false, []);
                    $tree['candidateAnswers']['case:alpha|format:checkstyle'] = $answers['case:alpha|format:checkstyle'];
                    $tree['candidateAnswers']['case:alpha|format:checkstyle']['stdout'] = ($tree['candidateAnswers']['case:alpha|format:checkstyle']['stdout'] ?? throw new GateError('A renderer-backed witness requires stdout.')) . "\n<!-- replayed checkstyle difference -->\n";
                    $tree['declaredDelta']['case:alpha|format:checkstyle'] = self::NO_DIFF;

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
                        $key = 'case:' . $case . '|format:checkstyle';
                        $answers = SyntheticTree::caseAnswers($case, $tree['findings'][$case], false, []);
                        $tree['candidateAnswers'][$key] = $answers[$key];
                        $tree['candidateAnswers'][$key]['stdout'] = ($tree['candidateAnswers'][$key]['stdout'] ?? throw new GateError('A renderer-backed witness requires stdout.')) . "\n<!-- explicit " . $case . " difference -->\n";
                    }
                    $tree['declaredDelta']['format:checkstyle'] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_MISMATCH, 'case:beta|format:checkstyle', 'DeclaredDeltaCheck::checkDifference <- SurfaceComparison::compareFinalBytes']],
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

    /**
     * @param callable(Specification): Specification $plant
     * @param list<Expected> $expect
     * @param list<Tolerated> $tolerate
     *
     * @return Witness
     */
    public static function witness(string $id, string $scenario, callable $plant, array $expect, array $tolerate = []): array
    {
        return ['id' => $id, 'scenario' => $scenario, 'plant' => $plant, 'expect' => $expect, 'tolerate' => $tolerate];
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
     * The failures a run raised, each with the identity it was raised at, the
     * exit code the mode ended with and the declarations it changed — or why
     * it did not finish.
     *
     * @param Specification $specification
     * @param list<string>|null $flags the mode's flags, or null for a comparison
     *
     * @return Run|string
     */
    private static function run(array $specification, ?array $flags, RaiseSites $sites): array|string
    {
        $root = SyntheticTree::create($specification);
        $siteAt = [];

        foreach ($sites->sites as $site) {
            $siteAt[$site['file'] . ':' . $site['line']] = $site['site'];
        }

        try {
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
                $where = $raised['file'] . ':' . $raised['line'];
                $failures[] = [
                    'class' => $raised['class'],
                    'scope' => $raised['scope'],
                    'detail' => $raised['detail'],
                    'identity' => isset($siteAt[$where]) ? $sites->identityOf($siteAt[$where], $raised['chain']) : $where,
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
                        'check witness %s: the %s run raised no %s @ %s at %s. It raised: %s',
                        $witness['id'],
                        $scenario,
                        $pattern[0],
                        $pattern[1],
                        $pattern[2],
                        self::describe($failures),
                    );

                    continue;
                }

                $observed[] = $pattern[2];
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
                    'check witness %s run: %s @ %s at %s is named by no witness of that run: %s',
                    $scenario,
                    $failure['class'],
                    $failure['scope'],
                    $failure['identity'],
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
                && (!isset($pattern[2]) || $failure['identity'] === $pattern[2])
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
            static fn(array $failure): string => $failure['class'] . ' @ ' . $failure['scope'] . ' at ' . $failure['identity'],
            $failures,
        ));
    }
}
