<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Each of the gate's checks, seen raising its failure class in a whole run.
 *
 * A check whose body is removed only removes failures, so neither a green
 * control nor a red one whose required class comes from elsewhere notices.
 * What notices is a run built to trip exactly that check: a {@see SyntheticTree}
 * carrying one planted defect per witness, compared by {@see Gate::compare()},
 * judged by the {@see GateReport} it wrote. The public entry point is the
 * point — these witnesses must hold unchanged while the checks move between
 * files.
 *
 * Each witness names the failures its plant must raise (`expect`: class, scope
 * glob and the raise site of {@see WitnessRegistry::sites()} it must come from)
 * and the side effects it may raise (`tolerate`: class and scope glob). A run
 * is held to both directions: an expected failure missing, a failure nothing
 * names and a toleration nothing used are each red. The clean tree underneath
 * all of them must be GREEN, or every observation below could be the stand's
 * own defect.
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
 * @phpstan-type Failure array{class: string, scope: string, detail: string, site: string}
 * @phpstan-type Site array{class: string, file: string, line: int}
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

    private const string NO_DIFF = "a diff nobody measured\n";

    /**
     * `observed` holds only the raise sites an expectation actually matched.
     *
     * @param array<string, Site> $sites
     *
     * @return array{failures: list<string>, observed: list<string>}
     */
    public static function observe(array $sites): array
    {
        $failures = [];
        $observed = [];
        $siteAt = [];

        foreach ($sites as $site => $where) {
            $siteAt[$where['file'] . ':' . $where['line']] = $site;
        }

        foreach (self::witnesses() as $witness) {
            foreach ($witness['expect'] as $pattern) {
                if (!isset($sites[$pattern[2]])) {
                    $failures[] = \sprintf(
                        'check witness %s: expects %s from %s, which is no raise site in the gate\'s source.',
                        $witness['id'],
                        $pattern[0],
                        $pattern[2],
                    );
                }
            }
        }

        $clean = self::run(SyntheticTree::clean(), $siteAt);

        if (\is_string($clean) || $clean !== []) {
            $failures[] = \sprintf(
                'check witness clean-tree: the unplanted synthetic tree is not GREEN, so no witness below can be told'
                . ' from a defect of the stand: %s',
                \is_string($clean) ? $clean : self::describe($clean),
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

            $run = self::run($specification, $siteAt);

            if (\is_string($run)) {
                foreach ($witnesses as $witness) {
                    $failures[] = \sprintf('check witness %s: the %s run did not complete: %s', $witness['id'], $scenario, $run);
                }

                continue;
            }

            $judged = self::judge($scenario, $witnesses, $run);
            $failures = [...$failures, ...$judged['failures']];
            $observed = [...$observed, ...$judged['observed']];
        }

        return ['failures' => $failures, 'observed' => array_values(array_unique($observed))];
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
                [[FailureClass::ENV_MISMATCH, 'reference tree', 'Gate::compare']],
            ),
            self::witness(
                'tuple-drift',
                self::BEFORE_THE_REFERENCE,
                static function (array $tree): array {
                    $tree['tuple'] = array_values(array_diff($tree['tuple'], ['message']));

                    return $tree;
                },
                [[FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH, 'TupleCheck::checkTuple#1']],
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
                [[FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH, 'TupleCheck::checkTuple#2']],
            ),
            self::witness(
                'determinism.differs',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:text'] = ['stdout' => 'replayed text ' . SyntheticTree::RANDOM . "\n"];

                    return $tree;
                },
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|format:text', 'NormalizationCheck::checkDeterminism#2']],
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
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|stderr:format:summary', 'NormalizationCheck::checkDeterminism#1']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|stderr:format:summary']],
            ),
            self::witness(
                'normalization-scope',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:metrics', 'summary.channel', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [[FailureClass::NORMALIZATION_OVERREACH, 'format:metrics / summary.channel', 'NormalizationCheck::checkNormalizationScope']],
                [[FailureClass::NORMALIZATION_STALE, 'format:metrics / summary.channel']],
            ),
            self::witness(
                'normalization-leaves-findings',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:json', 'violations', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [[FailureClass::NORMALIZATION_OVERREACH, 'candidate / case:alpha|format:json', 'NormalizationCheck::checkNormalizationLeavesFindings']],
                [[FailureClass::NORMALIZATION_OVERREACH, '* / case:*|format:json']],
            ),
            self::witness(
                'stale-normalization',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['normalization'][] = ['format:json', 'never.present', NormalizationRule::KIND_JSON_PATH];

                    return $tree;
                },
                [[FailureClass::NORMALIZATION_STALE, 'format:json / never.present', 'NormalizationCheck::checkStaleNormalization']],
            ),
            self::witness(
                'path-leak',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:github'] = ['stdout' => 'replayed github from ' . SyntheticTree::TREE . "\n"];

                    return $tree;
                },
                [[FailureClass::PATH_LEAK, 'reference / case:alpha|format:github', 'SurfaceComparison::checkPathLeaks']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:github']],
            ),
            self::witness(
                'single-producer',
                self::WHOLE_RUN,
                static fn(array $tree): array => self::withCase($tree, 'beta', 'replay.alpha', declared: true),
                [[FailureClass::COVERAGE_MULTIPLICITY, 'corpus', 'CoverageCheck::checkSingleProducer']],
            ),
            self::witness(
                'finding-key-set',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'gamma', 'replay.gamma', declared: true);
                    $tree['findings']['gamma'][0]['unpublished'] = 'a key the tuple does not name';

                    return $tree;
                },
                [[FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / gamma / finding #0', 'TupleCheck::checkTupleAgainstFindings']],
                [[FailureClass::FINDING_TUPLE_MISMATCH, 'reference / gamma / finding #0']],
            ),
            self::witness(
                'coverage-surplus',
                self::WHOLE_RUN,
                static fn(array $tree): array => self::withCase($tree, 'delta', 'replay.undeclared', declared: false),
                [[FailureClass::COVERAGE_SURPLUS, 'corpus', 'ChannelCoverage::reportSurplus']],
            ),
            self::witness(
                'witness-disagreement',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['fixture']['replay.fixture-only'] = ['class'];

                    return $tree;
                },
                [[FailureClass::WITNESS_DISAGREEMENT, 'governance/Channel/Fixtures/declared.txt', 'ChannelWitness::checkAgreement']],
            ),
            self::witness(
                'level-vocabulary-drift',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['levels'][] = 'replayed-level';

                    return $tree;
                },
                [[FailureClass::LEVEL_VOCABULARY_DRIFT, 'scripts/finding-gate/SubjectLevel.php', 'ChannelWitness::checkLevelVocabulary']],
            ),
            self::witness(
                'report-payload-unreadable',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:html'] = ['stdout' => "<html>no payload</html>\n"];

                    return $tree;
                },
                [[FailureClass::REPORT_PAYLOAD_UNREADABLE, 'case:alpha|format:html', 'SurfaceComparison::compareSurfaces#2']],
            ),
            self::witness(
                'run-failed',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:sarif'] = ['stdout' => "not json\n"];

                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'candidate / alpha / format:sarif', 'FingerprintCheck::decodeFingerprintSurface']],
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
                [[FailureClass::RUN_FAILED, '* / omega', 'CaseOutcomeCheck::findingsOf#1']],
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
                [[FailureClass::RUN_FAILED, '* / alpha', 'CaseOutcomeCheck::findingsOf#2']],
            ),
            self::witness(
                'baseline-exit',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|baseline-file'] = ['stdout' => "{\"replayed\": \"alpha\"}\n", 'exit' => 1];

                    return $tree;
                },
                [[FailureClass::RUN_FAILED, '* / alpha / baseline:generate', 'CaseOutcomeCheck::checkBaselineSurface#1']],
            ),
            self::witness(
                'baseline-empty',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree = self::withCase($tree, 'eta', 'replay.eta', declared: true);
                    $tree['answers']['case:eta|baseline-file'] = ['stdout' => ''];

                    return $tree;
                },
                [[FailureClass::RUN_FAILED, '* / eta / baseline-file', 'CaseOutcomeCheck::checkBaselineSurface#2']],
            ),
            self::witness(
                'reference-input',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:text-verbose'] = ['stdout' => "replayed text-verbose of alpha\n", 'exit' => 3];
                    $tree['candidateAnswers']['case:alpha|format:text-verbose'] = ['stdout' => "replayed text-verbose of alpha\n"];

                    return $tree;
                },
                [[FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'reference / case:alpha', 'RenameMapCheck::checkReferenceInput']],
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|exit:format:text-verbose']],
            ),
            self::witness(
                'map-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['maps']['symbols'][] = "Replay\\Nowhere\tReplay\\Elsewhere\tself-test";

                    return $tree;
                },
                [[FailureClass::MAP_STALE, '*Replay\Nowhere*', 'RenameMapCheck::checkStaleMaps']],
            ),
            self::witness(
                'split-unmapped',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['maps']['channels'][] = "replay.alpha#replay.never-one\treplay.split-one#replay.split-one\tself-test";
                    $tree['maps']['channels'][] = "replay.alpha#replay.never-two\treplay.split-two#replay.split-two\tself-test";

                    return $tree;
                },
                [[FailureClass::SPLIT_UNMAPPED, 'case:alpha', 'RenameMapCheck::checkSplitExplanation']],
                [[FailureClass::MAP_STALE, '*replay.never-*']],
            ),
            self::witness(
                'case-claim',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['cases']['alpha'][] = SubjectLevel::claim('replay.alpha', 'class');

                    return $tree;
                },
                [[FailureClass::CASE_CLAIM_MISMATCH, 'case:alpha', 'CoverageCheck::checkCaseClaim']],
            ),
            self::witness(
                'coverage-shortfall',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['static']['replay.unfired'] = ['callable'];
                    $tree['fixture']['replay.unfired'] = ['callable'];

                    return $tree;
                },
                [[FailureClass::COVERAGE_SHORTFALL, 'corpus', 'ChannelCoverage::reportShortfall']],
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
                [[FailureClass::FINGERPRINT_MISMATCH, '* / alpha / sarif partialFingerprints', 'FingerprintCheck::checkFingerprints']],
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
                [[FailureClass::FINGERPRINT_OPAQUE, '* / case:alpha|format:gitlab', 'FingerprintCheck::substituteFingerprints']],
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
                [[FailureClass::PUBLISHED_ORDER_DRIFT, 'case:alpha|baseline-file', 'SurfaceComparison::checkPublishedOrder']],
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
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|stderr:format:github', 'SurfaceComparison::compareSurfaces#1']],
            ),
            self::witness(
                'surface-differs',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:checkstyle'] = ['stdout' => "another checkstyle of alpha\n"];

                    return $tree;
                },
                [[FailureClass::SURFACE_MISMATCH, 'case:alpha|format:checkstyle', 'DeclaredDeltaCheck::checkDifference']],
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
                [[FailureClass::FINDING_COUNT_MISMATCH, 'case:zeta', 'SurfaceComparison::compareFindingCounts']],
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
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|format:summary', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#3']],
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
                [[FailureClass::DELTA_TOO_LARGE, 'case:alpha|format:text-verbose', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#1']],
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
                [[FailureClass::DELTA_OVERREACH, 'case:alpha|format:json', 'DeclaredDeltaCheck::checkAgainstDeclaredDelta#2']],
                [[FailureClass::DELTA_MISMATCH, 'case:alpha|format:json']],
            ),
            self::witness(
                'delta-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['declaredDelta']['case:alpha|format:health'] = self::NO_DIFF;

                    return $tree;
                },
                [[FailureClass::DELTA_STALE, 'case:alpha|format:health', 'DeclaredDeltaCheck::checkStaleDeclaredDelta']],
            ),
            self::witness(
                'field-move-stale',
                self::DECLARATIONS,
                static function (array $tree): array {
                    $tree['fieldMoves'][] = ['case:alpha|format:json', 'message', 'nowhere', 'elsewhere'];

                    return $tree;
                },
                [[FailureClass::FIELD_MOVE_STALE, 'case:alpha|format:json', 'DeclaredDeltaCheck::checkStaleFieldMoves']],
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
     * The failures a whole run reported, each with the site that raised it, or
     * why it did not finish.
     *
     * @param Specification $specification
     * @param array<string, string> $siteAt `file:line` => site
     *
     * @return list<Failure>|string
     */
    private static function run(array $specification, array $siteAt): array|string
    {
        $root = SyntheticTree::create($specification);
        $gate = null;

        try {
            $report = new GateReport();
            $gate = new Gate(Options::parse(['self-test', '--candidate=' . $root, '--reference=HEAD', '--jobs=4'], $root), $report);
            $gate->compare();
            $written = $root . '/replay/report.json';
            $report->writeJson($written);
            $decoded = json_decode(Fs::read($written), true, 512, \JSON_THROW_ON_ERROR);
            $failures = [];
            $raised = $report->raised();

            foreach (\is_array($decoded) && \is_array($decoded['failures'] ?? null) ? $decoded['failures'] : [] as $index => $failure) {
                $where = isset($raised[$index]) ? $raised[$index]['file'] . ':' . $raised[$index]['line'] : '?';
                $failures[] = [
                    'class' => (string) ($failure['class'] ?? ''),
                    'scope' => (string) ($failure['scope'] ?? ''),
                    'detail' => (string) ($failure['detail'] ?? ''),
                    'site' => $siteAt[$where] ?? $where,
                ];
            }

            if ($failures === [] && $report->exitCode() !== GateReport::EXIT_GREEN) {
                return 'the run reported no failure and still exited ' . $report->exitCode();
            }

            return $failures;
        } catch (GateError $error) {
            return $error->getMessage();
        } finally {
            $gate?->cleanUp();
            SyntheticTree::remove($root);
        }
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
                        'check witness %s: the %s run raised no %s @ %s from %s. It raised: %s',
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
                    'check witness %s run: %s @ %s from %s is named by no witness of that run: %s',
                    $scenario,
                    $failure['class'],
                    $failure['scope'],
                    $failure['site'],
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
                && (!isset($pattern[2]) || $failure['site'] === $pattern[2])
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
            static fn(array $failure): string => $failure['class'] . ' @ ' . $failure['scope'] . ' from ' . $failure['site'],
            $failures,
        ));
    }
}
