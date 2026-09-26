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
    private const string BEFORE_THE_REFERENCE = 'pre-reference';

    private const string WHOLE_RUN = 'whole';

    /**
     * `map-stale` is judged only on a run that raised no `run-failed` and no
     * `reference-input-untranslated`, so the declarations the run is held to
     * cannot share a run with the plants that raise either.
     */
    private const string DECLARATIONS = 'declarations';

    private const string NORMALIZATION_REFUSED = 'derive-normalization refused';

    private const string NORMALIZATION_WRITTEN = 'derive-normalization written';

    private const string DECLARED_DELTA_REFUSED = 'derive-declared-delta refused';

    private const string DECLARED_DELTA_WRITTEN = 'derive-declared-delta written';

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
            'flags' => ['--derive-declared-delta'],
            'exit' => GateModes::MEASUREMENT_FAILED,
            'writes' => [],
        ],
        self::DECLARED_DELTA_WRITTEN => [
            'flags' => ['--derive-declared-delta'],
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

        $byScenario = [];

        foreach (self::witnesses() as $witness) {
            $byScenario[$witness['scenario']][] = $witness;
        }

        foreach ($byScenario as $scenario => $witnesses) {
            $specification = SyntheticTree::clean();

            foreach ($witnesses as $witness) {
                $specification = ($witness['plant'])($specification);
            }

            $mode = self::MODES[$scenario];
            $run = self::run($specification, $mode['flags'] ?? null, $sites);

            if (\is_string($run)) {
                foreach ($witnesses as $witness) {
                    $failures[] = \sprintf('check witness %s: the %s run did not complete: %s', $witness['id'], $scenario, $run);
                }

                continue;
            }

            $judged = self::judge($scenario, $witnesses, $run['failures']);
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

    /** @return list<Witness> */
    private static function witnesses(): array
    {
        return [
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
                [[FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH, 'TupleCheck::checkTuple#1 <- Gate::compare']],
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
                [[FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH, 'TupleCheck::checkTuple#2 <- Gate::compare']],
            ),
            self::witness(
                'determinism.differs',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:text'] = ['stdout' => 'replayed text ' . SyntheticTree::RANDOM . "\n"];

                    return $tree;
                },
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|format:text', 'NormalizationCheck::checkDeterminism#2 <- Gate::compare']],
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
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|stderr:format:summary', 'NormalizationCheck::checkDeterminism#1 <- Gate::compare']],
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
                [[FailureClass::NORMALIZATION_OVERREACH, 'candidate / case:alpha|format:json', 'NormalizationCheck::checkNormalizationLeavesFindings <- Gate::checkFindings']],
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
                    $tree['answers']['case:alpha|format:github'] = ['stdout' => 'replayed github from ' . SyntheticTree::TREE . "\n"];

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
                [[FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / gamma / finding #0', 'TupleCheck::checkTupleAgainstFindings <- Gate::checkFindings']],
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
                [[FailureClass::WITNESS_DISAGREEMENT, 'governance/Channel/Fixtures/declared.txt', 'ChannelWitness::checkAgreement <- CoverageCheck::checkWitnesses']],
            ),
            self::witness(
                'level-vocabulary-drift',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['levels'][] = 'replayed-level';

                    return $tree;
                },
                [[FailureClass::LEVEL_VOCABULARY_DRIFT, 'scripts/finding-gate/SubjectLevel.php', 'ChannelWitness::checkLevelVocabulary <- CoverageCheck::checkWitnesses']],
            ),
            self::witness(
                'report-payload-unreadable',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:html'] = ['stdout' => "<html>no payload</html>\n"];

                    return $tree;
                },
                [[FailureClass::REPORT_PAYLOAD_UNREADABLE, 'case:alpha|format:html', 'SurfaceComparison::compareSurfaces#2 <- Gate::compare']],
            ),
            self::witness(
                'run-failed',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:sarif'] = ['stdout' => "not json\n"];

                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'candidate / alpha / format:sarif', 'FingerprintCheck::decodeFingerprintSurface <- Gate::checkFindings']],
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
                [[FailureClass::RUN_FAILED, '* / omega', 'CaseOutcomeCheck::findingsOf#1 <- Gate::checkFindings']],
                [
                    [FailureClass::CASE_CLAIM_MISMATCH, 'case:omega'],
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
                [[FailureClass::RUN_FAILED, '* / alpha / baseline:generate', 'CaseOutcomeCheck::checkBaselineSurface#1 <- Gate::checkFindings']],
            ),
            self::witness(
                'baseline-empty',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'eta', 'replay.eta', declared: true);
                    $tree['answers']['case:eta|baseline-file'] = ['stdout' => ''];

                    return $tree;
                },
                [[FailureClass::RUN_FAILED, '* / eta / baseline-file', 'CaseOutcomeCheck::checkBaselineSurface#2 <- Gate::checkFindings']],
            ),
            self::witness(
                'reference-input',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:text-verbose'] = ['stdout' => "replayed text-verbose of alpha\n", 'exit' => 3];
                    $tree['candidateAnswers']['case:alpha|format:text-verbose'] = ['stdout' => "replayed text-verbose of alpha\n"];

                    return $tree;
                },
                [[FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'reference / case:alpha', 'RenameMapCheck::checkReferenceInput <- Gate::compare']],
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
                    $tree['answers']['case:alpha|format:sarif'] = ['stdout' => self::json(['runs' => [[
                        'results' => [['partialFingerprints' => ['primaryLocationLineHash' => 'not the recomputed identity']]],
                    ]]])];

                    return $tree;
                },
                [[FailureClass::FINGERPRINT_MISMATCH, '* / alpha / sarif partialFingerprints', 'FingerprintCheck::checkFingerprints <- Gate::checkFindings']],
            ),
            self::witness(
                'fingerprint-opaque',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $hash = Fingerprints::md5Of(Fingerprints::expected($tree['findings']['alpha']))[0];
                    $tree['answers']['case:alpha|format:gitlab'] = ['stdout' => self::json([
                        ['check_name' => 'replayed', 'fingerprint' => $hash, 'description' => $hash],
                    ])];

                    return $tree;
                },
                [[FailureClass::FINGERPRINT_OPAQUE, '* / case:alpha|format:gitlab', 'FingerprintCheck::substituteFingerprints <- SurfaceComparison::compareSurfaces']],
            ),
            self::witness(
                'published-order',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => self::json(['entries' => [
                        'declaration:callable:Replay\Alpha::run@src/Alpha.php' => [['channel' => 'replay.zulu'], ['channel' => 'replay.alpha']],
                    ]])];

                    return $tree;
                },
                [[FailureClass::PUBLISHED_ORDER_DRIFT, 'case:alpha|baseline-file', 'SurfaceComparison::checkPublishedOrder <- Gate::compare']],
            ),
            self::witness(
                'surface-one-side',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:github'] = [
                        'stdout' => "replayed github of alpha\n",
                        'stderr' => "replayed warning\n",
                    ];

                    return $tree;
                },
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|stderr:format:github', 'SurfaceComparison::compareSurfaces#1 <- Gate::compare']],
            ),
            self::witness(
                'surface-differs',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:checkstyle'] = ['stdout' => "another checkstyle of alpha\n"];

                    return $tree;
                },
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:checkstyle', 'DeclaredDeltaCheck::checkDifference <- SurfaceComparison::compareSurfaces']],
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
                [[FailureClass::FINDING_COUNT_MISMATCH, 'case:zeta', 'SurfaceComparison::compareFindingCounts <- Gate::compare']],
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
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|format:summary', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#3 <- SurfaceComparison::compareSurfaces']],
            ),
            self::witness(
                'delta-too-large',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $lines = '';

                    for ($line = 0; $line <= DeclaredDelta::MAX_CHANGED_LINES; ++$line) {
                        $lines .= 'line ' . $line . "\n";
                    }

                    $tree['candidateAnswers']['case:alpha|format:text-verbose'] = ['stdout' => $lines];
                    $tree['declaredDelta']['case:alpha|format:text-verbose'] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_TOO_LARGE, 'case:alpha|format:text-verbose', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#1 <- SurfaceComparison::compareSurfaces']],
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
                [[FailureClass::DELTA_OVERREACH, 'case:alpha|format:json', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#2 <- SurfaceComparison::compareSurfaces']],
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
                'field-move-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['fieldMoves'][] = ['case:alpha|format:json', 'message', 'nowhere', 'elsewhere'];

                    return $tree;
                },
                [[FailureClass::FIELD_MOVE_STALE, 'case:alpha|format:json', 'DeclaredDeltaCheck::checkStaleFieldMoves <- Gate::compare']],
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
                'derive-declared-delta-refuses-a-failed-comparison',
                self::DECLARED_DELTA_REFUSED,
                static function (array $tree): array {
                    $tree['candidateLock'] = "{\"replay\": \"another lock\"}\n";

                    return $tree;
                },
                [[FailureClass::ENV_MISMATCH, 'reference tree', 'Gate::compare <- GateModes::deriveDeclaredDelta']],
            ),
            self::witness(
                'derive-declared-delta-writes-a-measured-diff',
                self::DECLARED_DELTA_WRITTEN,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:checkstyle'] = ['stdout' => "another checkstyle of alpha\n"];

                    return $tree;
                },
                [],
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
    private static function witness(string $id, string $scenario, callable $plant, array $expect, array $tolerate = []): array
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
