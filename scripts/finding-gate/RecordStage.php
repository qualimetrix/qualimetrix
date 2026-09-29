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
        foreach ($this->run->corpus->cases as $case) {
            $this->records->prepare($case->id);
            $key = 'case:' . $case->id . '|format:json';
            if ($this->run->isExactSurface($key)) {
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
        if (!str_starts_with($pair->key, 'case:') || $pair->candidate === null || $pair->reference === null) {
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
        $definition = $this->definition($case);
        foreach (['candidate', 'reference'] as $side) {
            $outcome = CaseOutcome::of($definition, $side);
            if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, $outcome)
                || ($pair->surface === 'baseline-file' && !CaseOutcome::applies(CaseOutcome::CHECK_BASELINE_FILE, $outcome))) {
                continue;
            }
            $text = $side === 'candidate' ? $pair->candidate : $pair->reference;
            try {
                if ($report !== null) {
                    $text = $this->primary($case, $report, $view, $side, $text);
                } elseif (\in_array($pair->surface, ['format:html', 'format:sarif', 'format:gitlab'], true)) {
                    $text = $this->structured($case, $side, $pair->surface, $text);
                } elseif (\in_array($pair->surface, ProseRecords::SURFACES, true)) {
                    $text = $this->prose($case, $side, $pair->surface, $text);
                } elseif ($pair->surface === 'format:checkstyle') {
                    $text = $this->checkstyle($case, $side, $text);
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

    private function structured(string $case, string $side, string $surface, string $text): string
    {
        $projected = ReportRecords::projected($surface, $text);
        $removed = array_map(static fn(array $record): array => ReportRecords::projection($surface, RankingSchema::physical($record)), $this->records->licensedResiduals($case, 'format:json', $side));
        $edits = [];
        $budgets = [];
        foreach ($projected as $entry) {
            $path = $entry['path'];
            $bucket = $surface === 'format:html' ? ValueCheck::value(\array_slice($path, 0, -1)) : '*';
            $budgets[$bucket] ??= $removed;
            $at = array_search($entry['fields'], $budgets[$bucket], true);
            if ($at !== false) {
                $edits[ValueCheck::value($path)] = null;
                unset($budgets[$bucket][$at]);
                continue;
            }
            foreach ($this->records->authority($case, 'format:json', $side) as $record) {
                if (ReportRecords::projection($surface, $record) !== $entry['fields']) {
                    continue;
                }
                $base = $this->records->base('json', 'format:json', [$record])[0];
                $replacement = $this->records->replacement($case, 'format:json', $side, $base);
                if ($replacement === $base) {
                    break;
                }
                $changed = ReportRecords::projection($surface, $replacement);
                foreach ($changed as $field => $value) {
                    if ($value === $entry['fields'][$field]) {
                        continue;
                    }
                    $target = match ($surface) {
                        'format:sarif' => match ($field) {
                            'file' => [...$path, 'locations', 0, 'physicalLocation', 'artifactLocation', 'uri'],
                            'line' => [...$path, 'locations', 0, 'physicalLocation', 'region', 'startLine'],
                            'message' => [...$path, 'message', 'text'],
                            default => [...$path, $field],
                        },
                        default => [...$path, $field],
                    };
                    $edits[ValueCheck::value($target)] = ValueCheck::value($surface === 'format:sarif' && $field === 'message' ? $value['text'] : $value);
                }
                break;
            }
        }
        $text = $edits === [] ? $text : ReportRecords::edit($text, $edits);
        return $surface === 'format:sarif' && $removed !== [] ? $this->sarifCatalog($text, array_column($removed, 'ruleId')) : $text;
    }

    /** @param list<string> $removedCodes */
    private function sarifCatalog(string $text, array $removedCodes): string
    {
        $document = ReportRecords::decode($text);
        $edits = [];
        foreach ($document['runs'] as $runIndex => $run) {
            $used = array_column($run['results'], 'ruleId');
            $rules = array_values(array_filter($run['tool']['driver']['rules'], static fn(array $rule): bool => !\in_array($rule['id'], $removedCodes, true) || \in_array($rule['id'], $used, true)));
            $indices = array_flip(array_column($rules, 'id'));
            foreach ($run['tool']['driver']['rules'] as $index => $rule) {
                if (\in_array($rule['id'], $removedCodes, true) && !\in_array($rule['id'], $used, true)) {
                    $edits[ValueCheck::value(['runs', $runIndex, 'tool', 'driver', 'rules', $index])] = null;
                }
            }
            foreach ($run['results'] as $index => $record) {
                if ($record['ruleIndex'] !== $indices[$record['ruleId']]) {
                    $edits[ValueCheck::value(['runs', $runIndex, 'results', $index, 'ruleIndex'])] = ValueCheck::value($indices[$record['ruleId']]);
                }
            }
        }
        return ReportRecords::edit($text, $edits);
    }

    private function prose(string $case, string $side, string $surface, string $text): string
    {
        $removed = array_map(RankingSchema::physical(...), $this->records->licensedResiduals($case, 'format:json', $side));
        $published = $this->records->authority($case, 'format:json', $side);
        foreach (array_reverse(ProseRecords::extract($surface, $text)) as $entry) {
            if ($surface === 'format:summary' && isset($entry['fields']['rank']) && RankingCheck::create($this->run)->observed($case, 'format:json', $side)) {
                $text = ProseRecords::erase($text, $entry['lines']);
                continue;
            }
            foreach ($published as $index => $record) {
                if (!ProseRecords::matches($surface, $entry['fields'], $record)) {
                    continue;
                }
                unset($published[$index]);
                $at = array_search($record, $removed, true);
                if ($at !== false) {
                    $text = ProseRecords::erase($text, $entry['lines']);
                    unset($removed[$at]);
                } else {
                    $base = $this->records->base('json', 'format:json', [$record])[0];
                    $replacement = $this->records->replacement($case, 'format:json', $side, $base);
                    if ($replacement !== $base) {
                        $text = ProseRecords::rewriteProjection($surface, $text, $entry, $record, $replacement);
                    }
                }
                break;
            }
        }
        return $text;
    }

    private function checkstyle(string $case, string $side, string $text): string
    {
        $removed = array_map(static fn(array $record): array => ReportRecords::projection('format:checkstyle', RankingSchema::physical($record)), $this->records->licensedResiduals($case, 'format:json', $side));
        $published = $this->records->authority($case, 'format:json', $side);
        $rebuilt = preg_replace_callback('~<file\b[^>]*name="([^"]*)"[^>]*>(.*?)</file>~s', function (array $file) use ($case, $side, &$removed, &$published): string {
            $name = html_entity_decode($file[1], \ENT_QUOTES | \ENT_XML1, 'UTF-8');
            $body = preg_replace_callback('~<error\b[^>]*/>~s', function (array $error) use ($name, $case, $side, &$removed, &$published): string {
                preg_match_all('~\b([a-z]+)="([^"]*)"~', $error[0], $attributes, \PREG_SET_ORDER);
                $values = [];
                foreach ($attributes as $attribute) {
                    $values[$attribute[1]] = html_entity_decode($attribute[2], \ENT_QUOTES | \ENT_XML1, 'UTF-8');
                }
                $projection = ['file' => $name, 'line' => isset($values['line']) ? (int) $values['line'] : null, 'severity' => $values['severity'] ?? null, 'code' => $values['source'] ?? null, 'message' => $values['message'] ?? null];
                $at = array_search($projection, $removed, true);
                if ($at !== false) {
                    unset($removed[$at]);
                    return '';
                }
                foreach ($published as $index => $record) {
                    if (ReportRecords::projection('format:checkstyle', $record) !== $projection) {
                        continue;
                    }
                    unset($published[$index]);
                    $base = $this->records->base('json', 'format:json', [$record])[0];
                    $replacement = $this->records->replacement($case, 'format:json', $side, $base);
                    if ($replacement === $base) {
                        return $error[0];
                    }
                    $changed = ReportRecords::projection('format:checkstyle', $replacement);
                    if ($changed['file'] !== $name) {
                        throw new GateError('A checkstyle file move cannot rewrite an unrelated file group.');
                    }
                    $values = ['line' => $changed['line'], 'severity' => $changed['severity'], 'source' => $changed['code'], 'message' => $changed['message']];
                    return preg_replace_callback('~\b(line|severity|source|message)="([^"]*)"~', static fn(array $attribute): string => $attribute[1] . '="' . htmlspecialchars((string) $values[$attribute[1]], \ENT_QUOTES | \ENT_XML1, 'UTF-8') . '"', $error[0]) ?? throw new GateError('Cannot rewrite an exact checkstyle projection.');
                }
                return $error[0];
            }, $file[2]);
            if ($body !== null && $body !== $file[2] && trim($body) === '') {
                return '';
            }
            return str_replace($file[2], $body ?? throw new GateError('Cannot read checkstyle error elements.'), $file[0]);
        }, $text);
        return $rebuilt ?? throw new GateError('Cannot read checkstyle file elements.');
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
