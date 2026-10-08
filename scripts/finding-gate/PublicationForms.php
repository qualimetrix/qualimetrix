<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;

/** A native record population is the positive form; every other supplied publication is one whole invocation. */
final class PublicationForms
{
    /** @var WeakMap<GateReport,self>|null */
    private static ?WeakMap $reports = null;
    public const string RECORDS = 'records';
    public const string WHOLE_INVOCATION = 'whole-invocation';

    /** @var array<string,array<string,string>> */
    private array $artifacts = [];
    /** @var array<string,array<string,string>> */
    private array $forms = [];

    public function __construct(private readonly CapturePlan $plan, GateReport $report)
    {
        self::$reports ??= new WeakMap();
        self::$reports[$report] ??= $this;
    }

    public static function forReport(GateReport $report): ?self
    {
        return self::$reports[$report] ?? null;
    }

    public function recordInvocation(string $key): ?bool
    {
        try {
            $invocation = $this->plan->invocationOf($key);
        } catch (GateError) {
            return null;
        }
        $descriptor = $this->plan->descriptorOf($invocation);
        return $this->recordsPair(Surfaces::key($descriptor['scope'], $descriptor['outputFileKind'] ?? $descriptor['surface']));
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

    public function of(string $side, string $key): ?string
    {
        if (isset($this->forms[$side][$key])) {
            return $this->forms[$side][$key];
        }
        $text = $this->artifacts[$side][$key] ?? null;
        if ($text === null || !ReportViews::recordBearingSurface(Surfaces::surfaceClass($key))) {
            return null;
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
        if ($member === null && !$baseline && $surface !== 'format:checkstyle'
            && !\in_array($surface, ProseRecords::SURFACES, true)
            && !\in_array($surface, ['format:html', 'format:gitlab', 'format:sarif'], true)) {
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
            } elseif ($baseline) {
                ReportRecords::baselineDocument($text);
            }
            return self::RECORDS;
        } catch (GateError) {
            return self::WHOLE_INVOCATION;
        }
    }

    public function problem(string $side, string $key): null
    {
        $this->of($side, $key);
        return null;
    }

    public function schemaPair(string $case, string $view): ?bool
    {
        if ($view !== 'ranking') {
            return $this->recordsPair('case:' . $case . '|' . $view);
        }
        $unknown = false;
        foreach ($this->plan->rankingInvocations() as $source) {
            if ($source['scope'] !== 'case:' . $case) {
                continue;
            }
            $pair = $this->recordsPair(Surfaces::key($source['scope'], $source['surface']));
            if ($pair === true) {
                return true;
            }
            $unknown = $unknown || $pair === null;
        }
        return $unknown ? null : false;
    }

    /** Null is a prospective obligation whose capture has not been supplied yet. */
    public function recordsPair(string $key): ?bool
    {
        $candidate = $this->of('candidate', $key);
        $reference = $this->of('reference', $key);
        if ($candidate === self::WHOLE_INVOCATION || $reference === self::WHOLE_INVOCATION) {
            return false;
        }
        return $candidate === null || $reference === null ? null : true;
    }

    /** @return list<string> */
    public function invocationArtifacts(string $key): array
    {
        return $this->plan->artifactsOf($this->plan->invocationOf($key));
    }
}
