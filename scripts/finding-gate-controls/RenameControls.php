<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\CaseOutcome;
use QmxFindingGate\Corpus;
use QmxFindingGate\DeclaredValues;
use QmxFindingGate\FailureClass;

/**
 * The controls on the declared maps: a rename without its row, a row that explains nothing, and every kind
 * of name a row translates — channels, split halves, aggregated spellings, root keys, report values, case
 * inputs.
 */
final class RenameControls
{
    /** A published JSON finding channel moves; producer declarations and Population stay unchanged. */
    public static function renameWithoutMap(): Control
    {
        return Control::red(
            'rename-no-map',
            'a JSON finding channel is renamed with no channels.tsv row naming it',
            ChannelRenamePlants::publishedLcomChannelMutation()->and(self::oldLcomValueDeclarations()),
            [
                ...array_map(static fn(string $scope): Expectation => new Expectation(FailureClass::RECORD_UNDECLARED, $scope, exactScope: true), [
                    'case:complexity|format:json', 'case:detectors|format:json',
                ]),
                ...array_map(static fn(string $scope): Expectation => new Expectation(FailureClass::SURFACE_MISMATCH, $scope, exactScope: true), [
                    'case:complexity|format:json', 'case:complexity|check:output:file',
                    'case:detectors|format:json', 'case:detectors|check:output:file',
                ]),
                new Expectation(FailureClass::COVERAGE_SHORTFALL, 'corpus', exactScope: true),
                new Expectation(FailureClass::COVERAGE_SURPLUS, 'corpus', exactScope: true),
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:complexity', exactScope: true),
                new Expectation(FailureClass::CASE_CLAIM_MISMATCH, 'case:detectors', exactScope: true),
            ],
        );
    }

    /** Value declarations for the old record identity cannot apply after this rename. */
    private static function oldLcomValueDeclarations(): Mutation
    {
        $values = DeclaredValues::load(\dirname(__DIR__, 2) . '/finding-gate');
        $replacements = [];

        foreach ($values->derived() as $row) {
            if ($row['kind'] !== DeclaredValues::FIELD || !str_contains($row['subject'], '|record:')) {
                continue;
            }

            if (!str_starts_with($row['subject'], 'case:complexity|format:json|record:')
                && !str_starts_with($row['subject'], 'case:detectors|format:json|record:')) {
                continue;
            }

            [, $recordKey] = explode('|record:', $row['subject'], 2);
            $record = json_decode($recordKey, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($record) || ($record['channel'] ?? null) !== 'cohesion.lcom') {
                continue;
            }

            $line = implode("\t", array_values($row));
            $replacements[$line . "\n"] = '';
        }

        return $replacements === []
            ? Mutation::none()
            : Mutation::edit(
                'finding-gate/' . DeclaredValues::DERIVED,
                $replacements,
                'old-identity value declarations do not apply after this channel rename',
            );
    }

    /**
     * An untranslatable selector makes the reference refuse an authoritative
     * analysis input. The gate preserves that refusal's input diagnosis
     * instead of treating it as missing ranking metadata.
     */
    public static function referenceInputUntranslated(): Control
    {
        return Control::red(
            'reference-input',
            'a case input that needs translating, with no inputs.tsv row to translate it',
            Mutation::edit(
                'src/Analysis/Policy/Architecture/Contract/ArchitectureChannels.php',
                [
                    "POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shadow';"
                        => "POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shado2';",
                ],
                'the channel architecture.potential-shadow is renamed, and the producing rule is left alone',
            )->and(Mutation::edit(
                'finding-gate/cases/disabled-rule/case.json',
                [
                    // The trailing bracket is part of the fragment on purpose: a
                    // Mutation may not leave its own anchor behind, so an added
                    // line has to consume something. The comma is what changes.
                    "\"--disable-rule=code-smell.eval\"\n    ],"
                        => "\"--disable-rule=code-smell.eval\",\n        \"--disable-rule=architecture.potential-shado2\"\n    ],",
                ],
                'the auxiliary selector case addresses the new channel name',
            ))->and(Mutation::edit(
                'finding-gate/cases/layers/case.json',
                ['"architecture.potential-shadow@project"' => '"architecture.potential-shado2@project"'],
                'the case that fires the channel claims it under its new name',
            )),
            [new Expectation(FailureClass::REFERENCE_INPUT_UNTRANSLATED, 'reference / case:disabled-rule', exactScope: true)],
        );
    }

    /** A JSON producer move credits its live split row; a second unobserved row stays stale. */
    public static function splitRowIdle(): Control
    {
        return Control::red(
            'split-row-idle',
            'a declared JSON producer move explains records while another row of its split explains none',
            ChannelRenamePlants::publishedUnusedPrivateProducerMutation()
                ->and(ChannelRenamePlants::trackedChannelMapPlus(
                    [
                        "code-smell.unused-private#code-smell.unused-private\t"
                            . "code-smell.unused-privat2#code-smell.unused-private\t"
                            . 'the JSON producer moves while its finding channel and code stay unchanged',
                        "code-smell.unused-private#code-smell.never-emitted\t"
                            . "code-smell.unused-privat3#code-smell.never-emitted\t"
                            . 'this split row names no published finding',
                    ],
                    'one producer split row explains records and the other is idle',
                )),
            [
                new Expectation(
                    FailureClass::MAP_STALE,
                    'channels.tsv: "code-smell.unused-private#code-smell.never-emitted" -> "code-smell.unused-privat3#code-smell.never-emitted"',
                    exactScope: true,
                ),
                ...array_map(static fn(string $scope): Expectation => new Expectation(FailureClass::RECORD_UNDECLARED, $scope, exactScope: true), [
                    'case:smells|format:json', 'case:detectors-smells|format:json', 'case:detectors|format:json',
                ]),
                ...array_map(static fn(string $scope): Expectation => new Expectation(FailureClass::SURFACE_MISMATCH, $scope, exactScope: true), [
                    'case:smells|format:json', 'case:smells|check:output:file',
                    'case:detectors-smells|format:json', 'case:detectors-smells|check:output:file',
                    'case:detectors|format:json', 'case:detectors|check:output:file',
                ]),
            ],
        );
    }

    /**
     * A published aggregated spelling moves, and the base key stays exactly
     * where it is.
     *
     * The control on the suffix expansion. A `metric-keys.tsv` row translates
     * `<key>.<strategy>` as well as `<key>`, which is what makes 212 published
     * spellings declarable in 83 rows — and also what could make the expansion a
     * rubber stamp, absorbing a movement in the suffix that no row states. Only
     * the suffix moves here, and it moves for every key that carries it: `cbo`,
     * `ccn` and `cbo_app` stay exactly as they are on every surface, while
     * `cbo.p95` is published as `cbo.pct95`. No row declares that, so it has to
     * be red.
     *
     * The mutation moves the spelling where it is PUBLISHED, and that is the
     * point rather than a convenience. Measured 2026-08-26: changing the
     * separator in `MetricName::agg()` instead — the composition every writer and
     * every reader shares — takes the product down altogether (`run-failed` on
     * all fourteen cases, "the JSON surface carries no findings section", zero
     * observed channels), because the aggregated name is also how the
     * aggregation reads its own weights back. A control that kills the product
     * proves the corpse differs, not that the gate compares metric keys.
     *
     * The dot is kept, and that is the difference between this control and a
     * sloppier one: dropping it (`cbopct95`) would move the boundary as well as
     * the suffix, and then the control would no longer be about a suffix at all.
     * It read `cbopct95` when this control was first written, and two reviewers
     * found it before a run did.
     *
     * `p95` is the strategy moved, and no value changes with it: the built-in
     * health formulas read `coupling.cbo.p95` and `complexity.cognitive.p95` out
     * of the metric bag,
     * not out of this formatter, so the findings, the counts, the claims and the
     * baselines are all identical on both sides. What differs is one published
     * name on one surface — which is exactly the difference the suffix expansion
     * could otherwise absorb.
     */
    public static function movedAggregatedSpelling(): Control
    {
        return Control::red(
            'moved-aggregated-spelling',
            'a published aggregated spelling moves while its base key stays put',
            Mutation::edit(
                'src/Reporting/Formatter/MetricsJsonFormatter.php',
                [
                    "'metrics' => \$metricsArray," => "'metrics' => array_combine(array_map("
                        . "static fn(string \$key): string => str_ends_with(\$key, '.p95')"
                        . " ? substr(\$key, 0, -4) . '.pct95' : \$key,"
                        . " array_keys(\$metricsArray)), \$metricsArray),",
                ],
                'the metrics surface publishes "<key>.pct95" where the product computed "<key>.p95"',
            ),
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'case:complexity|format:metrics'),
                ...self::aggregateValueFailures()],
            [new Expectation(FailureClass::SURFACE_MISMATCH, 'format:metrics'),
                ...Controls::oversizedDeclaredSurfaces([
                    'case:coupling|format:metrics', 'case:design|format:metrics',
                    'case:drill-down|format:metrics', 'case:layers|format:metrics',
                ]),
                ...Controls::valueToleration()],
        );
    }

    /**
     * A value comparison requires records on both sides. The UTF-8 transition's
     * declared whole metrics publication is held to its exact delta; an identity
     * comparison retains the native record-value obligations.
     *
     * @return list<Expectation>
     */
    private static function aggregateValueFailures(): array
    {
        $required = [];
        foreach (\QmxFindingGate\Corpus::load(\dirname(__DIR__, 2))->cases as $case) {
            if ($case->id === 'utf8-identifier'
                && Controls::hasSurfaceDeclaration('case:utf8-identifier|format:metrics')) {
                $required[] = Controls::changedSurface('case:' . $case->id . '|format:metrics');
                continue;
            }
            if (CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($case, 'reference'))
                && CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($case, 'candidate'))) {
                $required[] = new Expectation(FailureClass::VALUE_MISMATCH, 'case:' . $case->id . '|format:metrics|record:');
            }
        }
        return $required;
    }

    /**
     * The concrete namespace-suppression root renamed through its declaration,
     * consumer key and every corpus document writing that canonical root.
     *
     * Resolved readers use canonical document keys; preserving the former flat
     * result key would drop the renamed value rather than preserve suppression.
     * The per-rule key of the same spelling remains a separate vocabulary.
     */
    public static function rootKeyRenamed(): Control
    {
        return Control::greenWith(
            'root-key-renamed',
            'the declared suppress_namespaces root and its consumer key are renamed, translated by their document spelling',
            self::rootKeyMutation()->and(ChannelRenamePlants::trackedMapPlus(
                'inputs.tsv',
                ["suppress_namespaces:\tsuppress_ns:\tthe root configuration key's document spelling is renamed"],
                "the step's own rows, plus the row that declares this control's renamed root key",
            )),
        );
    }

    /** The rename {@see rootKeyRenamed()} declares and {@see reportValueWithoutRow()}'s sibling controls do not need. */
    private static function rootKeyMutation(): Mutation
    {
        return Mutation::edit(
            'src/Analysis/Configuration/ConfigSchema.php',
            [
                "public const string SUPPRESS_NAMESPACES = 'suppress_namespaces';" => "public const string SUPPRESS_NAMESPACES = 'suppress_ns';",
                "['suppressNamespaces', self::SUPPRESS_NAMESPACES, self::LIST, null]," => "['suppressNs', self::SUPPRESS_NAMESPACES, self::LIST, null],",
                "'suppressNamespaces' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE," => "'suppressNs' => SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE,",
            ],
            "the namespace-suppression source path, normalization policy and resolved reader key are renamed together",
        )->and(Mutation::edit(
            'src/Analysis/Configuration/ConfigurationRoot.php',
            ["case SuppressNamespaces = 'suppress_namespaces';" => "case SuppressNamespaces = 'suppress_ns';"],
            'the same canonical root declaration retains its selector-set schema',
        ))->and(Mutation::renameRootKeyInCorpus(
            'suppress_namespaces',
            'suppress_ns',
            'every case addressing the root key writes the new name at root indent; the per-rule key of the same'
                . ' spelling, nested under rules:, is untouched',
        ));
    }

    /**
     * A suppressed report value renamed in product code, translated by
     * `report-values.tsv`'s quoted-only substitution for JSON record pairs.
     * A declared whole UTF-8 publication retains its exact surface comparison.
     *
     * `SuppressionMechanism::NamespaceSuppression` is renamed rather than a
     * mechanism the corpus actually fires, because the declaration does not need
     * one: {@see \Qualimetrix\Reporting\Formatter\Suppressed\SuppressedFormatter}
     * prints every mechanism's value in `mechanisms` and as a key of
     * `byMechanism` in every case's `format:suppressed` surface, whether or not
     * that case's own suppressions ever use it — so the row fires everywhere and
     * cannot go stale.
     */
    public static function reportValueRenamed(): Control
    {
        $mutation = self::reportValueMutation()->and(ChannelRenamePlants::trackedMapPlus(
            'report-values.tsv',
            ["namespace-suppression\tnamespace-block\tthe control renames the mechanism value"],
            'a report-values row declaring the control\'s renamed value',
        ));
        $scope = 'case:utf8-identifier|format:suppressed';
        if (!Controls::hasSurfaceDeclaration($scope)) {
            return Control::greenWith(
                'report-value-renamed',
                'a suppressed report value is translated by its declared report-values row',
                $mutation,
            );
        }
        return Control::red(
            'report-value-renamed',
            'a suppressed report value is translated in JSON record pairs but changes a declared whole UTF-8 publication',
            $mutation,
            [Controls::changedSurface($scope)],
        );
    }

    /** The rename {@see reportValueRenamed()} declares and {@see reportValueWithoutRow()} leaves undeclared. */
    private static function reportValueMutation(): Mutation
    {
        return Mutation::edit(
            'src/Reporting/FindingProjection/SuppressionMechanism.php',
            ["case NamespaceSuppression = 'namespace-suppression';" => "case NamespaceSuppression = 'namespace-block';"],
            'the NamespaceSuppression report value is renamed',
        );
    }

    /**
     * The same report-value rename as {@see reportValueRenamed()}, with no
     * `report-values.tsv` row — the class no control had watched fire for this
     * map.
     *
     * The blast radius is every published `format:suppressed` surface, and only
     * that surface: `mechanisms` and `byMechanism` print every value in every
     * case regardless of whether that case's own findings were ever suppressed
     * by it. Early refusals publish no mechanism vocabulary. A populated
     * suppression record also carries the renamed mechanism.
     */
    public static function reportValueWithoutRow(): Control
    {
        return Control::red(
            'report-value-no-row',
            'a suppressed report value renamed with no report-values.tsv row naming it',
            self::reportValueMutation(),
            [new Expectation(FailureClass::RECORD_UNDECLARED, 'case:rule-exclusion-ledger|format:suppressed', exactScope: true),
                ...self::surfaceMismatchOnEverySuppressedFormat()],
        );
    }

    /**
     * Record-paired suppressed publications come from the corpus. A declared
     * whole UTF-8 publication uses its exact delta; the drill-down early
     * selector refusal publishes no mechanism vocabulary.
     *
     * @return list<Expectation>
     */
    private static function surfaceMismatchOnEverySuppressedFormat(): array
    {
        $required = [];

        foreach (Corpus::load(\dirname(__DIR__, 2))->cases as $case) {
            if ($case->id === 'drill-down') {
                continue;
            }
            if ($case->id === 'utf8-identifier') {
                $required[] = Controls::changedSurface('case:utf8-identifier|format:suppressed');
                continue;
            }
            if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($case, 'reference'))
                || !CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($case, 'candidate'))) {
                continue;
            }

            $required[] = new Expectation(FailureClass::SURFACE_MISMATCH, 'case:' . $case->id . '|format:suppressed', exactScope: true);
        }

        return $required;
    }
}
