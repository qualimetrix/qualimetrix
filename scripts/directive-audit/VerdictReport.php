<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

/**
 * The audit's JSON report, read once, in one place.
 *
 * Two scripts judge this report — the CI floor and the narrow/full comparison —
 * and until this class existed they read it twice: the same
 * `effect !== 'unmeasured'` condition byte for byte in both, and each with its
 * own idea of which fields were optional. Fixing one of them would have moved
 * the pair apart without either saying so.
 *
 * So the whole report lives here, not only the part the floor needs: the
 * comparison also reads `scope`, `selection`, `exit_code` and the entries
 * themselves, and a library that handed back only threshold sites would leave
 * it with a second `json_decode` and the single reading only half done.
 *
 * What is read once is the *report*. Both callers still ask one question of
 * their own before this class is constructed — whether the command emitted any
 * JSON at all — because its answer is the command's own exit code rather than
 * a statement about a report, and asking it here would turn a run that died
 * before writing anything into a malformed report.
 *
 * `directives[]` is validated eagerly, including every verdict's membership in
 * {@see MeasuredEffects::TABLE}; `scope`/`selection`/`sweep`/`exit_code` are
 * validated on access, because only one caller reads those and a floor that
 * refused a report over a field it never consults would be refusing on someone
 * else's behalf.
 */
final readonly class VerdictReport
{
    /**
     * @param array<mixed, mixed> $decoded
     * @param list<AuditedVerdict> $verdicts
     * @param list<array<string, mixed>> $rawVerdicts entries as published, for a verdict-by-verdict comparison
     */
    private function __construct(
        private array $decoded,
        private bool $errorEnvelope,
        private array $verdicts,
        private array $rawVerdicts,
    ) {}

    /** @throws AuditReportError */
    public static function fromJson(string $stdout): self
    {
        $decoded = json_decode($stdout, true);

        if (!\is_array($decoded)) {
            throw new AuditReportError('The audit produced no JSON object.');
        }

        // One decision, read by two questions: whether to validate the
        // verdicts, and whether the caller is looking at a report at all. Two
        // spellings of it would be two chances to disagree, which is the defect
        // this class exists to remove.
        $errorEnvelope = isset($decoded['error']);

        if ($errorEnvelope) {
            return new self($decoded, true, [], []);
        }

        $directives = self::directiveListOf($decoded);
        $verdicts = [];
        $raw = [];

        foreach ($directives as $index => $row) {
            if (!\is_array($row)) {
                throw new AuditReportError(\sprintf('directives[%d] is not an object.', $index));
            }

            /** @var array<string, mixed> $row */
            $verdict = AuditedVerdict::fromRow($row, $index);
            MeasuredEffects::requireNamed($verdict->effect);

            $verdicts[] = $verdict;
            $raw[] = $row;
        }

        return new self($decoded, false, $verdicts, $raw);
    }

    /** An `{"error": …}` envelope carries no measurement, so there is nothing here to judge. */
    public function isErrorEnvelope(): bool
    {
        return $this->errorEnvelope;
    }

    /**
     * What the envelope says went wrong, for a caller that has to report it.
     *
     * The text is the whole content of an envelope, so a caller that only said
     * "failed before producing a report" would have thrown away the one thing
     * the run managed to produce.
     */
    public function errorText(): string
    {
        $error = $this->decoded['error'] ?? null;

        return \is_string($error) ? $error : var_export($error, true);
    }

    /**
     * The exit code the report states, which is not the one the process
     * returned: a disagreement between the two is the point of reading it.
     *
     * @throws AuditReportError
     */
    public function exitCode(): int
    {
        $value = $this->decoded['exit_code'] ?? null;

        if (!\is_int($value)) {
            throw new AuditReportError(\sprintf('"exit_code" must be an integer, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @throws AuditReportError
     *
     * @return array<mixed, mixed>
     */
    public function scope(): array
    {
        return $this->section('scope');
    }

    /**
     * @throws AuditReportError
     *
     * @return array<mixed, mixed>
     */
    public function selection(): array
    {
        return $this->section('selection');
    }

    /** @throws AuditReportError */
    public function sweep(): string
    {
        $value = $this->decoded['sweep'] ?? null;

        if (!\is_string($value)) {
            throw new AuditReportError(\sprintf('"sweep" must be a string, got %s.', get_debug_type($value)));
        }

        return $value;
    }

    /** @return list<AuditedVerdict> */
    public function verdicts(): array
    {
        return $this->verdicts;
    }

    /** @return list<AuditedVerdict> */
    public function thresholdVerdicts(): array
    {
        return array_values(array_filter(
            $this->verdicts,
            static fn(AuditedVerdict $verdict): bool => $verdict->isThreshold(),
        ));
    }

    /**
     * Entries as published, keyed by the site identity they are about.
     *
     * The raw entry rather than {@see AuditedVerdict}: a comparison of two runs
     * is about every field the audit publishes, including the ones this library
     * has no opinion on, and a comparison of the fields it happens to model
     * would go green on a difference in the rest.
     *
     * A list per site, not an entry per site: two entries can share
     * `file:line:form:target`, and a map that kept the last of them would drop
     * the other before any comparison saw it — the same collapse
     * {@see Population::diff()} refuses to make one field away from here.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function rawVerdictsBySite(): array
    {
        $bySite = [];

        foreach ($this->verdicts as $index => $verdict) {
            $bySite[$verdict->keyedSite()][] = $this->rawVerdicts[$index];
        }

        return $bySite;
    }

    /**
     * How many authored `@qmx-threshold` sites produced an actual verdict.
     *
     * Population agreement alone does not prove that: a directive stays in the
     * population even after the audit gives up on judging it, because it is
     * reported back as `unmeasured` rather than dropped.
     */
    public function measuredThresholdCount(): int
    {
        $count = 0;

        foreach ($this->thresholdVerdicts() as $verdict) {
            if ($verdict->isMeasured()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<mixed, mixed> $decoded
     *
     * @throws AuditReportError
     *
     * @return list<mixed>
     */
    private static function directiveListOf(array $decoded): array
    {
        $directives = $decoded['directives'] ?? null;

        if (!\is_array($directives) || !array_is_list($directives)) {
            throw new AuditReportError(\sprintf(
                'The report carries no "directives" list (got %s).',
                get_debug_type($directives),
            ));
        }

        return $directives;
    }

    /**
     * @throws AuditReportError
     *
     * @return array<mixed, mixed>
     */
    private function section(string $key): array
    {
        $value = $this->decoded[$key] ?? null;

        if (!\is_array($value)) {
            throw new AuditReportError(\sprintf('"%s" must be an object, got %s.', $key, get_debug_type($value)));
        }

        return $value;
    }
}
