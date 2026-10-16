<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** A native JSON record population is the positive form; every other supplied publication is one whole invocation. */
final class PublicationForms
{
    public const string RECORDS = 'records';
    public const string WHOLE_INVOCATION = 'whole-invocation';

    /** @var array<string,array<string,string>> */
    private array $artifacts = [];
    /** @var array<string,array<string,string>> */
    private array $forms = [];

    public function __construct(private readonly CapturePlan $plan) {}

    public function recordInvocation(string $key): bool
    {
        return $this->recordsPair($this->publicationKey($key));
    }

    private function publicationKey(string $key): string
    {
        $descriptor = $this->plan->descriptorOf($this->plan->invocationOf($key));
        return Surfaces::key($descriptor['scope'], $descriptor['outputFileKind'] ?? $descriptor['surface']);
    }

    /** @param array<string,string> $artifacts */
    public function supply(string $side, array $artifacts): void
    {
        foreach ($artifacts as $key => $bytes) {
            if (($this->artifacts[$side][$key] ?? null) !== $bytes) {
                $this->forms[$side] = [];
            }
            $this->artifacts[$side][$key] = $bytes;
        }
    }

    /** Own-side decoding is separate from cross-side record authority. */
    public function of(string $side, string $key): ?string
    {
        if (isset($this->forms[$side][$key])) {
            return $this->forms[$side][$key];
        }
        $text = $this->artifacts[$side][$key] ?? null;
        if ($text === null) {
            return null;
        }
        if (!ReportViews::recordBearingSurface(Surfaces::surfaceClass($key))) {
            return self::WHOLE_INVOCATION;
        }
        return $this->forms[$side][$key] = self::classify($key, $text);
    }

    public static function classify(string $key, string $text): string
    {
        $surface = Surfaces::surfaceClass($key);
        $member = match ($surface) {
            'format:json', 'check:baseline-source', 'check:baseline', 'check:output:file', 'check:parallel' => 'violations',
            'format:metrics' => 'symbols',
            'format:suppressed' => 'suppressed',
            'directives' => 'directives',
            default => null,
        };
        $baseline = \in_array($surface, ['baseline-file', 'baseline:cleanup:file', 'baseline:rename-channels:file', 'baseline:update:file'], true);
        if (!ReportViews::recordBearingSurface($surface)) {
            if ((str_starts_with($surface, 'format:') && \in_array(substr($surface, 7), Surfaces::FORMATS, true))
                || \in_array($surface, ['show-suppressed', 'explain'], true)) {
                return self::WHOLE_INVOCATION;
            }
            throw new GateError('The publication has no native record decoder: ' . $key);
        }
        if ($member === null && !$baseline) {
            return self::WHOLE_INVOCATION;
        }
        try {
            if ($member !== null) {
                ReportRecords::rawRecords($text, $member);
                if ($member === 'violations' && \array_key_exists('topIssues', ReportRecords::decode($text))) {
                    ReportRecords::rawRecords($text, 'topIssues');
                }
            } else {
                ReportRecords::baselineDocument($text);
            }
            return self::RECORDS;
        } catch (GateError) {
            return self::WHOLE_INVOCATION;
        }
    }

    /** The single positive decision for comparative record authority. */
    public function recordsPair(string $key): bool
    {
        $this->plan->invocationOf($key);
        if (!ReportViews::recordBearingSurface(Surfaces::surfaceClass($key))) {
            return false;
        }
        return $this->of('candidate', $key) === self::RECORDS && $this->of('reference', $key) === self::RECORDS;
    }

    public function schemaPair(string $case, string $view): bool
    {
        foreach ($this->schemaKeys($case, $view) as $key) {
            if ($this->recordsPair($key)) {
                return true;
            }
        }
        return false;
    }

    /** Prospective supplier obligations do not grant comparison authority. */
    public function recordsExpected(string $key): bool
    {
        $publication = $this->publicationKey($key);
        $surface = Surfaces::surfaceClass($publication);
        if (!\in_array($surface, ['format:json', 'check:baseline-source', 'check:baseline', 'check:output:file', 'check:parallel', 'format:metrics', 'format:suppressed', 'directives', 'baseline-file', 'baseline:cleanup:file', 'baseline:rename-channels:file', 'baseline:update:file'], true)) {
            return false;
        }
        foreach (['candidate', 'reference'] as $side) {
            if ($this->plan->requiredOn($this->plan->invocationOf($key), $side) && $this->of($side, $publication) === self::WHOLE_INVOCATION) {
                return false;
            }
        }
        return true;
    }

    public function schemaExpected(string $case, string $view): bool
    {
        foreach ($this->schemaKeys($case, $view) as $key) {
            if ($this->recordsExpected($key)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function schemaKeys(string $case, string $view): array
    {
        if ($view !== 'ranking') {
            foreach ($this->plan->invocations() as $descriptor) {
                if ($descriptor['scope'] === 'case:' . $case
                    && ($descriptor['surface'] === $view || $descriptor['outputFileKind'] === $view)) {
                    return ['case:' . $case . '|' . $view];
                }
            }
            return [];
        }
        $keys = [];
        foreach ($this->plan->rankingInvocations() as $source) {
            if ($source['scope'] === 'case:' . $case) {
                $keys[] = Surfaces::key($source['scope'], $source['surface']);
            }
        }
        return $keys;
    }

    /** @return list<string> */
    public function invocationArtifacts(string $key): array
    {
        return $this->plan->artifactsOf($this->plan->invocationOf($key));
    }
}
