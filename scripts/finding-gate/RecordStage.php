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
                $decoded['violations'] = \array_slice($decoded['violations'], \count($this->records->licensedResiduals($case->id, 'format:json', $side)));
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
            'check:baseline' => $this->baselineView($case),
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
        if ($report === 'json') {
            $this->inspectRankedPrefix($pair, $case, $view);
        } elseif ($report === 'directives') {
            $this->directiveExit($pair);
        }
    }

    private function primary(string $case, string $report, string $view, string $side, string $text): string
    {
        $published = ReportRecords::extract($report, $text, $this->records->fields($report, $view, $side), $this->records->optionalSubject($report, $view));
        $base = $this->records->base($report, $view, $published);
        $removed = $this->records->licensedResiduals($case, $view, $side);
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
        return $edits === [] ? $text : ReportRecords::edit($text, $edits);
    }

    private function structured(string $case, string $side, string $surface, string $text): string
    {
        $projected = ReportRecords::projected($surface, $text);
        $removed = array_map(static fn(array $record): array => ReportRecords::projection($surface, $record), $this->records->licensedResiduals($case, 'format:json', $side));
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
            foreach ($this->records->published($case, 'format:json', $side) as $record) {
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
                            default => [...$path, $field],
                        },
                        default => [...$path, $field],
                    };
                    $edits[ValueCheck::value($target)] = ValueCheck::value($value);
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
        $removed = $this->records->licensedResiduals($case, 'format:json', $side);
        $published = $this->records->published($case, 'format:json', $side);
        foreach (array_reverse(ProseRecords::extract($surface, $text)) as $entry) {
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
        $removed = array_map(static fn(array $record): array => ReportRecords::projection('format:checkstyle', $record), $this->records->licensedResiduals($case, 'format:json', $side));
        $published = $this->records->published($case, 'format:json', $side);
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
        $published = $this->records->published($case, $view, $side);
        $entries = ReportRecords::baselineEntries($text, $published);
        $removed = $this->records->licensedResiduals($case, $view, $side);
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
                    $edits[ValueCheck::value([...$path, 'magnitudes'])] = json_encode($values, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
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

    private function directiveExit(SurfacePair $pair): void
    {
        $a = ReportRecords::decode((string) $pair->candidate);
        $b = ReportRecords::decode((string) $pair->reference);
        $invocation = $pair->key;
        if ($a['exit_code'] !== $b['exit_code'] && ValueCheck::create($this->run)->measure(DeclaredValues::EXIT, 'directives', $invocation, '*', $b['exit_code'], $a['exit_code'])) {
            $pair->candidate = ReportRecords::edit((string) $pair->candidate, [ValueCheck::value(['exit_code']) => ValueCheck::value($b['exit_code'])]);
        }
    }

    private function inspectRankedPrefix(SurfacePair $pair, string $case, string $view): void
    {
        try {
            $this->rankedProjection($pair, $case, $view);
        } catch (GateError $error) {
            $this->run->report->fail(FailureClass::TOP_ISSUES_MISMATCH, $pair->key, $error->getMessage());
        }
    }

    private function rankedProjection(SurfacePair $pair, string $case, string $view): void
    {
        $documents = ['candidate' => ReportRecords::decode((string) $pair->candidate), 'reference' => ReportRecords::decode((string) $pair->reference)];
        if (!\array_key_exists('topIssues', $documents['candidate']) && !\array_key_exists('topIssues', $documents['reference'])) {
            return;
        }
        if (!\array_key_exists('topIssues', $documents['candidate']) || !\array_key_exists('topIssues', $documents['reference'])) {
            throw new GateError('A ranked publication was removed from only one side.');
        }
        $lists = [];
        $affected = ['candidate' => [], 'reference' => []];
        $vacancies = ['candidate' => 0, 'reference' => 0];
        $matched = [];
        foreach ($documents as $side => $document) {
            $issues = $document['topIssues'];
            if (!\is_array($issues) || !array_is_list($issues)) {
                throw new GateError('A ranked publication requires an observed topIssues list.');
            }
            $budget = $this->records->published($case, $view, $side);
            $removed = $this->records->licensedResiduals($case, $view, $side);
            $completeCounts = array_count_values(array_map(DeclaredRecords::canonical(...), $budget));
            $removedCounts = array_count_values(array_map(DeclaredRecords::canonical(...), $removed));
            $rankedValues = [];
            $entries = [];
            $previous = \INF;
            foreach ($issues as $index => $issue) {
                $keys = \is_array($issue) ? array_keys($issue) : [];
                $expectedKeys = ReportRecords::RANKED_FIELDS;
                sort($keys);
                sort($expectedKeys);
                if ($keys !== $expectedKeys || ($issue['rank'] ?? null) !== $index + 1
                    || (!\is_int($issue['impactScore'] ?? null) && !\is_float($issue['impactScore'] ?? null))
                    || $issue['impactScore'] > $previous) {
                    throw new GateError('A ranked issue has an invalid rank, score, or descending position.');
                }
                $previous = $issue['impactScore'];
                $found = null;
                $complete = null;
                foreach ($budget as $at => $record) {
                    if (!self::issueOf($issue, $record)) {
                        continue;
                    }
                    $canonical = DeclaredRecords::canonical($record);
                    if ($complete !== null && $canonical !== $complete) {
                        throw new GateError('A ranked issue has ambiguous complete authoritative finding instances.');
                    }
                    $complete = $canonical;
                    $found ??= $at;
                }
                if ($found === null) {
                    throw new GateError('A ranked issue has no complete authoritative finding instance.');
                }
                $record = $budget[$found];
                unset($budget[$found]);
                $completeKey = DeclaredRecords::canonical($record);
                $values = ValueCheck::value([$issue['impactScore'], $issue['coupling.class-rank']]);
                if (($removedCounts[$completeKey] ?? 0) > 0 && $removedCounts[$completeKey] < $completeCounts[$completeKey]
                    && isset($rankedValues[$completeKey]) && $rankedValues[$completeKey] !== $values) {
                    throw new GateError('A partial duplicate ranked removal has ambiguous value correspondence.');
                }
                $rankedValues[$completeKey] = $values;
                $base = $this->records->base('json', $view, [$record])[0];
                $replacement = $this->records->replacement($case, $view, $side, $base);
                $entries[] = ['index' => $index, 'record' => $record, 'fields' => $issue, 'complete' => $completeKey, 'base' => $base, 'replacement' => $replacement];
            }
            $removalBudgets = self::rankedRemovalBudgets($completeCounts, $removedCounts, array_column($entries, 'complete'));
            foreach ($entries as $entry) {
                $index = $entry['index'];
                $record = $entry['record'];
                $issue = $entry['fields'];
                $completeKey = $entry['complete'];
                $base = $entry['base'];
                $replacement = $entry['replacement'];
                $removal = array_search($record, $removed, true);
                if ($removalBudgets[$completeKey] > 0) {
                    if ($removal === false) {
                        throw new GateError('A visible ranked removal has no remaining exact licensed occurrence.');
                    }
                    --$removalBudgets[$completeKey];
                    unset($removed[$removal]);
                    ++$vacancies[$side];
                    $affected[$side][] = $index;
                } else {
                    $matched[$side][DeclaredRecords::canonical($replacement)][] = ['index' => $index, 'record' => $record, 'fields' => $issue];
                    if ($replacement !== $base) {
                        foreach (['file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation'] as $field) {
                            if (\array_key_exists($field, $replacement)) {
                                $issue[$field] = $replacement[$field];
                            }
                        }
                        if (isset($issue['debtMinutes']) && isset($replacement['techDebtMinutes'])) {
                            $issue['debtMinutes'] = $replacement['techDebtMinutes'];
                        }
                        $affected[$side][] = $index;
                    }
                }
                $lists[$side][] = $issue;
            }
            $lists[$side] ??= [];
        }
        foreach ($matched['candidate'] ?? [] as $complete => $entries) {
            foreach (self::rankedValuePairs($entries, $matched['reference'][$complete] ?? []) as $pairing) {
                $entry = $pairing['candidate'];
                $old = $pairing['reference'];
                foreach (['impactScore', 'coupling.class-rank'] as $field) {
                    if ($old['fields'][$field] === $entry['fields'][$field]) {
                        continue;
                    }
                    $base = $this->records->base('json', $view, [$entry['record']])[0];
                    $subject = 'case:' . $case . '|' . $view . '|record:' . ReportRecords::identity('json', $base);
                    if (ValueCheck::create($this->run)->measure(DeclaredValues::FIELD, $field, $subject, SubjectLevel::of((string) $entry['record']['subject']), $old['fields'][$field], $entry['fields'][$field])) {
                        $lists['candidate'][$entry['index']][$field] = $old['fields'][$field];
                        $affected['candidate'][] = $entry['index'];
                        $affected['reference'][] = $old['index'];
                    }
                }
            }
        }
        if ($affected['candidate'] === [] && $affected['reference'] === []) {
            return;
        }
        $prefix = min($affected['candidate'] === [] ? \count($lists['candidate']) : min($affected['candidate']), $affected['reference'] === [] ? \count($lists['reference']) : min($affected['reference']));
        if (\array_slice($lists['candidate'], 0, $prefix) !== \array_slice($lists['reference'], 0, $prefix)) {
            throw new GateError('The ranked prefix before the first licensed record changed.');
        }
        $stable = [];
        foreach ($lists as $side => $issues) {
            foreach ($issues as $index => $issue) {
                if (!\in_array($index, $affected[$side], true)) {
                    unset($issue['rank']);
                    $stable[$side][] = ValueCheck::value($issue);
                }
            }
            $stable[$side] ??= [];
        }
        $partitions = [];
        foreach (['candidate', 'reference'] as $side) {
            $other = $side === 'candidate' ? 'reference' : 'candidate';
            $partitions[$side] = self::rankedPartition($stable[$side], $stable[$other]);
        }
        if ($partitions['candidate']['common'] !== $partitions['reference']['common']) {
            throw new GateError('Unaffected ranked survivors changed relative order.');
        }
        foreach (['candidate', 'reference'] as $side) {
            $other = $side === 'candidate' ? 'reference' : 'candidate';
            if ($affected[$other] === []) {
                continue;
            }
            $admitted = $partitions[$side]['unmatched'];
            $available = \count($this->records->published($case, $view, $side));
            $expectedSize = min(max(\count($lists['candidate']), \count($lists['reference'])), $available);
            if (\count($lists[$side]) !== $expectedSize) {
                throw new GateError('The ranked refill has an unexplained size or more entrants than licensed vacancies.');
            }
            $ceiling = $prefix === 0 ? \INF : $lists[$other][$prefix - 1]['impactScore'];
            $remainingVacancies = $vacancies[$other];
            foreach ($admitted as $encoded) {
                if ($remainingVacancies === 0) {
                    throw new GateError('The ranked refill has an unexplained size or more entrants than licensed vacancies.');
                }
                --$remainingVacancies;
                $issue = ReportRecords::decode($encoded);
                if ($issue['impactScore'] > $ceiling) {
                    throw new GateError('A newly admitted issue outranks the unchanged prefix.');
                }
            }
        }
        foreach (['candidate', 'reference'] as $side) {
            $edits = [];
            for ($index = $prefix; $index < \count($lists[$side]); ++$index) {
                $edits[ValueCheck::value(['topIssues', $index])] = null;
            }
            if ($edits !== []) {
                if ($side === 'candidate') {
                    $pair->candidate = ReportRecords::edit((string) $pair->candidate, $edits);
                } else {
                    $pair->reference = ReportRecords::edit((string) $pair->reference, $edits);
                }
            }
        }
    }

    /**
     * @param array<string,int> $completeCounts
     * @param array<string,int> $removedCounts
     * @param list<string> $visibleComplete
     *
     * @return array<string,int>
     */
    private static function rankedRemovalBudgets(array $completeCounts, array $removedCounts, array $visibleComplete): array
    {
        foreach ($removedCounts as $complete => $removed) {
            if ($removed < 0 || !isset($completeCounts[$complete]) || $removed > $completeCounts[$complete]) {
                throw new GateError('A ranked removal exceeds its exact complete occurrence population.');
            }
        }
        $budgets = [];
        foreach (array_count_values($visibleComplete) as $complete => $visible) {
            $available = $completeCounts[$complete] ?? null;
            if ($available === null || $available < $visible) {
                throw new GateError('A ranked publication exceeds its exact complete occurrence population.');
            }
            $survivors = $available - ($removedCounts[$complete] ?? 0);
            $budgets[$complete] = $visible - min($visible, $survivors);
        }
        return $budgets;
    }

    /**
     * @param list<array{index:int,record:array<string,mixed>,fields:array<string,mixed>}> $candidate
     * @param list<array{index:int,record:array<string,mixed>,fields:array<string,mixed>}> $reference
     *
     * @return list<array{candidate:array{index:int,record:array<string,mixed>,fields:array<string,mixed>},reference:array{index:int,record:array<string,mixed>,fields:array<string,mixed>}}>
     */
    private static function rankedValuePairs(array $candidate, array $reference): array
    {
        $pairs = [];
        foreach ($candidate as $index => $entry) {
            foreach ($reference as $at => $old) {
                if ($entry['fields']['impactScore'] !== $old['fields']['impactScore']
                    || $entry['fields']['coupling.class-rank'] !== $old['fields']['coupling.class-rank']) {
                    continue;
                }
                $pairs[] = ['candidate' => $entry, 'reference' => $old];
                unset($candidate[$index], $reference[$at]);
                break;
            }
        }
        if ($candidate !== [] && $reference !== []) {
            $candidate = array_values($candidate);
            $reference = array_values($reference);
            if (\count($candidate) !== \count($reference)) {
                throw new GateError('Duplicate ranked values have no unambiguous complete occurrence correspondence.');
            }
            foreach (['candidate' => $candidate, 'reference' => $reference] as $entries) {
                foreach ($entries as $entry) {
                    if ($entry['fields']['impactScore'] !== $entries[0]['fields']['impactScore']
                        || $entry['fields']['coupling.class-rank'] !== $entries[0]['fields']['coupling.class-rank']) {
                        throw new GateError('Duplicate ranked values have no unambiguous complete occurrence correspondence.');
                    }
                }
            }
            foreach ($candidate as $index => $entry) {
                $pairs[] = ['candidate' => $entry, 'reference' => $reference[$index]];
            }
        }
        return $pairs;
    }

    /**
     * @param list<string> $issues
     * @param list<string> $other
     *
     * @return array{common:list<string>,unmatched:list<string>}
     */
    private static function rankedPartition(array $issues, array $other): array
    {
        $common = [];
        $unmatched = [];
        foreach ($issues as $issue) {
            $at = array_search($issue, $other, true);
            if ($at === false) {
                $unmatched[] = $issue;
            } else {
                $common[] = $issue;
                unset($other[$at]);
            }
        }
        return ['common' => $common, 'unmatched' => $unmatched];
    }

    /**
     * @param array<string,mixed> $issue
     * @param array<string,mixed> $record
     */
    private static function issueOf(array $issue, array $record): bool
    {
        if (($issue['debtMinutes'] ?? null) !== $record['techDebtMinutes']) {
            return false;
        }
        foreach (['file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation'] as $field) {
            if (!\array_key_exists($field, $issue) || $issue[$field] !== $record[$field]) {
                return false;
            }
        }
        return true;
    }
}
