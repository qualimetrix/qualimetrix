<?php

declare(strict_types=1);

namespace QmxFindingGate;

use WeakMap;
use WeakReference;

/** Side-local ranking evidence enriches the one record correspondence. */
final class RankingCheck implements CaseCheck
{
    /** @var WeakMap<RunContext,WeakReference<self>>|null */
    private static ?WeakMap $runs = null;
    /** @var array<string,array<string,bool>> */
    private array $metadata = [];
    /** @var array<string,array<string,array<string,array{comparative:list<array<string,mixed>>,order:list<int>,slice:int,total:int,publications:list<string>}>>> */
    private array $observed = [];
    /** @var array<string,array<string,list<array{record:string,fields:array<string,mixed>}>>> */
    private array $fieldMeasurements = [];
    /** @var array<string,true> */
    private array $supplied = [];
    private int $ambiguities = 0;

    private function __construct(private readonly RunContext $run, private readonly ValueCheck $values) {}

    public static function create(RunContext $run): static
    {
        self::$runs ??= new WeakMap();
        $check = isset(self::$runs[$run]) ? self::$runs[$run]->get() : null;
        if ($check instanceof self) {
            return $check;
        }
        $check = new self($run, ValueCheck::create($run));
        self::$runs[$run] = WeakReference::create($check);
        return $check;
    }

    public function trialCopy(RunContext $trial): self
    {
        $copy = self::create($trial);
        $copy->metadata = $this->metadata;
        $copy->observed = $this->observed;
        $copy->fieldMeasurements = $this->fieldMeasurements;
        return $copy;
    }

    public function name(): string
    {
        return CaseOutcome::CHECK_RANKING;
    }

    public function checkCase(string $side, CaseDefinition $case, string $outcome, array $artifacts): void
    {
        if (!CaseOutcome::applies($this->name(), $outcome)) {
            return;
        }
        foreach ($this->run->capturePlan->rankingInvocations() as $descriptor) {
            $key = Surfaces::key($descriptor['scope'], $descriptor['surface']);
            if ($descriptor['scope'] === 'case:' . $case->id && $this->run->capturePlan->requiredOn($key, $side)) {
                $this->checkCaptureMetadata($side, $key, $artifacts);
            }
        }
    }

    public function checkRepeatedCaptures(CaptureResult $first, CaptureResult $second): void
    {
        if ($first->baselineEligibility !== $second->baselineEligibility) {
            foreach (array_keys($first->baselineEligibility + $second->baselineEligibility) as $source) {
                $this->run->report->sourceEvidence('candidate', $source, 'repeatable', ($first->baselineEligibility[$source] ?? null) === ($second->baselineEligibility[$source] ?? null));
            }
            $this->run->report->fail(FailureClass::NONDETERMINISM_UNDECLARED, 'baseline eligibility', 'The same candidate inputs produced different product baseline eligibility decisions.');
        }
        $records = RecordCheck::create($this->run);
        $fields = null;
        foreach ($this->run->corpus->cases as $case) {
            if (!CaseOutcome::applies(CaseOutcome::CHECK_FINDINGS, CaseOutcome::of($case, 'candidate'))) {
                continue;
            }
            foreach ($this->run->capturePlan->rankingInvocations() as $descriptor) {
                $key = Surfaces::key($descriptor['scope'], $descriptor['surface']);
                if ($descriptor['scope'] !== 'case:' . $case->id || !$this->run->capturePlan->requiredOn($key, 'candidate')) {
                    continue;
                }
                if ($this->run->report->sourceRejected('candidate', $key, 'ranking')) {
                    $this->run->report->sourceEvidence('candidate', $key, 'repeatable', false);
                    continue;
                }
                $fields ??= $this->fields('candidate');
                $bags = [];
                foreach ([$first, $second] as $capture) {
                    $slot = $capture->rankings[$key] ?? throw new GateError('A repeated capture has no validated own ranking: ' . $key);
                    $physical = $slot['physical'] ?? $slot['ranked'];
                    $physicalRecords = ReportRecords::extract('json', $physical['stdout'], $records->fields('json', $descriptor['surface'], 'candidate'));
                    $rankedRecords = RankingSchema::records(ReportRecords::decode($slot['ranked']['stdout']), $fields);
                    $rankedRecords = array_map(static fn(array $record): array => array_diff_key($record, ['rank' => true]), $rankedRecords);
                    $bags[] = [
                        self::captureBag($physicalRecords),
                        self::captureBag($rankedRecords),
                        ExactSurfaceAuthority::rawPopulation($slot, $key, 'candidate', $this->run),
                    ];
                }
                if ($bags[0] !== $bags[1]) {
                    $this->run->report->sourceEvidence('candidate', $key, 'repeatable', false);
                    $this->run->report->fail(FailureClass::NONDETERMINISM_UNDECLARED, $key, 'Complete physical or ranked values changed between candidate passes.');
                } else {
                    $this->run->report->sourceEvidence('candidate', $key, 'repeatable', true);
                }
            }
        }
    }

    /** @param list<array<string,mixed>> $records
     * @return list<string>
     */
    private static function captureBag(array $records): array
    {
        $labels = array_map(static fn(array $record): string => ValueCheck::value(self::captureValue($record)), $records);
        sort($labels);
        return $labels;
    }

    private static function captureValue(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::captureValue(...), $value);
    }

    /** @param array<string,string> $artifacts */
    public function checkCaptureMetadata(string $side, string $key, array $artifacts): bool
    {
        if (isset($this->metadata[$side][$key])) {
            return $this->metadata[$side][$key];
        }
        try {
            $capture = $this->run->rankings->of($side, $key);
            $descriptor = $this->run->capturePlan->descriptorOf($key);
            $exitKey = Surfaces::key($descriptor['scope'], 'exit:' . $descriptor['surface']);
            $stderrKey = Surfaces::key($descriptor['scope'], 'stderr:' . $descriptor['surface']);
            $stderrSurface = Surfaces::surfaceClass($stderrKey);
            foreach ([$capture['ranked'], $capture['physical']] as $result) {
                if ($result !== null && ((!isset($artifacts[$exitKey], $artifacts[$stderrKey]))
                    || (string) $result['exit'] !== $artifacts[$exitKey]
                    || $this->run->normalization->normalizeCaptureMetadata($stderrSurface, $result['stderr']) !== $this->run->normalization->normalizeCaptureMetadata($stderrSurface, $artifacts[$stderrKey]))) {
                    throw new GateError('Internal ranking metadata differs from its original invocation exit or stderr.');
                }
            }
            return $this->metadata[$side][$key] = true;
        } catch (GateError $error) {
            $this->projectionProblem($side, $key, $error);
            return $this->metadata[$side][$key] = false;
        }
    }

    /** @param list<array<string,mixed>> $published
     * @param array<string,string> $artifacts
     *
     * @return array{published:list<array<string,mixed>>,authority:list<array<string,mixed>>,comparative:list<array<string,mixed>>,rawAuthority:list<array<string,mixed>>}
     */
    public function observe(string $side, CaseDefinition $case, string $view, array $published, array $artifacts): array
    {
        $key = 'case:' . $case->id . '|' . $view;
        if (!$this->checkCaptureMetadata($side, $key, $artifacts)) {
            throw new GateError('The ranking capture metadata is not authoritative.');
        }
        $before = $this->ambiguities;
        try {
            $observed = $this->anatomy($side, $case, $view, $published, $artifacts);
            $this->run->report->sourceEvidence($side, $key, 'ranking', true);
            return $observed;
        } catch (GateError $error) {
            $this->run->report->sourceEvidence($side, $key, 'ranking', false);
            if ($before === $this->ambiguities) {
                $this->projectionProblem($side, $key, $error);
            }
            throw $error;
        }
    }

    private function projectionProblem(string $side, string $key, GateError $error): void
    {
        $this->run->report->sourceEvidence($side, $key, 'ranking', false);
        $this->run->report->fail(FailureClass::RANKING_PROJECTION_MISMATCH, $side . ' / ' . $key, $error->getMessage());
    }

    /** @return list<string> */
    private function fields(string $side): array
    {
        $fields = RankingSchema::derive($this->run->options->candidateRoot)->fields;
        $changes = $this->run->declarations->fields->changes('json', 'ranking');
        $expected = RankingSchema::FIELDS;
        foreach ($changes as $field => $change) {
            if ($change === DeclaredFields::ADDED && !\in_array($field, $expected, true)) {
                $expected[] = $field;
            } elseif ($change === DeclaredFields::REMOVED) {
                $expected = array_values(array_diff($expected, [$field]));
            }
        }
        $actual = $fields;
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new GateError('The complete ranked publisher classification has an undeclared field change.');
        }
        return $side === 'reference' ? $this->run->declarations->fields->referenceFields('json', 'ranking', $fields) : $fields;
    }

    /** @param list<array<string,mixed>> $published
     * @param array<string,string> $artifacts
     *
     * @return array{published:list<array<string,mixed>>,authority:list<array<string,mixed>>,comparative:list<array<string,mixed>>,rawAuthority:list<array<string,mixed>>}
     */
    private function anatomy(string $side, CaseDefinition $case, string $view, array $published, array $artifacts): array
    {
        $key = 'case:' . $case->id . '|' . $view;
        $originalText = $artifacts[$key] ?? throw new GateError('The original ranking source publication is missing.');
        $original = ReportRecords::decode($originalText);
        $meta = self::meta($original, \count($published));
        $slot = $this->run->rankings->of($side, $key);
        $fullText = $slot['ranked']['stdout'];
        $full = ReportRecords::decode($fullText);
        $fields = $this->fields($side);
        $issues = RankingSchema::records($full, $fields);
        $slice = RankingSchema::records($original, $fields);
        if (\count($issues) !== $meta['total'] || ReportRecords::rawRecords($originalText, 'topIssues') !== \array_slice(ReportRecords::rawRecords($fullText, 'topIssues'), 0, \count($slice))) {
            throw new GateError('The original ranked slice is not the raw prefix of its complete ranking.');
        }
        $physicalFields = EquivalenceTuple::load($this->run->options->candidateRoot)->fields;
        foreach ($this->run->declarations->fields->changes('json', $view) as $field => $change) {
            if ($change === DeclaredFields::ADDED && !\in_array($field, $physicalFields, true)) {
                $physicalFields[] = $field;
            } elseif ($change === DeclaredFields::REMOVED) {
                $physicalFields = array_values(array_diff($physicalFields, [$field]));
            }
        }
        if ($side === 'reference') {
            $physicalFields = $this->run->declarations->fields->referenceFields('json', $view, $physicalFields);
        }
        $rankedPhysical = ReportRecords::extract('json', $fullText, $physicalFields);
        $rankedMeta = self::meta($full, \count($rankedPhysical));
        if ($rankedMeta !== $meta || $rankedPhysical !== $published) {
            throw new GateError('The same-argument full ranking changed the original physical publication or metadata.');
        }
        $authority = $published;
        if ($meta['truncated']) {
            $support = $slot['physical'] ?? throw new GateError('A truncated ranking requires complete physical support.');
            $supportText = $support['stdout'];
            $supportDocument = ReportRecords::decode($supportText);
            $authority = ReportRecords::extract('json', $supportText, $physicalFields);
            $supportMeta = self::meta($supportDocument, \count($authority));
            if ($supportMeta['truncated'] || $supportMeta['total'] !== $meta['total'] || $supportMeta['byRule'] !== $meta['byRule']
                || ReportRecords::rawRecords($supportText, 'topIssues') !== ReportRecords::rawRecords($fullText, 'topIssues')) {
                throw new GateError('Complete physical support changed the full ranking or original population metadata.');
            }
            $budget = $authority;
            foreach ($published as $record) {
                $at = array_search($record, $budget, true);
                if ($at === false) {
                    throw new GateError('Complete physical support changed an original finding occurrence.');
                }
                unset($budget[$at]);
            }
        } elseif ($slot['physical'] !== null) {
            throw new GateError('An untruncated publication must use its original physical authority.');
        }
        if (\count($authority) !== \count($issues)) {
            throw new GateError('The full physical and ranked occurrence populations differ.');
        }
        $byRule = [];
        foreach ($authority as $record) {
            $rule = (string) $record['rule'];
            $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
        }
        $expectedByRule = $meta['byRule'];
        ksort($byRule);
        ksort($expectedByRule);
        if ($byRule !== $expectedByRule) {
            throw new GateError('Complete physical rule counts disagree with original population metadata.');
        }
        $counts = [];
        foreach ($authority as $record) {
            $join = RankingSchema::joinKey($record, false, $fields);
            $counts[$join] = ($counts[$join] ?? 0) + 1;
        }
        $budget = $authority;
        $comparative = $authority;
        $order = [];
        $values = [];
        if ($this->run->declarations->fields->changes('json', 'ranking') !== []) {
            $this->fieldMeasurements[$case->id][$side] ??= [];
        }
        foreach ($issues as $issue) {
            $join = RankingSchema::joinKey($issue, true, $fields);
            $encodedValue = ValueCheck::value(RankingSchema::values($issue));
            if (($counts[$join] ?? 0) > 1 && isset($values[$join]) && $values[$join] !== $encodedValue) {
                ++$this->ambiguities;
                $this->run->report->fail(FailureClass::RECORD_AMBIGUOUS, $side . ' / ' . $key, 'A repeated ranked join has distinct values: ' . $join, [$values[$join], $encodedValue]);
                throw new GateError('Repeated ranked findings have ambiguous value correspondence.');
            }
            $values[$join] = $encodedValue;
            $found = null;
            foreach ($budget as $index => $record) {
                if (RankingSchema::joinKey($record, false, $fields) === $join) {
                    $found = $index;
                    break;
                }
            }
            if ($found === null) {
                throw new GateError('A full ranked row has no remaining complete physical occurrence.');
            }
            unset($budget[$found]);
            $order[] = $found;
            foreach (RankingSchema::values($issue) as $field => $value) {
                $comparative[$found]['ranking.' . $field] = $value;
            }
            if ($this->run->declarations->fields->changes('json', 'ranking') !== []) {
                $mappedIssue = $this->mapped($side, $view, $issue);
                $this->fieldMeasurements[$case->id][$side][] = ['record' => 'source:' . $view . '|join:' . RankingSchema::joinKey($mappedIssue, true, array_values(array_diff($fields, array_keys($this->run->declarations->fields->changes('json', 'ranking'))))), 'fields' => $mappedIssue];
            }
        }
        if ($budget !== []) {
            throw new GateError('Complete physical authority has findings absent from its full ranking.');
        }
        $publications = [$view];
        foreach (['check:output:file', 'check:parallel'] as $alias) {
            $aliasKey = 'case:' . $case->id . '|' . $alias;
            if ($view === 'format:json' && isset($artifacts[$aliasKey]) && ReportRecords::rawRecords($artifacts[$aliasKey], 'topIssues') !== ReportRecords::rawRecords($originalText, 'topIssues')) {
                throw new GateError('A same-input alias changed its original ranked slice.');
            }
            if ($view === 'format:json' && isset($artifacts[$aliasKey])) {
                $publications[] = $alias;
            }
        }
        if ($view === 'format:json') {
            $this->summary($artifacts['case:' . $case->id . '|format:summary'] ?? throw new GateError('The ranked summary projection is missing.'), $issues, $authority, $order, \count($slice));
        }
        $mapped = array_map(fn(array $record): array => $this->mapped($side, $view, $record), $comparative);
        $this->observed[$case->id][$view][$side] = ['comparative' => $mapped, 'order' => $order, 'slice' => \count($slice), 'total' => \count($issues), 'publications' => $publications];
        return ['rawAuthority' => $authority, 'published' => array_map(fn(array $record): array => $this->mapped($side, $view, $record), $published), 'authority' => array_map(fn(array $record): array => $this->mapped($side, $view, $record), $authority), 'comparative' => $mapped];
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function mapped(string $side, string $view, array $record): array
    {
        return $side === 'reference' ? ReportRecords::object($this->run->maps->forward(ValueCheck::value($record), $view)) : $record;
    }

    /** @param array<string,mixed>|list<mixed> $document
     * @return array{total:int,shown:int,truncated:bool,byRule:array<string,int>}
     */
    private static function meta(array $document, int $shown): array
    {
        $meta = $document['violationsMeta'] ?? null;
        if (!\is_array($meta) || !\is_int($meta['total'] ?? null) || $meta['total'] < 0 || $meta['total'] === \PHP_INT_MAX
            || ($meta['shown'] ?? null) !== $shown || !\is_bool($meta['truncated'] ?? null) || !\is_array($meta['byRule'] ?? null)
            || ($meta['truncated'] ? $shown >= $meta['total'] : $shown !== $meta['total'])) {
            throw new GateError('The physical publication has invalid total, shown or truncation metadata.');
        }
        foreach ($meta['byRule'] as $rule => $count) {
            if (!\is_string($rule) || !\is_int($count) || $count < 0) {
                throw new GateError('Physical population metadata requires named nonnegative rule counts.');
            }
        }
        if (array_sum($meta['byRule']) !== $meta['total']) {
            throw new GateError('Physical rule counts disagree with the full population total.');
        }
        return ['total' => $meta['total'], 'shown' => $shown, 'truncated' => $meta['truncated'], 'byRule' => $meta['byRule']];
    }

    /** @param list<array<string,mixed>> $issues
     * @param list<array<string,mixed>> $authority
     * @param list<int> $order
     */
    private function summary(string $text, array $issues, array $authority, array $order, int $size): void
    {
        $entries = array_values(array_filter(ProseRecords::extract('format:summary', $text), static fn(array $entry): bool => isset($entry['fields']['rank'])));
        if (\count($entries) !== $size) {
            throw new GateError('The ranked summary row count differs from its original JSON slice.');
        }
        foreach ($entries as $index => $entry) {
            $row = $entry['fields'];
            $issue = $issues[$index];
            if (!ProseRecords::matches('format:summary', $row, $authority[$order[$index]]) || $row['rank'] !== $index + 1
                || (isset($issue['debtMinutes']) && $row['debt'] !== self::debt((int) $issue['debtMinutes']))) {
                throw new GateError('A ranked summary row changed its physical projection, ordinal, tag or debt.');
            }
            if (isset($issue['impactScore'])) {
                $score = (string) $row['score'];
                $dot = strpos($score, '.');
                $digits = $dot === false ? 0 : \strlen($score) - $dot - 1;
                if ($digits > 2 || abs((float) $score - (float) $issue['impactScore']) > 0.5 * 10 ** (-$digits) + 0.005 + 1e-12) {
                    throw new GateError('A ranked summary score differs from its published JSON value.');
                }
            }
        }
    }

    private static function debt(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0min';
        }
        $parts = [];
        foreach ([480 => 'd', 60 => 'h', 1 => 'min'] as $unit => $suffix) {
            if ($minutes >= $unit) {
                $parts[] = intdiv($minutes, $unit) . $suffix;
                $minutes %= $unit;
            }
        }
        return implode(' ', $parts);
    }

    public function supplyFields(string $case): void
    {
        if ($this->run->declarations->fields->changes('json', 'ranking') === []) {
            return;
        }
        foreach (['candidate', 'reference'] as $side) {
            $key = $case . '|' . $side;
            if (isset($this->supplied[$key])) {
                continue;
            }
            if (!isset($this->fieldMeasurements[$case][$side])) {
                continue;
            }
            $this->run->declarations->fields->supply('json', $case, 'ranking', $side, $this->fieldMeasurements[$case][$side]);
            $this->supplied[$key] = true;
        }
    }

    public function observed(string $case, string $view, string $side): bool
    {
        return isset($this->observed[$case][$view][$side]);
    }

    /** @param list<array{candidate:array<string,mixed>,reference:array<string,mixed>,key:string}> $pairs */
    public function prepareRanking(string $case, string $view, array $pairs): void
    {
        $sides = $this->observed[$case][$view] ?? [];
        if (!isset($sides['candidate'], $sides['reference'])) {
            return;
        }
        $labels = [];
        $eligible = [];
        foreach ($pairs as $index => $pair) {
            $labels[$index] = DeclaredRecords::canonical($pair['reference']);
            $eligible[$index] = array_intersect_key($pair['candidate'], array_flip(['ranking.impactScore', 'ranking.coupling.class-rank']))
                === array_intersect_key($pair['reference'], array_flip(['ranking.impactScore', 'ranking.coupling.class-rank']));
        }
        $sequences = [];
        $visible = [];
        foreach (['candidate', 'reference'] as $side) {
            $budget = $pairs;
            foreach ($sides[$side]['order'] as $position => $physicalIndex) {
                $record = $sides[$side]['comparative'][$physicalIndex];
                $record = $this->base($view, $record);
                foreach ($budget as $index => $pair) {
                    if ($pair[$side] === $record) {
                        $sequences[$side][] = ['label' => $labels[$index], 'eligible' => $eligible[$index]];
                        if ($position < $sides[$side]['slice']) {
                            $visible[$labels[$index]] = true;
                        }
                        unset($budget[$index]);
                        break;
                    }
                }
            }
        }
        $lists = [];
        foreach (['candidate', 'reference'] as $side) {
            $lists[$side] = array_column(array_values(array_filter($sequences[$side] ?? [], static fn(array $row): bool => $row['eligible'] && isset($visible[$row['label']]))), 'label');
        }
        $measurement = RankingOrder::measure($lists['reference'], $lists['candidate']);
        $intended = false;
        foreach ($this->run->declarations->values->intents() as $intent) {
            $intended = $intended || ($intent['kind'] === DeclaredValues::ORDER && $intent['key'] === 'ranking');
        }
        foreach ($measurement['moved'] as $movement) {
            $subject = 'case:' . $case . '|' . $view . '|record:' . $movement['label'] . '|occurrence:' . $movement['occurrence'];
            if (!$intended) {
                $this->run->report->semanticResidual('case:' . $case . '|' . $view);
                $this->run->report->fail(FailureClass::RANKING_ORDER_MISMATCH, $subject, 'An unchanged ranked finding moved relative to other unchanged findings.');
            } else {
                $this->values->measure(DeclaredValues::ORDER, 'ranking', $subject, '*', $movement['referencePosition'], $movement['candidatePosition']);
            }
        }
        $a = $sides['candidate'];
        $b = $sides['reference'];
        $aExact = $a['slice'] < $a['total'];
        $bExact = $b['slice'] < $b['total'];
        if (($aExact && $bExact && $a['slice'] !== $b['slice']) || ($aExact && !$bExact && $a['slice'] < $b['total']) || (!$aExact && $bExact && $b['slice'] < $a['total'])) {
            foreach (array_intersect($a['publications'], $b['publications']) as $publication) {
                $this->values->measure(DeclaredValues::FIELD, 'topIssues.limit', 'case:' . $case . '|' . $publication, '*', ($bExact ? '=' : '>=') . $b['slice'], ($aExact ? '=' : '>=') . $a['slice']);
            }
        }
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function base(string $view, array $record): array
    {
        $fields = array_keys($this->run->declarations->fields->changes('json', $view));
        foreach (array_keys($this->run->declarations->fields->changes('json', 'ranking')) as $field) {
            if (\in_array($field, RankingSchema::VALUES, true)) {
                $fields[] = 'ranking.' . $field;
            }
        }
        return array_diff_key($record, array_flip($fields));
    }
}
