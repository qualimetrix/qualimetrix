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
 * Each witness names the failures its plant must raise (`expect`) and the side
 * effects it may raise (`tolerate`, glob patterns). A run is held to both
 * directions: an expected failure missing, a failure nothing names and a
 * toleration nothing used are each red. The clean tree underneath all of them
 * must be GREEN, or every observation below could be the stand's own defect.
 *
 * @phpstan-import-type Specification from SyntheticTree
 *
 * @phpstan-type Pattern array{0: string, 1: string}
 * @phpstan-type Witness array{
 *     id: string,
 *     scenario: string,
 *     plant: callable(Specification): Specification,
 *     expect: list<Pattern>,
 *     tolerate: list<Pattern>,
 * }
 * @phpstan-type Failure array{class: string, scope: string, detail: string}
 */
final class CheckWitnesses
{
    /** `env-mismatch` returns before the reference is run, so what it stops cannot share its run. */
    private const string BEFORE_THE_REFERENCE = 'pre-reference';

    private const string WHOLE_RUN = 'whole';

    /**
     * `observed` holds only the classes an expectation actually matched.
     *
     * @return array{failures: list<string>, observed: list<string>}
     */
    public static function observe(): array
    {
        $failures = [];
        $observed = [];

        $clean = self::run(SyntheticTree::clean());

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

            $run = self::run($specification);

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
                [[FailureClass::ENV_MISMATCH, 'reference tree']],
            ),
            self::witness(
                'tuple-drift',
                self::BEFORE_THE_REFERENCE,
                static function (array $tree): array {
                    $tree['tuple'] = array_values(array_diff($tree['tuple'], ['message']));

                    return $tree;
                },
                [[FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH]],
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
                [[FailureClass::TUPLE_FIELD_DRIFT, EquivalenceTuple::TRACKED_PATH]],
            ),
            self::witness(
                'determinism.differs',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['candidateAnswers']['case:alpha|format:text'] = ['stdout' => 'replayed text ' . SyntheticTree::RANDOM . "\n"];

                    return $tree;
                },
                [[FailureClass::NONDETERMINISM_UNDECLARED, 'case:alpha|format:text']],
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
                [[FailureClass::NORMALIZATION_OVERREACH, 'candidate / case:alpha|format:json']],
                [[FailureClass::NORMALIZATION_OVERREACH, '* / case:*|format:json']],
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
                    $tree['answers']['case:alpha|format:github'] = ['stdout' => 'replayed github from ' . SyntheticTree::TREE . "\n"];

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
                [[FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / gamma / finding #0']],
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
                'report-payload-unreadable',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:html'] = ['stdout' => "<html>no payload</html>\n"];

                    return $tree;
                },
                [[FailureClass::REPORT_PAYLOAD_UNREADABLE, 'case:alpha|format:html']],
            ),
            self::witness(
                'run-failed',
                self::WHOLE_RUN,
                static function (array $tree): array {
                    $tree['answers']['case:alpha|format:sarif'] = ['stdout' => "not json\n"];

                    return $tree;
                },
                [[FailureClass::RUN_FAILED, 'candidate / alpha / format:sarif']],
                [[FailureClass::RUN_FAILED, 'reference / alpha / format:sarif']],
            ),
        ];
    }

    /**
     * @param callable(Specification): Specification $plant
     * @param list<Pattern> $expect
     * @param list<Pattern> $tolerate
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
     * The failures a whole run reported, or why it did not finish.
     *
     * @param Specification $specification
     *
     * @return list<Failure>|string
     */
    private static function run(array $specification): array|string
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

            foreach (\is_array($decoded) && \is_array($decoded['failures'] ?? null) ? $decoded['failures'] : [] as $failure) {
                $failures[] = [
                    'class' => (string) ($failure['class'] ?? ''),
                    'scope' => (string) ($failure['scope'] ?? ''),
                    'detail' => (string) ($failure['detail'] ?? ''),
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
     * @param Pattern $pattern
     * @param list<Failure> $failures
     *
     * @return list<int> the indexes of the failures it matches
     */
    private static function matching(array $pattern, array $failures): array
    {
        $matched = [];

        foreach ($failures as $index => $failure) {
            if ($failure['class'] === $pattern[0] && fnmatch($pattern[1], $failure['scope'], \FNM_NOESCAPE)) {
                $matched[] = $index;
            }
        }

        return $matched;
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
}
