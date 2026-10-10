<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Erases exact licensed projections, keeping every other published byte under comparison. */
final class RecordStage implements SurfaceStage
{
    private function __construct(private readonly RunContext $run, private readonly RecordCheck $records) {}

    public static function create(RunContext $run): static
    {
        return new self($run, RecordCheck::create($run));
    }

    public function before(): string
    {
        return 'difference';
    }

    /**
     * @param array<string,string> $candidate
     * @param array<string,string> $reference
     *
     * @return array{array<string,string>,array<string,string>}
     */
    public function countInputs(array $candidate, array $reference): array
    {
        $this->run->publicationForms->supply('candidate', $candidate);
        $this->run->publicationForms->supply('reference', $reference);
        foreach ($this->run->corpus->cases as $case) {
            $this->records->prepare($case->id);
            $key = 'case:' . $case->id . '|format:json';
            if ($this->run->isExactSurface($key) || !$this->run->publicationForms->recordsPair($key)) {
                continue;
            }
            foreach (['candidate', 'reference'] as $side) {
                if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($case, $side))) {
                    continue;
                }
                $text = $side === 'candidate' ? ($candidate[$key] ?? null) : ($reference[$key] ?? null);
                if ($text === null) {
                    continue;
                }
                if ($this->records->licensedResiduals($case->id, 'format:json', $side) === []) {
                    continue;
                }
                $decoded = ReportRecords::decode($text);
                if (!\is_array($decoded['violations'] ?? null)) {
                    continue;
                }
                $removed = array_map(RankingSchema::physical(...), $this->records->licensedResiduals($case->id, 'format:json', $side));
                foreach ($decoded['violations'] as $index => $record) {
                    $at = array_search($record, $removed, true);
                    if ($at !== false) {
                        unset($decoded['violations'][$index], $removed[$at]);
                    }
                }
                $decoded['violations'] = array_values($decoded['violations']);
                if ($side === 'candidate') {
                    $candidate[$key] = ValueCheck::value($decoded);
                } else {
                    $reference[$key] = ValueCheck::value($decoded);
                }
            }
        }
        return [$candidate, $reference];
    }

    public function applyStage(SurfacePair $pair): void
    {
        if (!str_starts_with($pair->key, 'case:') || !ReportViews::recordBearingSurface($pair->surface)
            || $pair->candidate === null || $pair->reference === null) {
            return;
        }
        if (!$this->run->publicationForms->recordsPair($pair->key)) {
            return;
        }
        $case = substr($pair->key, 5, (int) strpos($pair->key, '|') - 5);
        $report = match ($pair->surface) {
            'format:json', 'check:baseline-source', 'check:output:file', 'check:parallel', 'check:baseline' => 'json',
            'format:metrics' => 'metrics',
            'format:suppressed' => 'suppressed',
            'directives' => 'directives',
            default => null,
        };
        $view = match ($pair->surface) {
            'check:baseline-source' => 'check:baseline-source',
            'check:baseline' => 'check:baseline',
            default => $report === null ? 'format:json' : ReportViews::main($report),
        };
        $sourceView = $pair->surface === 'baseline-file' ? $this->baselineView($case) : $view;
        if (!$this->run->publicationForms->recordsPair('case:' . $case . '|' . $sourceView)) {
            return;
        }
        $definition = $this->definition($case);
        foreach (['candidate', 'reference'] as $side) {
            $outcome = CaseOutcome::of($definition, $side);
            if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, $outcome)
                || ($pair->surface === 'baseline-file' && !CaseOutcome::applies(CaseOutcome::CHECK_BASELINE_FILE, $outcome))) {
                continue;
            }
            if ($pair->surface === 'baseline-file'
                && $this->run->report->sourceRejected($side, 'case:' . $case . '|' . $this->baselineView($case), 'records')) {
                continue;
            }
            $text = $side === 'candidate' ? $pair->candidate : $pair->reference;
            try {
                if ($report !== null) {
                    $text = $this->primary($case, $report, $view, $side, $text);
                } elseif ($pair->surface === 'baseline-file') {
                    $text = $this->baseline($case, $side, $text);
                }
                if (\in_array($pair->surface, $this->run->declarations->fields->views('json-document'), true)) {
                    $edits = [];
                    foreach (array_keys($this->run->declarations->fields->changes('json-document', $pair->surface)) as $field) {
                        $edits[ValueCheck::value([$field])] = null;
                    }
                    $text = ReportRecords::edit($text, $edits, removeWholeLines: true);
                }
                if ($side === 'candidate') {
                    $pair->candidate = $text;
                } else {
                    $pair->reference = $text;
                }
            } catch (GateError $error) {
                $this->run->report->fail(FailureClass::RECORD_PROJECTION_MISMATCH, $side . ' / ' . $pair->key, $error->getMessage());
                $pair->settle();
                return;
            }
        }
        if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($definition, 'candidate'))
            || !CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($definition, 'reference'))) {
            return;
        }
        if ($report === 'directives') {
            $this->directiveExit($pair);
        }
    }

    private function primary(string $case, string $report, string $view, string $side, string $text): string
    {
        $published = ReportRecords::extract($report, $text, $this->records->fields($report, $view, $side), $this->records->optionalSubject($report, $view));
        $base = $this->records->base($report, $view, $published);
        $residuals = $this->records->licensedResiduals($case, $view, $side);
        $removed = $report === 'json' ? array_map(RankingSchema::physical(...), $residuals) : $residuals;
        $edits = [];
        $array = ReportRecords::ARRAYS[$report];
        foreach ($published as $index => $record) {
            $at = array_search($record, $removed, true);
            if ($at !== false) {
                $edits[ValueCheck::value([$array, $index])] = null;
                unset($removed[$at]);
                continue;
            }
            $replacement = $this->records->replacement($case, $view, $side, $base[$index]);
            foreach ($record as $field => $value) {
                $path = [$array, $index, $field];
                if (!\array_key_exists($field, $replacement)) {
                    $edits[ValueCheck::value($path)] = null;
                } elseif ($field === 'metrics' && \is_array($value) && \is_array($replacement[$field])) {
                    foreach ($value as $metric => $magnitude) {
                        if (!\array_key_exists($metric, $replacement[$field])) {
                            $edits[ValueCheck::value([...$path, $metric])] = null;
                        } elseif ($magnitude !== $replacement[$field][$metric]) {
                            $edits[ValueCheck::value([...$path, $metric])] = ValueCheck::value($replacement[$field][$metric]);
                        }
                    }
                } elseif ($value !== $replacement[$field]) {
                    $edits[ValueCheck::value($path)] = ValueCheck::value($replacement[$field]);
                }
            }
        }
        if ($report === 'json' && RankingCheck::create($this->run)->observed($case, $view, $side)) {
            $edits[ValueCheck::value(['topIssues'])] = null;
            $counts = $this->records->producerCounts($case, $view, $side, ReportRecords::decode($text));
            if ($counts !== null) {
                $edits[ValueCheck::value(['violationsMeta', 'byRule'])] = ValueCheck::value($counts);
            }
        }
        return $edits === [] ? $text : ReportRecords::edit($text, $edits);
    }

    private function definition(string $case): CaseDefinition
    {
        foreach ($this->run->corpus->cases as $definition) {
            if ($definition->id === $case) {
                return $definition;
            }
        }
        throw new GateError('An unknown case has no declared record outcome.');
    }

    private function baselineView(string $case): string
    {
        foreach ($this->run->corpus->cases as $definition) {
            if ($definition->id === $case) {
                return $definition->baselineSource() === null ? 'format:json' : 'check:baseline-source';
            }
        }
        throw new GateError('An unknown case has no baseline source publication.');
    }

    private function baseline(string $case, string $side, string $text): string
    {
        $view = $this->baselineView($case);
        $published = $this->records->authority($case, $view, $side);
        $entries = ReportRecords::baselineEntries($text, $published);
        $removed = $this->eligibleBaselineResiduals($case, $view, $side);
        $edits = [];
        $entryCounts = [];
        $deletedCounts = [];
        foreach ($entries as $projection) {
            $path = $projection['path'];
            $entry = $projection['fields'];
            $subject = $path[1];
            $entryCounts[$subject] = ($entryCounts[$subject] ?? 0) + 1;
            $group = [];
            foreach ($published as $record) {
                $identity = ['subject' => $subject, 'channel' => $entry['channel'], 'occurrence' => $entry['occurrence'] ?? null, 'edge' => $entry['edge'] ?? null];
                if (ReportRecords::identity('json', $identity) !== ReportRecords::identity('json', $record)) {
                    continue;
                }
                $at = array_search($record, $removed, true);
                if ($at !== false) {
                    unset($removed[$at]);
                    continue;
                }
                $base = $this->records->base('json', $view, [$record])[0];
                $group[] = $this->records->replacement($case, $view, $side, $base);
            }
            if ($group === []) {
                $edits[ValueCheck::value($path)] = null;
                $deletedCounts[$subject] = ($deletedCounts[$subject] ?? 0) + 1;
            } elseif (isset($entry['magnitudes'])) {
                $values = array_map(static fn(array $record): float => round((float) $record['metricValue'], 6), $group);
                sort($values);
                if ($values !== array_map(static fn(mixed $value): float => (float) $value, $entry['magnitudes'])) {
                    if (\count($values) === \count($entry['magnitudes'])) {
                        foreach ($values as $index => $value) {
                            if ($value !== (float) $entry['magnitudes'][$index]) {
                                $edits[ValueCheck::value([...$path, 'magnitudes', $index])] = json_encode($value, \JSON_THROW_ON_ERROR);
                            }
                        }
                    } else {
                        $edits[ValueCheck::value([...$path, 'magnitudes'])] = json_encode($values, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
                    }
                }
            } elseif ($entry['count'] !== \count($group)) {
                $edits[ValueCheck::value([...$path, 'count'])] = ValueCheck::value(\count($group));
            }
        }
        if ($removed !== []) {
            throw new GateError('A licensed baseline source residual has no matching published entry.');
        }
        foreach ($deletedCounts as $subject => $count) {
            if ($count === $entryCounts[$subject]) {
                $edits[ValueCheck::value(['entries', $subject])] = null;
            }
        }
        return $edits === [] ? $text : ReportRecords::edit($text, $edits);
    }

    /** @return list<array<string,mixed>> */
    private function eligibleBaselineResiduals(string $case, string $view, string $side): array
    {
        $raw = $this->records->rawAuthority($case, $view, $side);
        $comparative = $this->records->comparative($case, $view, $side);
        if (\count($raw) !== \count($comparative)) {
            throw new GateError('The raw baseline source and comparative authority have different populations.');
        }
        $remaining = $this->records->licensedResiduals($case, $view, $side);
        $eligible = [];
        foreach ($comparative as $index => $record) {
            $at = array_search($record, $remaining, true);
            if ($at === false) {
                continue;
            }
            unset($remaining[$at]);
            if ($this->run->baselineEligibility->eligible($side, 'case:' . $case . '|' . $view, $raw[$index])) {
                $eligible[] = RankingSchema::physical($record);
            }
        }
        if ($remaining !== []) {
            throw new GateError('A licensed baseline source residual has no complete raw occurrence.');
        }
        return $eligible;
    }

    private function directiveExit(SurfacePair $pair): void
    {
        $a = ReportRecords::decode((string) $pair->candidate);
        $b = ReportRecords::decode((string) $pair->reference);
        $invocation = $pair->key;
        if ($a['exit_code'] !== $b['exit_code'] && ValueCheck::create($this->run)->measure(DeclaredValues::EXIT, 'directives', $invocation, '*', $b['exit_code'], $a['exit_code'])) {
            $pair->candidate = ReportRecords::edit((string) $pair->candidate, [ValueCheck::value(['exit_code']) => ValueCheck::value($b['exit_code'])]);
        }
    }

}
