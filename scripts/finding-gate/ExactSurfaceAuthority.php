<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Full visible publication and occurrence-preserving private record evidence. */
final class ExactSurfaceAuthority
{
    /** @param array{candidate:CaptureResult,reference:CaptureResult} $captures
     * @return array{string,string}
     */
    public static function pair(SurfacePair $pair, array $captures, RunContext $run): array
    {
        foreach ($captures as $side => $capture) {
            $run->publicationForms->supply($side, $capture->artifacts);
        }
        return [
            self::one($pair, 'candidate', $captures['candidate'], $run),
            self::one($pair, 'reference', $captures['reference'], $run),
        ];
    }

    /**
     * @return array{
     *   rawSources:list<string>,residualViews:list<string>,
     *   required:list<array{side:string,key:string,role:string}>,
     *   schemas:list<array{side:string,key:string,role:string,supplied:bool}>
     * }
     */
    public static function footprint(string $key, RunContext $run): array
    {
        $surface = Surfaces::surfaceClass($key);
        $case = str_starts_with($key, 'case:') ? substr($key, 5, (int) strpos($key, '|') - 5) : null;
        if ($run->publicationForms->recordsPair($key) === false) {
            $required = [];
            foreach ($run->publicationForms->invocationArtifacts($key) as $artifact) {
                foreach (['candidate', 'reference'] as $side) {
                    foreach (['capture', 'surface', 'normalization', 'path'] as $role) {
                        $required[] = ['side' => $side, 'key' => $artifact, 'role' => $role];
                    }
                    if ($side === 'candidate') {
                        $required[] = ['side' => $side, 'key' => $artifact, 'role' => 'repeatable'];
                    }
                }
            }
            return ['rawSources' => [], 'residualViews' => [$key], 'required' => $required, 'schemas' => []];
        }
        $bearing = $case !== null && ReportViews::recordBearingSurface($surface);
        $refusalSides = [];
        if ($surface === 'baseline-file') {
            foreach (['candidate', 'reference'] as $side) {
                $refusalSides[$side] = self::declaredBaselineRefusal($key, $side, $run);
            }
        }
        $source = $bearing ? self::source($surface, $case) : null;
        $rawSources = $source === null ? [] : [$source];
        $residualViews = [$key];
        if ($source !== null && $source !== $key) {
            $residualViews[] = $source;
        }
        $sources = [$key => ['capture', 'surface', 'normalization', 'path']];
        $schemaViews = [];
        if ($bearing) {
            if ($source !== null) {
                $sources[$source] = array_values(array_unique([...($sources[$source] ?? []), 'capture', 'surface', 'normalization', 'path', 'records']));
                if (!\in_array($surface, ['format:metrics', 'format:suppressed', 'directives'], true)) {
                    $sources[$source][] = 'ranking';
                    $sourceView = Surfaces::surfaceClass($source);
                    $schemaViews['json' . "\0" . $sourceView] = true;
                    $schemaViews['json-document' . "\0" . $sourceView] = true;
                    $schemaViews['json' . "\0" . 'ranking'] = true;
                } elseif ($surface !== 'format:suppressed') {
                    $schemaViews[($surface === 'format:metrics' ? 'metrics' : 'directives') . "\0" . $surface] = true;
                }
            }
            $sources['case:' . $case . '|format:json'][] = 'outcome';
            if ($surface !== 'baseline-file' && !\in_array($surface, ['baseline:cleanup:file', 'baseline:rename-channels:file', 'baseline:update:file'], true)) {
                $sources[$key] = array_values(array_unique([...($sources[$key] ?? []), 'records']));
            }
            if (\in_array($surface, ['format:sarif', 'format:gitlab'], true)) {
                $sources[$key][] = 'fingerprint';
            }
            if (\in_array($surface, ['check:output:file', 'check:parallel'], true)) {
                $schemaViews['json-document' . "\0" . $surface] = true;
            }
            if (\in_array($surface, ['baseline-file', 'baseline:cleanup:file', 'baseline:rename-channels:file', 'baseline:update:file'], true)) {
                $schemaViews['json' . "\0" . 'format:json'] = true;
                $schemaViews['json' . "\0" . 'ranking'] = true;
            }
            if ($surface === 'baseline-file') {
                $sources[$key][] = 'records';
                $sources[$key][] = 'outcome';
                $definition = null;
                foreach ($run->corpus->cases as $item) {
                    if ($item->id === $case) {
                        $definition = $item;
                        break;
                    }
                }
                $baselineSource = 'case:' . $case . '|' . ($definition?->baselineSource() === null ? 'format:json' : 'check:baseline-source');
                $sources[$baselineSource] = array_values(array_unique([...($sources[$baselineSource] ?? []), 'capture', 'surface', 'normalization', 'path', 'records', 'ranking']));
                $baselineView = Surfaces::surfaceClass($baselineSource);
                $schemaViews['json' . "\0" . $baselineView] = true;
                $schemaViews['json-document' . "\0" . $baselineView] = true;
                $schemaViews['json' . "\0" . 'ranking'] = true;
            }
        }
        $schemas = [];
        if ($case !== null) {
            foreach ($run->declarations->fields->requiredPublications($case) as $publication) {
                if (($refusalSides[$publication['side']] ?? false)
                    || !isset($schemaViews[$publication['report'] . "\0" . $publication['view']])) {
                    continue;
                }
                $schemas[] = [
                    'side' => $publication['side'],
                    'key' => 'case:' . $case . '|' . $publication['view'],
                    'role' => 'schema',
                    'supplied' => $publication['supplied'],
                ];
            }
        }
        $required = [];
        foreach ($sources as $sourceKey => $roles) {
            foreach (['candidate', 'reference'] as $side) {
                $sideRoles = ($refusalSides[$side] ?? false)
                    ? ['capture', 'surface', 'normalization', 'path', 'outcome']
                    : array_unique($roles);
                foreach ($sideRoles as $role) {
                    $required[] = ['side' => $side, 'key' => $sourceKey, 'role' => $role];
                }
                if ($side === 'candidate') {
                    $required[] = ['side' => $side, 'key' => $sourceKey, 'role' => 'repeatable'];
                }
            }
        }
        if ($bearing && !\in_array($surface, ['format:metrics', 'format:suppressed', 'directives'], true)) {
            $required[] = ['side' => '*', 'key' => 'finding', 'role' => 'tuple-schema'];
            foreach (['candidate', 'reference'] as $side) {
                if (!($refusalSides[$side] ?? false)) {
                    $required[] = ['side' => $side, 'key' => 'case:' . $case . '|format:json', 'role' => 'tuple'];
                }
            }
        }
        return ['rawSources' => $rawSources, 'residualViews' => $residualViews, 'required' => $required, 'schemas' => $schemas];
    }

    private static function one(SurfacePair $pair, string $side, CaptureResult $capture, RunContext $run): string
    {
        $visible = $side === 'candidate' ? $pair->candidate : $pair->reference;
        if ($visible === null) {
            throw new GateError('An exact surface has no complete visible publication: ' . $pair->key);
        }
        $framed = self::frame('visible', $visible);
        if ($run->publicationForms->recordsPair($pair->key) === false) {
            if ($pair->surface === 'baseline-file') {
                $scope = substr($pair->key, 0, (int) strpos($pair->key, '|'));
                $exit = $capture->artifacts[$scope . '|exit:baseline:generate'] ?? null;
                if ($exit !== null && $exit !== '0' && ($visible !== '' || ($capture->artifacts[$pair->key] ?? '') !== '')) {
                    throw new GateError('A refusing baseline invocation must retain empty captured baseline content.');
                }
            }
            return $framed . self::invocationFrame($pair->key, $side, $capture, $run);
        }
        if ($pair->surface === 'baseline-file' && self::declaredBaselineRefusal($pair->key, $side, $run)) {
            return $framed . self::baselineRefusalFrame($pair->key, $visible, $capture, $run);
        }
        if (!ReportViews::recordBearingSurface($pair->surface)) {
            return $framed;
        }
        $footprint = self::footprint($pair->key, $run);
        $source = $footprint['rawSources'][0] ?? null;
        if ($source === null) {
            return $framed . self::frame('records', self::canonical($visible));
        }
        if (\in_array($pair->surface, ['format:metrics', 'format:suppressed', 'directives'], true)) {
            $member = match ($pair->surface) {
                'format:metrics' => 'symbols',
                'format:suppressed' => 'suppressed',
                default => 'directives',
            };
            $records = ReportRecords::rawRecords($visible, $member);
            $rows = array_map(self::canonical(...), $records);
            sort($rows, \SORT_STRING);
            return $framed . self::frame('records', json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        }
        $slot = $capture->rankings[$source] ?? throw new GateError('An exact surface has no complete finding authority: ' . $source);
        [$physical, $ranking] = self::rawPopulation($slot, $source, $side, $run);
        return $framed . self::frame('physical-records', $physical) . self::frame('ranking-records', $ranking);
    }

    private static function declaredBaselineRefusal(string $key, string $side, RunContext $run): bool
    {
        if ($run->publicationForms->recordsPair($key) === false) {
            return false;
        }
        if (Surfaces::surfaceClass($key) !== 'baseline-file' || !str_starts_with($key, 'case:')) {
            return false;
        }
        $case = substr($key, 5, (int) strpos($key, '|') - 5);
        if ($run->declarations->outcomes->of($case) === null || !$run->declarations->exactSurfaces->has($key)) {
            return false;
        }
        foreach ($run->corpus->cases as $definition) {
            if ($definition->id === $case) {
                return CaseOutcome::of($definition, $side) === CaseOutcome::REFUSAL;
            }
        }
        return false;
    }

    private static function baselineRefusalFrame(string $key, string $visible, CaptureResult $capture, RunContext $run): string
    {
        $scope = substr($key, 0, (int) strpos($key, '|'));
        $file = $capture->artifacts[$key] ?? null;
        $exit = $capture->artifacts[$scope . '|exit:baseline:generate'] ?? null;
        $stderr = $capture->artifacts[$scope . '|stderr:baseline-file'] ?? null;
        $refusal = $capture->artifacts[$scope . '|format:json'] ?? null;
        $envelope = \is_string($refusal) ? json_decode($refusal, true) : null;
        if ($visible !== '' || $file !== '' || !\is_string($exit) || !ctype_digit($exit)
            || (int) $exit < 1 || (int) $exit > 255 || $exit === '70'
            || !\is_string($stderr) || $stderr === '' || !\is_array($envelope)
            || !\is_string($envelope['error'] ?? null) || $envelope['error'] === '') {
            throw new GateError('A declared baseline refusal requires empty captured baseline content, a non-analysis exit, stderr and a JSON refusal.');
        }
        return self::frame('baseline-exit', $exit)
            . self::frame('baseline-stderr', $run->normalization->normalize('stderr:baseline-file', $stderr))
            . self::frame('refusal', $run->normalization->normalize('format:json', $refusal));
    }

    /** @param array{ranked:array{stdout:string,stderr:string,exit:int},physical:?array{stdout:string,stderr:string,exit:int}} $slot
     * @return array{string,string}
     */
    public static function rawPopulation(array $slot, string $source, string $side, RunContext $run): array
    {
        $physicalText = $slot['physical']['stdout'] ?? $slot['ranked']['stdout'];
        $rankingText = $slot['ranked']['stdout'];
        $surface = Surfaces::surfaceClass($source);
        $physical = [];
        foreach (ReportRecords::rawRecords($physicalText, 'violations') as $raw) {
            $mapped = $side === 'reference' ? $run->maps->forward($raw, $surface) : $raw;
            $physical[] = self::canonical($mapped);
        }
        $ranking = [];
        foreach (ReportRecords::rawRecords($rankingText, 'topIssues') as $raw) {
            $mapped = $side === 'reference' ? $run->maps->forward($raw, $surface) : $raw;
            $members = self::objectMembers($mapped);
            unset($members['rank']);
            ksort($members, \SORT_STRING);
            $parts = [];
            foreach ($members as $name => $value) {
                $parts[] = json_encode($name, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . ':' . $value;
            }
            $ranking[] = '{' . implode(',', $parts) . '}';
        }
        sort($physical, \SORT_STRING);
        sort($ranking, \SORT_STRING);
        return [
            json_encode($physical, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            json_encode($ranking, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @param array{candidate:CaptureResult,reference:CaptureResult} $captures */
    public static function rawResidual(string $source, array $captures, RunContext $run, RecordCheck $records): bool
    {
        foreach ($captures as $side => $capture) {
            $run->publicationForms->supply($side, $capture->artifacts);
        }
        if ($run->publicationForms->recordsPair($source) === false) {
            return self::invocationFrame($source, 'candidate', $captures['candidate'], $run)
                !== self::invocationFrame($source, 'reference', $captures['reference'], $run);
        }
        if (Surfaces::surfaceClass($source) === 'baseline-file') {
            return self::canonical($captures['candidate']->artifacts[$source]) !== self::canonical($captures['reference']->artifacts[$source]);
        }
        $caseEnd = strpos($source, '|');
        if (!str_starts_with($source, 'case:') || $caseEnd === false) {
            throw new GateError('A raw authority source requires its exact case and view.');
        }
        $case = substr($source, 5, $caseEnd - 5);
        $view = substr($source, $caseEnd + 1);
        $report = match ($view) {
            'format:metrics' => 'metrics',
            'format:suppressed' => 'suppressed',
            'directives' => 'directives',
            default => 'json',
        };
        $bags = [];
        foreach (['physical', 'ranking'] as $kind) {
            if ($kind === 'ranking' && $report !== 'json') {
                continue;
            }
            /** @var array<string,list<array{raw:string,decoded:array<string,mixed>}>> $rows */
            $rows = [];
            foreach (['candidate', 'reference'] as $side) {
                $capture = $captures[$side];
                if ($report === 'json') {
                    $slot = $capture->rankings[$source] ?? throw new GateError('An exact source has no complete ranking: ' . $source);
                    $text = $kind === 'ranking' ? $slot['ranked']['stdout'] : ($slot['physical']['stdout'] ?? $slot['ranked']['stdout']);
                    $member = $kind === 'ranking' ? 'topIssues' : 'violations';
                } else {
                    $text = $capture->artifacts[$source] ?? throw new GateError('An exact source has no complete publication: ' . $source);
                    $member = match ($report) {
                        'metrics' => 'symbols',
                        'suppressed' => 'suppressed',
                        default => 'directives',
                    };
                }
                foreach (ReportRecords::rawRecords($text, $member) as $raw) {
                    $mapped = $side === 'reference' ? $run->maps->forward($raw, $view) : $raw;
                    $canonical = self::canonical($mapped);
                    if ($kind === 'ranking') {
                        $members = self::objectMembers($canonical);
                        unset($members['rank']);
                        $canonical = self::members($members);
                    }
                    $rows[$side][] = ['raw' => $canonical, 'decoded' => ReportRecords::object($canonical)];
                }
            }
            $operations = $records->exactOperations($case, $view);
            $schema = self::schemaUnits($rows, $kind, $report, $view, $case, $run, $records);
            $allowed = self::admissible($rows, $operations, $kind, $report, $schema);
            foreach (['candidate', 'reference'] as $side) {
                $rows[$side] = self::erase($rows[$side] ?? [], $operations, $side, $kind, $report, $schema, $allowed);
                $bags[$kind][$side] = array_column($rows[$side], 'raw');
                sort($bags[$kind][$side], \SORT_STRING);
            }
            if ($bags[$kind]['candidate'] !== $bags[$kind]['reference']) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,string> $members */
    private static function members(array $members): string
    {
        ksort($members, \SORT_STRING);
        $parts = [];
        foreach ($members as $name => $value) {
            $parts[] = json_encode($name, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . ':' . $value;
        }
        return '{' . implode(',', $parts) . '}';
    }

    /** @param array<string,mixed> $endpoint
     * @return array<string,mixed>
     */
    private static function endpoint(array $endpoint, string $kind): array
    {
        if ($kind !== 'ranking') {
            return RankingSchema::physical($endpoint);
        }
        $ranked = array_intersect_key($endpoint, array_flip(RankingSchema::PROJECTION));
        if (\array_key_exists('techDebtMinutes', $endpoint)) {
            $ranked['debtMinutes'] = $endpoint['techDebtMinutes'];
        }
        foreach (RankingSchema::VALUES as $field) {
            if (\array_key_exists('ranking.' . $field, $endpoint)) {
                $ranked[$field] = $endpoint['ranking.' . $field];
            }
        }
        return $ranked;
    }

    /** @param list<string> $path
     * @return list<string>
     */
    private static function pathFor(array $path, string $kind): array
    {
        $field = $path[0] ?? '';
        if ($kind !== 'ranking') {
            return str_starts_with($field, 'ranking.') ? [] : $path;
        }
        if (str_starts_with($field, 'ranking.')) {
            return [substr($field, 8)];
        }
        if ($field === 'techDebtMinutes') {
            return ['debtMinutes'];
        }
        return \in_array($field, RankingSchema::PROJECTION, true) ? $path : [];
    }

    /** @param array<string,list<array{raw:string,decoded:array<string,mixed>}>> $rows
     * @return array<string,list<string>>
     */
    private static function schemaUnits(array $rows, string $kind, string $report, string $view, string $case, RunContext $run, RecordCheck $records): array
    {
        $units = ['candidate' => [], 'reference' => []];
        $schemaReport = $kind === 'ranking' ? 'json' : $report;
        $schemaView = $kind === 'ranking' ? 'ranking' : $view;
        $changes = $report === 'suppressed' ? [] : $run->declarations->fields->changes($schemaReport, $schemaView);
        if ($changes === []) {
            return $units;
        }
        $supplied = [];
        foreach ($run->declarations->fields->requiredPublications($case) as $publication) {
            if ($publication['report'] === $schemaReport && $publication['view'] === $schemaView && $publication['supplied']) {
                $supplied[$publication['side']] = true;
            }
        }
        if (!isset($supplied['candidate'], $supplied['reference'])) {
            return $units;
        }
        $rankedFields = $kind === 'ranking' ? RankingSchema::derive($run->options->candidateRoot)->fields : [];
        $physicalFields = [];
        if ($kind === 'physical' && $report === 'json') {
            try {
                $physicalFields = EquivalenceTuple::derive($run->options->candidateRoot)->fields;
            } catch (GateError) {
                return $units;
            }
        }
        foreach (['candidate', 'reference'] as $side) {
            try {
                if ($report === 'json') {
                    $records->rawAuthority($case, $view, $side);
                } else {
                    $records->comparative($case, $view, $side);
                }
            } catch (GateError) {
                return $units;
            }
        }
        foreach ($changes as $field => $change) {
            $proved = true;
            foreach (['candidate', 'reference'] as $side) {
                $publisher = match (true) {
                    $kind === 'ranking' => $side === 'candidate' ? $rankedFields : $run->declarations->fields->referenceFields('json', 'ranking', $rankedFields),
                    $report === 'json' => $side === 'candidate' ? $physicalFields : $run->declarations->fields->referenceFields('json', $view, $physicalFields),
                    default => $records->fields($schemaReport, $schemaView, $side),
                };
                $present = ($change === DeclaredFields::ADDED) === ($side === 'candidate');
                if (\in_array($field, $publisher, true) !== $present
                    || ($present && ($rows[$side] ?? []) === [])) {
                    $proved = false;
                    break;
                }
                foreach ($rows[$side] ?? [] as $row) {
                    if ($field !== 'rank' && \array_key_exists($field, $row['decoded']) !== $present) {
                        $proved = false;
                        break 2;
                    }
                }
            }
            if ($proved && $field !== 'rank') {
                $units['candidate'][] = $field;
                $units['reference'][] = $field;
            }
        }
        return $units;
    }

    /** @param array<string,mixed> $endpoint
     * @param list<string> $schema
     */
    private static function compatibleKey(array $endpoint, array $schema): string
    {
        return self::decodedKey(array_diff_key($endpoint, array_flip($schema)));
    }

    /** @param array<string,list<array{raw:string,decoded:array<string,mixed>}>> $rows
     * @param list<array{candidate:?array<string,mixed>,reference:?array<string,mixed>,paths:list<list<string>>,whole:bool}> $operations
     * @param array<string,list<string>> $schema
     *
     * @return array<string,true>
     */
    private static function admissible(array $rows, array $operations, string $kind, string $report, array $schema): array
    {
        $allowed = [];
        foreach (['candidate', 'reference'] as $side) {
            $indicesByDecoded = [];
            foreach ($rows[$side] ?? [] as $index => $row) {
                $indicesByDecoded[self::compatibleKey($row['decoded'], $schema[$side])][] = $index;
            }
            $cohorts = [];
            foreach ($operations as $operation) {
                $endpoint = $operation[$side];
                if ($endpoint === null) {
                    continue;
                }
                $paths = self::operationPaths($operation, $kind);
                if ($paths === [] && !$operation['whole']) {
                    continue;
                }
                $expected = $report === 'json' ? self::endpoint($endpoint, $kind) : $endpoint;
                $cohort = self::compatibleKey($expected, $schema[$side]);
                $signature = self::operationKey($operation, $kind, $report);
                $cohorts[$cohort]['signatures'][$signature] = ($cohorts[$cohort]['signatures'][$signature] ?? 0) + 1;
            }
            foreach ($cohorts as $cohort => $group) {
                $indices = $indicesByDecoded[$cohort] ?? [];
                $raws = array_unique(array_map(static fn(int $index): string => $rows[$side][$index]['raw'], $indices));
                $counts = $group['signatures'];
                $count = array_sum($counts);
                if ($indices === [] || $count > \count($indices)
                    || (\count($raws) > 1 && (\count($counts) !== 1 || $count !== \count($indices)))) {
                    continue;
                }
                foreach (array_keys($counts) as $signature) {
                    $allowed[$side][$signature] = true;
                }
            }
        }
        $shared = [];
        foreach ($operations as $operation) {
            $signature = self::operationKey($operation, $kind, $report);
            $candidateNeeded = $operation['candidate'] !== null && ($operation['whole'] || self::operationPaths($operation, $kind) !== []);
            $referenceNeeded = $operation['reference'] !== null && ($operation['whole'] || self::operationPaths($operation, $kind) !== []);
            if ((!$candidateNeeded || isset($allowed['candidate'][$signature]))
                && (!$referenceNeeded || isset($allowed['reference'][$signature]))) {
                $shared[$signature] = true;
            }
        }
        return $shared;
    }

    /** @param array{candidate:?array<string,mixed>,reference:?array<string,mixed>,paths:list<list<string>>,whole:bool} $operation
     * @return list<list<string>>
     */
    private static function operationPaths(array $operation, string $kind): array
    {
        $paths = [];
        foreach ($operation['paths'] as $path) {
            $candidate = $operation['candidate'];
            $reference = $operation['reference'];
            if ($candidate === null || $reference === null
                || (self::present($candidate, $path) === self::present($reference, $path)
                    && self::at($candidate, $path) === self::at($reference, $path))) {
                continue;
            }
            $mapped = self::pathFor($path, $kind);
            if ($mapped !== []) {
                $paths[] = $mapped;
            }
        }
        return $paths;
    }

    /** @param array{candidate:?array<string,mixed>,reference:?array<string,mixed>,paths:list<list<string>>,whole:bool} $operation */
    private static function operationKey(array $operation, string $kind, string $report): string
    {
        if ($kind !== 'ranking' || $report !== 'json') {
            return DeclaredRecords::canonical($operation);
        }
        $transitions = [];
        foreach ($operation['paths'] as $path) {
            $transition = ['path' => $path];
            foreach (['candidate', 'reference'] as $side) {
                $endpoint = $operation[$side];
                $present = $endpoint !== null && self::present($endpoint, $path);
                $transition[$side] = ['present' => $present, 'value' => $present ? self::at($endpoint, $path) : null];
            }
            $transitions[] = $transition;
        }
        return self::decodedKey([
            'candidate' => $operation['candidate'] === null ? null : self::endpoint($operation['candidate'], $kind),
            'reference' => $operation['reference'] === null ? null : self::endpoint($operation['reference'], $kind),
            'transitions' => $transitions,
            'whole' => $operation['whole'],
        ]);
    }

    /** @param list<array{raw:string,decoded:array<string,mixed>}> $rows
     * @param list<array{candidate:?array<string,mixed>,reference:?array<string,mixed>,paths:list<list<string>>,whole:bool}> $operations
     * @param array<string,list<string>> $schema
     * @param array<string,true> $allowed
     *
     * @return list<array{raw:string,decoded:array<string,mixed>}>
     */
    private static function erase(array $rows, array $operations, string $side, string $kind, string $report, array $schema, array $allowed): array
    {
        $indicesByDecoded = [];
        foreach ($rows as $index => $row) {
            $indicesByDecoded[self::compatibleKey($row['decoded'], $schema[$side])][] = $index;
        }
        $groups = [];
        foreach ($operations as $operation) {
            $endpoint = $side === 'candidate' ? $operation['candidate'] : $operation['reference'];
            if ($endpoint === null) {
                continue;
            }
            $expected = $report === 'json' ? self::endpoint($endpoint, $kind) : $endpoint;
            $paths = self::operationPaths($operation, $kind);
            if ($paths === [] && !$operation['whole']) {
                continue;
            }
            $cohort = self::compatibleKey($expected, $schema[$side]);
            $signature = self::operationKey($operation, $kind, $report);
            if (!isset($allowed[$signature])) {
                continue;
            }
            $groups[$cohort][$signature]['expected'] = $expected;
            $groups[$cohort][$signature]['paths'] = $paths;
            $groups[$cohort][$signature]['whole'] = $operation['whole'];
            $groups[$cohort][$signature]['count'] = ($groups[$cohort][$signature]['count'] ?? 0) + 1;
        }
        foreach ($groups as $cohort => $signatures) {
            $indices = $indicesByDecoded[$cohort] ?? [];
            if ($indices === []) {
                continue;
            }
            $raws = array_unique(array_map(static fn(int $index): string => $rows[$index]['raw'], $indices));
            $count = array_sum(array_column($signatures, 'count'));
            if ($count > \count($indices) || (\count($raws) > 1 && (\count($signatures) !== 1 || $count !== \count($indices)))) {
                continue;
            }
            $next = 0;
            foreach ($signatures as $bundle) {
                for ($number = 0; $number < $bundle['count']; ++$number) {
                    $index = $indices[$next++] ?? null;
                    if ($index === null || !isset($rows[$index])) {
                        break;
                    }
                    if ($bundle['whole']) {
                        unset($rows[$index]);
                        continue;
                    }
                    $edits = [];
                    foreach ($bundle['paths'] as $path) {
                        if (self::present($rows[$index]['decoded'], $path)) {
                            $edits[ValueCheck::value($path)] = null;
                        }
                    }
                    if ($edits !== []) {
                        $row = $rows[$index];
                        $row['raw'] = self::canonical(ReportRecords::edit($row['raw'], $edits));
                        $rows[$index] = $row;
                    }
                }
            }
        }
        if ($schema[$side] !== []) {
            foreach ($rows as &$row) {
                $edits = [];
                foreach ($schema[$side] as $field) {
                    if (\array_key_exists($field, $row['decoded'])) {
                        $edits[ValueCheck::value([$field])] = null;
                    }
                }
                if ($edits !== []) {
                    $row['raw'] = self::canonical(ReportRecords::edit($row['raw'], $edits));
                }
            }
            unset($row);
        }
        return array_values($rows);
    }

    /** @param array<string,mixed> $record
     * @param list<string> $path
     */
    private static function present(array $record, array $path): bool
    {
        $current = $record;
        foreach ($path as $index => $field) {
            if (!\is_array($current) || !\array_key_exists($field, $current)) {
                return false;
            }
            $current = $current[$field];
        }
        return true;
    }

    /** @param array<string,mixed> $record
     * @param list<string> $path
     */
    private static function at(array $record, array $path): mixed
    {
        $current = $record;
        foreach ($path as $field) {
            if (!\is_array($current) || !\array_key_exists($field, $current)) {
                return null;
            }
            $current = $current[$field];
        }
        return $current;
    }

    private static function decodedKey(mixed $value): string
    {
        if (\is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, \SORT_STRING);
            }
            $value = array_map(static fn(mixed $member): mixed => \is_array($member) ? self::ordered($member) : $member, $value);
        }
        return ValueCheck::value($value);
    }

    /** @param array<array-key,mixed> $value
     * @return array<array-key,mixed>
     */
    private static function ordered(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }
        return array_map(static fn(mixed $member): mixed => \is_array($member) ? self::ordered($member) : $member, $value);
    }

    private static function source(string $surface, string $case): ?string
    {
        if (\in_array($surface, ['baseline-file', 'baseline:cleanup:file', 'baseline:rename-channels:file', 'baseline:update:file'], true)) {
            return null;
        }
        if (\in_array($surface, ['format:metrics', 'format:suppressed', 'directives'], true)) {
            return 'case:' . $case . '|' . $surface;
        }
        if (\in_array($surface, ['check:baseline', 'check:baseline-source'], true)) {
            return 'case:' . $case . '|' . $surface;
        }
        return 'case:' . $case . '|format:json';
    }

    private static function frame(string $name, string $bytes): string
    {
        return $name . ' ' . \strlen($bytes) . "\n" . $bytes . "\n";
    }

    private static function invocationFrame(string $key, string $side, CaptureResult $capture, RunContext $run): string
    {
        $frame = '';
        foreach ($run->publicationForms->invocationArtifacts($key) as $artifact) {
            $surface = Surfaces::surfaceClass($artifact);
            $bytes = $capture->artifacts[$artifact] ?? null;
            if ($bytes === null) {
                $frame .= self::frame('missing-invocation-artifact', $artifact);
                continue;
            }
            $label = str_starts_with($surface, 'exit:') ? 'invocation-exit' : (str_starts_with($surface, 'stderr:') ? 'invocation-stderr' : 'invocation-' . $surface);
            $frame .= self::frame($label, $run->normalization->normalize($surface, $bytes));
        }
        return $frame;
    }

    private static function canonical(string $raw): string
    {
        json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $at = 0;
        $value = self::value($raw, $at);
        self::skip($raw, $at);
        if ($at !== \strlen($raw)) {
            throw new GateError('An exact record has trailing JSON tokens.');
        }
        return $value;
    }

    /** @return array<string,string> */
    private static function objectMembers(string $raw): array
    {
        json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $at = 0;
        self::skip($raw, $at);
        if (($raw[$at] ?? '') !== '{') {
            throw new GateError('An exact record requires a JSON object.');
        }
        ++$at;
        $members = [];
        self::skip($raw, $at);
        while (($raw[$at] ?? '') !== '}') {
            $quoted = self::string($raw, $at);
            $key = json_decode($quoted, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_string($key) || isset($members[$key])) {
                throw new GateError('An exact record repeats a JSON member.');
            }
            self::skip($raw, $at);
            if (($raw[$at++] ?? '') !== ':') {
                throw new GateError('An exact record has no JSON member separator.');
            }
            $members[$key] = self::value($raw, $at);
            self::skip($raw, $at);
            if (($raw[$at] ?? '') === '}') {
                break;
            }
            if (($raw[$at++] ?? '') !== ',') {
                throw new GateError('An exact record has no JSON member delimiter.');
            }
            self::skip($raw, $at);
        }
        ++$at;
        return $members;
    }

    private static function value(string $raw, int &$at): string
    {
        self::skip($raw, $at);
        $start = $at;
        if (($raw[$at] ?? '') === '{') {
            $members = self::objectMembersAt($raw, $at);
            ksort($members, \SORT_STRING);
            $parts = [];
            foreach ($members as $key => $value) {
                $parts[] = json_encode($key, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . ':' . $value;
            }
            return '{' . implode(',', $parts) . '}';
        }
        if (($raw[$at] ?? '') === '[') {
            ++$at;
            $items = [];
            self::skip($raw, $at);
            while (($raw[$at] ?? '') !== ']') {
                $items[] = self::value($raw, $at);
                self::skip($raw, $at);
                if (($raw[$at] ?? '') === ']') {
                    break;
                }
                if (($raw[$at++] ?? '') !== ',') {
                    throw new GateError('An exact record has no JSON element delimiter.');
                }
            }
            ++$at;
            return '[' . implode(',', $items) . ']';
        }
        if (($raw[$at] ?? '') === '"') {
            return self::string($raw, $at);
        }
        $at += strcspn($raw, ",]} \t\r\n", $at);
        if ($at === $start) {
            throw new GateError('An exact record has an empty scalar token.');
        }
        return substr($raw, $start, $at - $start);
    }

    /** @return array<string,string> */
    private static function objectMembersAt(string $raw, int &$at): array
    {
        ++$at;
        $members = [];
        self::skip($raw, $at);
        while (($raw[$at] ?? '') !== '}') {
            $quoted = self::string($raw, $at);
            $key = json_decode($quoted, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_string($key) || \array_key_exists($key, $members)) {
                throw new GateError('An exact record repeats a JSON member.');
            }
            self::skip($raw, $at);
            if (($raw[$at++] ?? '') !== ':') {
                throw new GateError('An exact record has no JSON member separator.');
            }
            $members[$key] = self::value($raw, $at);
            self::skip($raw, $at);
            if (($raw[$at] ?? '') === '}') {
                break;
            }
            if (($raw[$at++] ?? '') !== ',') {
                throw new GateError('An exact record has no JSON member delimiter.');
            }
            self::skip($raw, $at);
        }
        ++$at;
        return $members;
    }

    private static function string(string $raw, int &$at): string
    {
        $start = $at++;
        while (isset($raw[$at])) {
            if ($raw[$at] === '\\') {
                $at += 2;
            } elseif ($raw[$at++] === '"') {
                return substr($raw, $start, $at - $start);
            }
        }
        throw new GateError('An exact record has an unterminated JSON string.');
    }

    private static function skip(string $raw, int &$at): void
    {
        $at += strspn($raw, " \t\r\n", $at);
    }
}
