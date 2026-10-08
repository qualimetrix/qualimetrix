<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;

/** Own captured bytes determine whether a publication supplies records or a refusal. */
final class PublicationForms
{
    /** @var WeakMap<GateReport,self>|null */
    private static ?WeakMap $reports = null;
    public const string RECORDS = 'records';
    public const string REFUSAL = 'refusal';

    /** @var array<string,array<string,string>> */
    private array $artifacts = [];
    /** @var array<string,array<string,string>> */
    private array $forms = [];
    /** @var array<string,array<string,string>> */
    private array $problems = [];

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
                $this->problems[$side] = [];
            }
            $this->artifacts[$side][$key] = $bytes;
        }
    }

    public function of(string $side, string $key): ?string
    {
        if (isset($this->forms[$side][$key])) {
            return $this->forms[$side][$key];
        }
        if (isset($this->problems[$side][$key])) {
            return null;
        }
        $text = $this->artifacts[$side][$key] ?? null;
        if ($text === null || !ReportViews::recordBearingSurface(Surfaces::surfaceClass($key))) {
            return null;
        }
        try {
            return $this->forms[$side][$key] = $this->classify($side, $key, $text);
        } catch (GateError $error) {
            $this->problems[$side][$key] = $error->getMessage();
            return null;
        }
    }

    private function classify(string $side, string $key, string $text): string
    {
        $surface = Surfaces::surfaceClass($key);
        $document = json_decode($text, true);
        $member = match ($surface) {
            'format:json', 'check:baseline-source', 'check:baseline', 'check:output:file', 'check:parallel' => 'violations',
            'format:metrics' => 'symbols',
            'format:suppressed' => 'suppressed',
            'directives' => 'directives',
            default => null,
        };
        if ($member !== null && \is_array($document) && \array_key_exists($member, $document)) {
            ReportRecords::rawRecords($text, $member);
            if ($member === 'violations' && \array_key_exists('topIssues', $document)) {
                ReportRecords::rawRecords($text, 'topIssues');
            }
            return self::RECORDS;
        }
        if (\is_array($document) && \is_string($document['error'] ?? null) && $document['error'] !== '') {
            return self::REFUSAL;
        }
        try {
            $invocation = $this->plan->invocationOf($key);
            $descriptor = $this->plan->descriptorOf($invocation);
        } catch (GateError) {
            $descriptor = ['scope' => substr($key, 0, (int) strpos($key, '|')), 'surface' => $surface, 'commandClass' => 'check'];
        }
        $exitKey = Surfaces::key($descriptor['scope'], 'exit:' . ($descriptor['surface'] === 'baseline-file' ? 'baseline:generate' : $descriptor['surface']));
        $exit = $this->artifacts[$side][$exitKey] ?? null;
        $analysisExits = match ($descriptor['commandClass']) {
            'check' => ['0', '1', '2', '4'],
            'directives' => ['0', '2', '4'],
            default => ['0'],
        };
        if ($exit !== null && !\in_array($exit, $analysisExits, true)) {
            return self::REFUSAL;
        }
        if ($member !== null) {
            ReportRecords::rawRecords($text, $member);
        } elseif (\in_array($surface, ['baseline-file', 'baseline:cleanup:file', 'baseline:rename-channels:file', 'baseline:update:file'], true)) {
            $baseline = ReportRecords::decode($text);
            if (!\is_array($baseline['entries'] ?? null)) {
                throw new GateError('The baseline publication has no observed entries object.');
            }
        } elseif ($surface === 'format:checkstyle') {
            ReportRecords::checkstyle($text);
        } elseif (\in_array($surface, ProseRecords::SURFACES, true)) {
            ProseRecords::extract($surface, $text);
        } elseif (\in_array($surface, ['format:html', 'format:gitlab', 'format:sarif'], true)) {
            ReportRecords::projected($surface, $surface === 'format:html' ? ReportPayload::of($text, $key, $side) : $text);
        } else {
            throw new GateError('The publication has no native record decoder: ' . $key);
        }
        return self::RECORDS;
    }

    public function problem(string $side, string $key): ?string
    {
        $this->of($side, $key);
        return $this->problems[$side][$key] ?? null;
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
        if ($candidate === self::REFUSAL || $reference === self::REFUSAL
            || isset($this->problems['candidate'][$key], $this->artifacts['candidate'][$key])
            || isset($this->problems['reference'][$key], $this->artifacts['reference'][$key])) {
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
