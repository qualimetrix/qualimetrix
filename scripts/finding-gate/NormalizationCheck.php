<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What the normalization list may redact and whether it covers everything that varies: it never touches a
 * field the tuple compares, two candidate runs agree after it, and every row still fires.
 */
final class NormalizationCheck
{
    public function __construct(
        private readonly Options $options,
        private readonly GateReport $report,
        private readonly Normalization $normalization,
    ) {}

    /**
     * Normalization and the tuple must not overlap.
     *
     * The two contracts pull in opposite directions: the tuple says which
     * published fields are compared, normalization says which fields are not.
     * A row that names a compared field would delete that comparison silently,
     * which is the same hole as a map row that erases evidence instead of
     * translating it. The measured deriver cannot emit such a row, but the tsv
     * is editable by hand, and that is what this guard is for.
     */
    public function checkNormalizationScope(): void
    {
        $fields = EquivalenceTuple::load($this->options->candidateRoot)->fields;

        foreach ($this->normalization->rules() as $rule) {
            if ($rule->kind === NormalizationRule::KIND_LINE_REGEX) {
                continue;
            }

            $segments = explode('.', $rule->locator);
            $last = end($segments);

            if (!\in_array($last, $fields, true)) {
                continue;
            }

            $this->report->fail(
                FailureClass::NORMALIZATION_OVERREACH,
                \sprintf('%s / %s', $rule->surface, $rule->locator),
                \sprintf(
                    'This rule redacts "%s", which the equivalence tuple compares. Excluding a compared field would'
                    . ' retire it from the comparison while the tuple still claims it is guarded.',
                    $last,
                ),
            );
        }
    }

    /**
     * The same property, measured rather than read off the locators.
     *
     * A locator does not have to name a tuple field to reach one: a row on
     * `violations` alone would take the whole findings section out of the
     * comparison, and a line-regex row cannot be judged statically at all. So
     * the surface where the tuple is defined is normalized and its findings
     * section compared against the raw one.
     *
     * @param list<array<string, mixed>> $findings
     */
    public function checkNormalizationLeavesFindings(string $side, CaseDefinition $case, string $artifact, array $findings): void
    {
        $key = Surfaces::key('case:' . $case->id, 'format:json');
        $normalized = json_decode($this->normalization->normalize(Surfaces::surfaceClass($key), $artifact), true);
        $after = \is_array($normalized) && \is_array($normalized['violations'] ?? null)
            ? array_values($normalized['violations'])
            : null;

        if ($after === $findings) {
            return;
        }

        $this->report->fail(
            FailureClass::NORMALIZATION_OVERREACH,
            $side . ' / ' . $key,
            'Normalization changes the published findings of this surface, so a field the tuple compares is being'
            . ' redacted before the two sides are compared.',
        );
    }

    /**
     * @param array<string, string> $first
     * @param array<string, string> $second
     */
    public function checkDeterminism(array $first, array $second): void
    {
        // The union, not run 1's keys: a surface that only one run produces at
        // all — the conditional `stderr:` key is exactly that shape — would
        // otherwise be compared by nothing.
        foreach (array_keys($first + $second) as $key) {
            $surface = Surfaces::surfaceClass($key);

            if (!isset($first[$key]) || !isset($second[$key])) {
                $this->report->fail(
                    FailureClass::NONDETERMINISM_UNDECLARED,
                    $key,
                    \sprintf('Only run %d of the candidate tree produced this surface at all.', isset($first[$key]) ? 1 : 2),
                );

                continue;
            }

            $left = $this->normalization->normalize($surface, $first[$key]);
            $right = $this->normalization->normalize($surface, $second[$key]);

            if ($left !== $right) {
                $this->report->fail(
                    FailureClass::NONDETERMINISM_UNDECLARED,
                    $key,
                    'Two runs of the candidate tree differ after normalization, so the normalization list does not'
                    . ' cover everything that varies. Measure it again with --derive-normalization.',
                    Diff::between($left, $right, 'run 1', 'run 2'),
                );
            }
        }
    }

    public function checkStaleNormalization(): void
    {
        foreach ($this->normalization->staleRules() as $rule) {
            $this->report->fail(
                FailureClass::NORMALIZATION_STALE,
                \sprintf('%s / %s', $rule->surface, $rule->locator),
                'This normalization rule matched nothing in the whole run. An exclusion nobody can point at is how'
                . ' the list grows into a blanket, so it fails until it is either justified or removed.',
            );
        }
    }

    /**
     * Measures the normalization list, and keeps a tracked row that still fires.
     *
     * The union is what makes re-measuring reproducible. Rounded to one decimal,
     * the summary line's duration crosses a boundary on some runs and not on
     * others, so a purely measured list would gain and lose that row at random —
     * and a list whose shape changes every time it is measured is not a measured
     * list either. Both properties the plan demands survive: a row still enters
     * only by measurement, and a row that fires nowhere is stale and leaves.
     *
     * @param list<array<string, string>> $passes
     */
    public function measured(array $passes): string
    {
        foreach ($passes[0] as $key => $content) {
            $this->normalization->normalize(Surfaces::surfaceClass($key), $content);
        }

        return Normalization::fromRules(self::unique([
            ...$this->normalization->activeRules(),
            ...NormalizationDeriver::derive($passes),
        ]))->render();
    }

    /**
     * @param list<NormalizationRule> $rules
     *
     * @return list<NormalizationRule>
     */
    private static function unique(array $rules): array
    {
        $unique = [];

        foreach ($rules as $rule) {
            $unique[$rule->surface . "\0" . $rule->locator . "\0" . $rule->kind] = $rule;
        }

        return array_values($unique);
    }
}
