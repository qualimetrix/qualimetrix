<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;
use WeakReference;

/** Authoritative records and their side-local publications for one comparison. */
final class RecordCheck implements CaseCheck, RunCheck
{
    /** @var WeakMap<RunContext,WeakReference<self>>|null */
    private static ?WeakMap $runs = null;
    /** @var array<string,array<string,array<string,list<array<string,mixed>>>>> */
    private array $records = [];
    /** @var array<string,array<string,array<string,list<array<string,mixed>>>>> */
    private array $publications = [];
    /** @var array<string,array<string,array<string,list<array<string,mixed>>>>> */
    private array $physical = [];
    /** @var array<string,array<string,array<string,list<array<string,mixed>>>>> */
    private array $raw = [];
    /** @var array<string,array<string,array<string,bool>>> */
    private array $identityReady = [];
    /** @var array<string,true> */
    private array $prepared = [];
    /** @var array<string,array<string,array<string,list<array<string,mixed>>>>> */
    private array $removed = [];
    /** @var array<string,array<string,array<string,array<string,array<string,mixed>>>>> */
    private array $replacements = [];
    /** @var list<list<string>> */
    private array $derived = [];
    /** @var array<string,array<string,array<string,int>>> */
    private array $producerCountChanges = [];
    /** @var array<string,array<string,list<array{candidate:?array<string,mixed>,reference:?array<string,mixed>,paths:list<list<string>>,whole:bool}>>> */
    private array $exactOperations = [];
    private bool $deriving = false;

    private function __construct(private readonly RunContext $run, private readonly ValueCheck $values, private readonly RankingCheck $ranking) {}

    public static function create(RunContext $run): static
    {
        self::$runs ??= new WeakMap();
        $check = isset(self::$runs[$run]) ? self::$runs[$run]->get() : null;
        if ($check instanceof self) {
            return $check;
        }
        $check = new self($run, ValueCheck::create($run), RankingCheck::create($run));
        self::$runs[$run] = WeakReference::create($check);
        return $check;
    }

    public function trialCopy(RunContext $trial): self
    {
        $copy = self::create($trial);
        $copy->records = $this->records;
        $copy->publications = $this->publications;
        $copy->physical = $this->physical;
        $copy->raw = $this->raw;
        $copy->identityReady = $this->identityReady;
        $copy->deriving = $this->deriving;
        return $copy;
    }

    public function name(): string
    {
        return CaseOutcome::CHECK_RECORDS;
    }

    public function startDeriving(): void
    {
        $this->deriving = true;
    }

    /** @param array<string,string> $artifacts */
    public function checkCase(string $side, CaseDefinition $case, string $outcome, array $artifacts): void
    {
        if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, $outcome)) {
            return;
        }
        $scope = 'case:' . $case->id;
        foreach (ReportViews::forCase($case) as $surface => $report) {
            if ($this->withdrawn($side, $case, $surface)) {
                continue;
            }
            $key = $scope . '|' . $surface;
            try {
                if (!isset($artifacts[$key])) {
                    throw new GateError('The authoritative ' . $report . ' record publication is missing.');
                }
                $text = $this->mapped($side, $surface, $artifacts[$key]);
                $this->records[$case->id][$surface][$side] = ReportRecords::extract($report, $text, $this->fields($report, $surface, $side), $this->optionalSubject($report, $surface));
                $this->publications[$case->id][$surface][$side] = $this->records[$case->id][$surface][$side];
                $this->physical[$case->id][$surface][$side] = $this->records[$case->id][$surface][$side];
                $this->identityReady[$case->id][$surface][$side] = true;
                if ($report !== 'metrics') {
                    foreach ($this->base($report, $surface, $this->records[$case->id][$surface][$side]) as $record) {
                        try {
                            ReportRecords::identity($report, $record);
                        } catch (GateError $error) {
                            $this->identityReady[$case->id][$surface][$side] = false;
                            $this->publicationProblem($side, $key, $error);
                        }
                    }
                }
                if ($report === 'directives') {
                    $document = ReportRecords::decode($text);
                    $exit = $artifacts[$scope . '|exit:directives'] ?? null;
                    if (!\is_int($document['exit_code'] ?? null) || $exit === null || (string) $document['exit_code'] !== $exit) {
                        throw new GateError('The directives JSON exit_code disagrees with this invocation process exit.');
                    }
                }
                if ($report === 'json' && $this->identityReady[$case->id][$surface][$side]) {
                    $raw = ReportRecords::extract('json', $artifacts[$key], $this->fields('json', $surface, $side));
                    try {
                        $observed = $this->ranking->observe($side, $case, $surface, $raw, $artifacts);
                    } catch (GateError $error) {
                        $this->identityReady[$case->id][$surface][$side] = false;
                        $this->run->report->sourceEvidence($side, $key, 'records', false);
                        continue;
                    }
                    $this->publications[$case->id][$surface][$side] = $observed['published'];
                    $this->physical[$case->id][$surface][$side] = $observed['authority'];
                    $this->raw[$case->id][$surface][$side] = $observed['rawAuthority'];
                    $this->records[$case->id][$surface][$side] = $observed['comparative'];
                }
                $this->run->report->sourceEvidence($side, $key, 'records', $this->identityReady[$case->id][$surface][$side]);
            } catch (GateError $error) {
                $this->publicationProblem($side, $key, $error);
            }
        }
        if (!isset($this->records[$case->id]['format:json'][$side])) {
            return;
        }
        $findings = $this->physical[$case->id]['format:json'][$side];
        foreach (['format:html', 'format:gitlab', 'format:sarif'] as $surface) {
            if ($this->withdrawn($side, $case, $surface) || $this->refusal($side, $case, $surface, $artifacts)) {
                continue;
            }
            $key = $scope . '|' . $surface;
            try {
                if (!isset($artifacts[$key])) {
                    throw new GateError('A readable finding projection is missing.');
                }
                $text = $surface === 'format:html' ? ReportPayload::of($artifacts[$key], $key, $side) : $artifacts[$key];
                // Validate the original catalog before maps can rewrite any rule id.
                if ($surface === 'format:sarif') {
                    ReportRecords::projected($surface, $text);
                }
                $projected = ReportRecords::projected($surface, $this->mapped($side, $surface, $text));
                $expected = array_map(fn(array $record): array => ReportRecords::projection($surface, $record, $this->run->publicationCodec($side)), $findings);
                $actual = array_column($projected, 'fields');
                if ($surface === 'format:html') {
                    $buckets = [];
                    foreach ($projected as $entry) {
                        $buckets[ValueCheck::value(\array_slice($entry['path'], 0, -1))][] = $entry['fields'];
                    }
                    foreach ($buckets as $bucket) {
                        $budget = $expected;
                        foreach ($bucket as $record) {
                            $at = array_search($record, $budget, true);
                            if ($at === false) {
                                throw new GateError('An HTML node publishes more projection instances than authoritative records.');
                            }
                            unset($budget[$at]);
                        }
                    }
                    foreach ($actual as $record) {
                        if (!\in_array($record, $expected, true)) {
                            throw new GateError('An HTML node publishes a finding projection absent from its authoritative records.');
                        }
                    }
                    foreach ($expected as $record) {
                        if (!\in_array($record, $actual, true)) {
                            throw new GateError('An authoritative finding has no HTML projection.');
                        }
                    }
                } elseif (!self::sameMultiset($actual, $expected)) {
                    throw new GateError('The readable finding projection differs from the complete authoritative record multiset.');
                }
                $this->run->report->sourceEvidence($side, $key, 'records', true);
            } catch (GateError $error) {
                $this->publicationProblem($side, $key, $error);
            }
        }
        $key = $scope . '|format:checkstyle';
        if (isset($artifacts[$key]) && !$this->withdrawn($side, $case, 'format:checkstyle') && !$this->refusal($side, $case, 'format:checkstyle', $artifacts)) {
            try {
                $actual = ReportRecords::checkstyle($this->mapped($side, 'format:checkstyle', $artifacts[$key]));
                $expected = array_map(fn(array $record): array => ReportRecords::projection('format:checkstyle', $record, $this->run->publicationCodec($side)), $findings);
                if (!self::sameMultiset($actual, $expected)) {
                    throw new GateError('The complete checkstyle projection multiset differs from authoritative records.');
                }
                $this->run->report->sourceEvidence($side, $key, 'records', true);
            } catch (GateError $error) {
                $this->publicationProblem($side, $key, $error);
            }
        }
        foreach (ProseRecords::SURFACES as $surface) {
            if ($this->withdrawn($side, $case, $surface)) {
                continue;
            }
            $key = $scope . '|' . $surface;
            if (!isset($artifacts[$key])) {
                continue;
            }
            try {
                $entries = ProseRecords::extract($surface, $this->mapped($side, $surface, $artifacts[$key]), $this->run->publicationCodec($side));
                $budget = $findings;
                foreach ($entries as $entry) {
                    if ($surface === 'format:summary' && isset($entry['fields']['rank'])) {
                        continue;
                    }
                    $found = false;
                    foreach ($budget as $index => $record) {
                        if (ProseRecords::matches($surface, $entry['fields'], $record, $this->run->publicationCodec($side))) {
                            unset($budget[$index]);
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        throw new GateError('A prose finding line is not the complete projection of an authoritative finding instance.');
                    }
                }
                if ($surface !== 'format:summary' && $budget !== [] && (preg_match('~^\.\.\. and ([0-9]+) more\. Use --detail=all to see all violations$~m', $artifacts[$key], $remaining) !== 1 || (int) $remaining[1] !== \count($budget))) {
                    throw new GateError('The prose publication omitted an authoritative finding projection.');
                }
                $this->run->report->sourceEvidence($side, $key, 'records', true);
            } catch (GateError $error) {
                $this->publicationProblem($side, $key, $error);
            }
        }
        $source = $case->baselineSource() === null ? 'format:json' : 'check:baseline-source';
        $key = $scope . '|baseline-file';
        if (CaseOutcome::applies(CaseOutcome::CHECK_BASELINE_FILE, $outcome) && isset($artifacts[$key])) {
            try {
                $sourceRecords = $this->physical[$case->id][$source][$side] ?? throw new GateError('The baseline source publication is unavailable.');
                ReportRecords::baselineEntries($this->mapped($side, 'baseline-file', $artifacts[$key]), $sourceRecords);
                $this->run->report->sourceEvidence($side, $key, 'records', true);
            } catch (GateError $error) {
                $this->publicationProblem($side, $key, $error);
            }
        }
        foreach (['check:output:file', 'check:parallel'] as $surface) {
            $key = $scope . '|' . $surface;
            if (!isset($artifacts[$key])) {
                continue;
            }
            try {
                $projected = ReportRecords::extract('json', $this->mapped($side, 'format:json', $artifacts[$key]), $this->fields('json', 'format:json', $side));
                if ($projected !== $this->publications[$case->id]['format:json'][$side]) {
                    throw new GateError('A same-input check publication differs from its authoritative JSON records.');
                }
                $this->run->report->sourceEvidence($side, $key, 'records', true);
            } catch (GateError $error) {
                $this->publicationProblem($side, $key, $error);
            }
        }
    }

    /** @param array<string,string> $artifacts */
    private function refusal(string $side, CaseDefinition $case, string $surface, array $artifacts): bool
    {
        $valid = CapturePlan::partialViewRefusal($case, $surface, $artifacts);
        $this->run->report->sourceEvidence($side, 'case:' . $case->id . '|' . $surface, 'refusal', $valid);
        return $valid;
    }

    private function withdrawn(string $side, CaseDefinition $case, string $surface): bool
    {
        return $side === 'candidate' && $this->run->declarations->surfaces->changeFor($surface, $case->id) === DeclaredSurfaces::WITHDRAWN;
    }

    private function publicationProblem(string $side, string $key, GateError $error): void
    {
        $this->run->report->sourceEvidence($side, $key, 'records', false);
        $this->run->report->fail(FailureClass::RECORD_PROJECTION_MISMATCH, $side . ' / ' . $key, $error->getMessage());
    }

    private function mapped(string $side, string $surface, string $text): string
    {
        $mapped = $side === 'reference' ? $this->run->maps->forward($text, $surface) : $text;
        return \in_array($surface, ReportViews::REPORTS['json'], true) ? $mapped : $this->run->normalization->normalize($surface, $mapped);
    }

    /** @return list<string> */
    public function fields(string $report, string $view, string $side): array
    {
        $fields = $report === 'json' ? EquivalenceTuple::load($this->run->options->candidateRoot)->fields : ReportRecords::SCHEMAS[$report];
        if ($report !== 'suppressed') {
            foreach ($this->run->declarations->fields->changes($report, $view) as $field => $change) {
                if ($change === DeclaredFields::ADDED && !\in_array($field, $fields, true)) {
                    $fields[] = $field;
                } elseif ($change === DeclaredFields::REMOVED) {
                    $fields = array_values(array_diff($fields, [$field]));
                }
            }
            if ($side === 'reference') {
                $fields = $this->run->declarations->fields->referenceFields($report, $view, $fields);
            }
        }
        return $fields;
    }

    public function optionalSubject(string $report, string $view): bool
    {
        return $report === 'metrics' && !\array_key_exists('subject', $this->run->declarations->fields->changes($report, $view));
    }

    /** Compare before any projection is erased, independent of surface traversal order. */
    public function prepare(string $case): void
    {
        if (isset($this->prepared[$case])) {
            return;
        }
        $this->prepared[$case] = true;
        $definition = null;
        foreach ($this->run->corpus->cases as $item) {
            if ($item->id === $case) {
                $definition = $item;
                break;
            }
        }
        if ($definition === null) {
            throw new GateError('An unknown case has no authoritative publications.');
        }
        $this->ranking->supplyFields($case);
        foreach (ReportViews::forCase($definition) as $view => $report) {
            $candidate = $this->records[$case][$view]['candidate'] ?? null;
            $reference = $this->records[$case][$view]['reference'] ?? null;
            $left = $this->base($report, $view, $candidate ?? []);
            $right = $this->base($report, $view, $reference ?? []);
            $identityReady = true;
            foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $observed) {
                if ($observed === null) {
                    continue;
                }
                if (!isset($this->identityReady[$case][$view][$side])) {
                    throw new GateError('An observed publication has no identity readiness verdict.');
                }
                $identityReady = $identityReady && $this->identityReady[$case][$view][$side];
            }
            $metricPairs = $report === 'metrics' ? MetricsRecords::pair($left, $right) : null;
            if ($report !== 'suppressed' && $this->run->declarations->fields->changes($report, $view) !== []) {
                foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $records) {
                    if ($records === null) {
                        continue;
                    }
                    $supplied = [];
                    foreach ($records as $index => $record) {
                        $base = $side === 'candidate' ? $left[$index] : $right[$index];
                        $key = DeclaredRecords::canonical($report === 'json' ? RankingSchema::physical($base) : $base);
                        if ($metricPairs !== null) {
                            foreach ($metricPairs['pairs'] as $pair) {
                                if ($pair[$side] === $base) {
                                    $key = $pair['key'];
                                    break;
                                }
                            }
                        }
                        $supplied[] = ['record' => $key, 'fields' => $report === 'json' ? RankingSchema::physical($record) : $record];
                    }
                    $this->run->declarations->fields->supply($report, $case, $view, $side, $supplied);
                }
            }
            if ($this->run->isExactSurface('case:' . $case . '|' . $view)) {
                continue;
            }
            if (!$identityReady || $candidate === null || $reference === null) {
                continue;
            }
            if ($report === 'json' && !$this->run->split->isEmpty()) {
                foreach ($this->run->split->unexplained($this->rawAuthority($case, $view, 'reference'), $this->rawAuthority($case, $view, 'candidate')) as $problem) {
                    $this->run->report->fail(FailureClass::SPLIT_UNMAPPED, 'case:' . $case . '|' . $view, $problem);
                }
            }
            $paired = $metricPairs ?? self::pair($report, $left, $right);
            if ($report === 'json') {
                $this->ranking->prepareRanking($case, $view, $paired['pairs']);
            }
            $positions = ['candidate' => [], 'reference' => []];
            foreach (['candidate' => $left, 'reference' => $right] as $side => $pool) {
                foreach ($pool as $index => $record) {
                    $positions[$side][DeclaredRecords::canonical($record)][] = $index;
                }
            }
            $used = ['candidate' => [], 'reference' => []];
            foreach ($paired['pairs'] as $pair) {
                $a = $pair['candidate'];
                $b = $pair['reference'];
                $originalCandidate = $a;
                $originalReference = $b;
                $candidateKey = DeclaredRecords::canonical($a);
                $referenceKey = DeclaredRecords::canonical($b);
                $candidateIndex = $positions['candidate'][$candidateKey][$used['candidate'][$candidateKey] ?? 0] ?? null;
                $referenceIndex = $positions['reference'][$referenceKey][$used['reference'][$referenceKey] ?? 0] ?? null;
                if (!\is_int($candidateIndex) || !\is_int($referenceIndex)) {
                    throw new GateError('A paired record has no original complete occurrence.');
                }
                $used['candidate'][$candidateKey] = ($used['candidate'][$candidateKey] ?? 0) + 1;
                $used['reference'][$referenceKey] = ($used['reference'][$referenceKey] ?? 0) + 1;
                $subject = 'case:' . $case . '|' . $view . '|record:' . $pair['key'];
                $level = $report === 'metrics' ? MetricsRecords::level($a) : (isset($a['subject']) && \is_string($a['subject']) ? SubjectLevel::of($a['subject']) : 'file');
                foreach (array_keys($a + $b) as $field) {
                    if ($report === 'metrics' && $field === 'metrics') {
                        foreach (array_keys($a['metrics'] + $b['metrics']) as $metric) {
                            $presentCandidate = \array_key_exists($metric, $a['metrics']);
                            $presentReference = \array_key_exists($metric, $b['metrics']);
                            if ($presentCandidate && $presentReference
                                && $this->values->measure(DeclaredValues::METRIC, $metric, $subject, $level, $b['metrics'][$metric], $a['metrics'][$metric])) {
                                $a['metrics'][$metric] = $b['metrics'][$metric];
                            } elseif ($presentCandidate !== $presentReference && $this->values->measure(
                                DeclaredValues::METRIC,
                                $metric,
                                $subject,
                                $level,
                                ['published' => $presentReference, 'value' => $presentReference ? $b['metrics'][$metric] : null],
                                ['published' => $presentCandidate, 'value' => $presentCandidate ? $a['metrics'][$metric] : null],
                            )) {
                                unset($a['metrics'][$metric], $b['metrics'][$metric]);
                            }
                        }
                    } elseif (\array_key_exists($field, $a) && \array_key_exists($field, $b) && $a[$field] !== $b[$field]) {
                        if (\is_string($b[$field]) && \is_string($a[$field]) && $this->run->split->allowsMove($field, $b[$field], $a[$field])) {
                            if ($report === 'json' && $field === 'rule') {
                                foreach ([$a[$field] => -1, $b[$field] => 1] as $rule => $change) {
                                    $this->producerCountChanges[$case][$view][$rule] = ($this->producerCountChanges[$case][$view][$rule] ?? 0) + $change;
                                }
                            }
                            $a[$field] = $b[$field];
                        } elseif (!$this->licensedByOtherForm($case, $view, $field, $b[$field], $a[$field])
                            && $this->values->measure(DeclaredValues::FIELD, $field, $subject, $level, $b[$field], $a[$field])) {
                            $a[$field] = $b[$field];
                        }
                    } elseif (\array_key_exists($field, $a) !== \array_key_exists($field, $b)
                        && $this->values->measure(
                            DeclaredValues::FIELD,
                            $field,
                            $subject,
                            $level,
                            ['published' => \array_key_exists($field, $b), 'value' => $b[$field] ?? null],
                            ['published' => \array_key_exists($field, $a), 'value' => $a[$field] ?? null],
                        )) {
                        unset($a[$field], $b[$field]);
                    }
                }
                $paths = self::changedPaths($originalCandidate, $a);
                foreach (self::changedPaths($originalReference, $b) as $path) {
                    if (!\in_array($path, $paths, true)) {
                        $paths[] = $path;
                    }
                }
                if ($paths !== []) {
                    $this->exactOperations[$case][$view][] = [
                        'candidate' => $candidate[$candidateIndex],
                        'reference' => $reference[$referenceIndex],
                        'paths' => $paths,
                        'whole' => false,
                    ];
                }
                $this->replacements[$case][$view]['candidate'][DeclaredRecords::canonical($pair['candidate'])] = $a;
                $this->replacements[$case][$view]['reference'][DeclaredRecords::canonical($pair['reference'])] = $b;
            }
            foreach (['introduced' => $paired['introduced'], 'withdrawn' => $paired['withdrawn']] as $change => $records) {
                $published = $change === 'introduced' ? $candidate : $reference;
                $bases = $change === 'introduced' ? $left : $right;
                foreach ($records as $record) {
                    $index = array_search($record, $bases, true);
                    if (!\is_int($index) || !isset($published[$index])) {
                        throw new GateError('A residual has no complete published record instance.');
                    }
                    $this->observeResidual($case, $report, $view, $change, $published[$index]);
                    unset($bases[$index]);
                }
            }
        }
    }

    private function licensedByOtherForm(string $case, string $view, string $field, mixed $from, mixed $to): bool
    {
        if (!\is_string($from) || !\is_string($to)) {
            return false;
        }
        $surface = 'case:' . $case . '|' . $view;
        $delta = $this->run->declarations->delta;
        return \in_array($delta->intentOf($surface), $delta->surfaces(), true)
            && $this->run->declarations->fieldMoves->allows($surface, $field, $from, $to);
    }

    /** @param array<string,mixed> $record */
    private function observeResidual(string $case, string $report, string $view, string $change, array $record): void
    {
        $canonical = DeclaredRecords::canonical($record);
        $intent = false;
        foreach ($this->run->declarations->records->intents($report, $view) as $row) {
            if ($row['change'] !== $change || !\in_array($row['case'], [$case, '*'], true)) {
                continue;
            }
            $selector = ReportRecords::decode($row['selector']);
            foreach ($selector as $key => $value) {
                if (!\array_key_exists($key, $record) || $record[$key] !== $value) {
                    continue 2;
                }
            }
            $intent = true;
            break;
        }
        if (!$intent || (!$this->deriving && !$this->run->declarations->records->claim($change, $case, $report, $view, $canonical))) {
            $this->run->report->semanticResidual('case:' . $case . '|' . $view);
            $this->run->report->fail(FailureClass::RECORD_UNDECLARED, 'case:' . $case . '|' . $view, 'No exact declared record instance licenses this ' . $change . ' residual.', [$canonical]);
            return;
        }
        $this->derived[] = [$change, $case, $report, $view, $canonical];
        $this->run->declarations->records->creditMeasurement($change, $case, $report, $view, $canonical);
        $side = $change === DeclaredRecords::INTRODUCED ? 'candidate' : 'reference';
        $this->removed[$case][$view][$side][] = $record;
        $this->exactOperations[$case][$view][] = [
            'candidate' => $side === 'candidate' ? $record : null,
            'reference' => $side === 'reference' ? $record : null,
            'paths' => [],
            'whole' => true,
        ];
    }

    /** @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param list<string> $prefix
     *
     * @return list<list<string>>
     */
    private static function changedPaths(array $before, array $after, array $prefix = []): array
    {
        $paths = [];
        foreach (array_keys($before + $after) as $field) {
            $path = [...$prefix, $field];
            if ($prefix === [] && $field === 'metrics'
                && \array_key_exists($field, $before) && \array_key_exists($field, $after)
                && \is_array($before[$field]) && \is_array($after[$field])) {
                array_push($paths, ...self::changedPaths($before[$field], $after[$field], $path));
            } elseif (($before[$field] ?? null) !== ($after[$field] ?? null)
                || \array_key_exists($field, $before) !== \array_key_exists($field, $after)) {
                $paths[] = $path;
            }
        }
        return $paths;
    }

    /** @return list<array{candidate:?array<string,mixed>,reference:?array<string,mixed>,paths:list<list<string>>,whole:bool}> */
    public function exactOperations(string $case, string $view): array
    {
        return $this->exactOperations[$case][$view] ?? [];
    }

    /**
     * @param list<array<string,mixed>> $records
     *
     * @return list<array<string,mixed>>
     */
    public function base(string $report, string $view, array $records): array
    {
        if ($report === 'suppressed') {
            return $records;
        }
        $fields = array_keys($this->run->declarations->fields->changes($report, $view));
        if ($report === 'json') {
            foreach (array_keys($this->run->declarations->fields->changes('json', 'ranking')) as $field) {
                if (\in_array($field, RankingSchema::VALUES, true)) {
                    $fields[] = 'ranking.' . $field;
                }
            }
        }
        return array_map(static fn(array $record): array => array_diff_key($record, array_flip($fields)), $records);
    }

    /**
     * @param array<string,mixed>|list<mixed> $document
     *
     * @return array<string,int>|null
     */
    public function producerCounts(string $case, string $view, string $side, array $document): ?array
    {
        $changes = $this->producerCountChanges[$case][$view] ?? [];
        if ($changes === []) {
            return null;
        }
        $expected = [];
        foreach ($this->authority($case, $view, $side) as $record) {
            $rule = (string) $record['rule'];
            $expected[$rule] = ($expected[$rule] ?? 0) + 1;
        }
        $counts = $document['violationsMeta']['byRule'] ?? null;
        if (!\is_array($counts)) {
            throw new GateError('Producer grouping requires original physical rule counts.');
        }
        ksort($expected);
        ksort($counts);
        if ($counts !== $expected) {
            throw new GateError('Producer grouping cannot repair original physical rule counts.');
        }
        foreach ($side === 'candidate' ? $changes : [] as $rule => $change) {
            $counts[$rule] = ($counts[$rule] ?? 0) + $change;
            if ($counts[$rule] === 0) {
                unset($counts[$rule]);
            }
        }
        ksort($counts);
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function licensedResiduals(string $case, string $view, string $side): array
    {
        return $this->removed[$case][$view][$side] ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function published(string $case, string $view, string $side): array
    {
        return $this->publications[$case][$view][$side] ?? throw new GateError('A required authoritative record publication is unavailable.');
    }

    /** @return list<array<string,mixed>> */
    public function authority(string $case, string $view, string $side): array
    {
        return $this->physical[$case][$view][$side] ?? throw new GateError('A required physical record authority is unavailable.');
    }

    /** @return list<array<string,mixed>> */
    public function rawAuthority(string $case, string $view, string $side): array
    {
        return $this->raw[$case][$view][$side] ?? throw new GateError('A required raw physical record authority is unavailable.');
    }

    /** @return list<array<string,mixed>> */
    public function comparative(string $case, string $view, string $side): array
    {
        return $this->records[$case][$view][$side] ?? throw new GateError('A required comparative record authority is unavailable.');
    }

    /**
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    public function replacement(string $case, string $view, string $side, array $record): array
    {
        $direct = $this->replacements[$case][$view][$side][DeclaredRecords::canonical($record)] ?? null;
        $json = \in_array($view, ReportViews::REPORTS['json'], true);
        if ($direct !== null) {
            return $json ? RankingSchema::physical($direct) : $direct;
        }
        if (!$json) {
            return $record;
        }
        foreach ($this->replacements[$case][$view][$side] ?? [] as $key => $replacement) {
            if (RankingSchema::physical(ReportRecords::object($key)) === $record) {
                return RankingSchema::physical($replacement);
            }
        }
        return RankingSchema::physical($record);
    }

    /**
     * @param list<array<string,mixed>> $candidate
     * @param list<array<string,mixed>> $reference
     *
     * @return array{pairs:list<array{candidate:array<string,mixed>,reference:array<string,mixed>,key:string}>,introduced:list<array<string,mixed>>,withdrawn:list<array<string,mixed>>}
     */
    private static function pair(string $report, array $candidate, array $reference): array
    {
        $left = [];
        $right = [];
        foreach ($candidate as $record) {
            $left[ReportRecords::identity($report, $record)][] = $record;
        }
        foreach ($reference as $record) {
            $right[ReportRecords::identity($report, $record)][] = $record;
        }
        $pairs = [];
        $introduced = [];
        $withdrawn = [];
        foreach (array_keys($left + $right) as $key) {
            $a = $left[$key] ?? [];
            $b = $right[$key] ?? [];
            if (\count($a) === 1 && \count($b) === 1) {
                $pairs[] = ['candidate' => $a[0], 'reference' => $b[0], 'key' => $key];
                continue;
            }
            foreach ($a as $record) {
                $index = array_search($record, $b, true);
                if ($index === false) {
                    $introduced[] = $record;
                } else {
                    $pairs[] = ['candidate' => $record, 'reference' => $b[$index], 'key' => $key];
                    unset($b[$index]);
                }
            }
            array_push($withdrawn, ...array_values($b));
        }
        return ['pairs' => $pairs, 'introduced' => $introduced, 'withdrawn' => $withdrawn];
    }

    /**
     * @param list<array<string,mixed>> $a
     * @param list<array<string,mixed>> $b
     */
    public static function sameMultiset(array $a, array $b): bool
    {
        $left = array_map(DeclaredRecords::canonical(...), $a);
        $right = array_map(DeclaredRecords::canonical(...), $b);
        sort($left);
        sort($right);
        return $left === $right;
    }

    /**
     * @param array<string,string> $candidate
     * @param array<string,string> $reference
     */
    public function checkRun(array $candidate, array $reference): void
    {
        if ($this->deriving) {
            foreach ($this->run->declarations->records->staleIntents() as $stale) {
                $this->run->report->fail(FailureClass::RECORD_STALE, $stale['scope'], $stale['detail'] . ' measured no residual record.');
            }
        }
    }

    /** @return list<string> */
    public function rewriteDerived(): array
    {
        if (!$this->run->report->canDerive([FailureClass::RECORD_PROJECTION_MISMATCH, FailureClass::RECORD_AMBIGUOUS,
            FailureClass::RECORD_UNDECLARED, FailureClass::RECORD_STALE, FailureClass::NONDETERMINISM_UNDECLARED, FailureClass::PATH_LEAK])) {
            return [];
        }
        $rows = $this->derived;
        usort($rows, static fn(array $a, array $b): int => $a <=> $b);
        return DerivedTable::write($this->run->options->candidateRoot . '/finding-gate', DeclaredRecords::DERIVED, Tsv::render(DeclaredRecords::DERIVED_COLUMNS, $rows), \count($rows));
    }
}
