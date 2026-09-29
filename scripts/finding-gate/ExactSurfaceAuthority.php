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
        return [
            self::one($pair, 'candidate', $captures['candidate'], $run),
            self::one($pair, 'reference', $captures['reference'], $run),
        ];
    }

    /** @return list<array{side:string,key:string,role:string}> */
    public static function requiredEvidence(string $key, RunContext $run): array
    {
        $surface = Surfaces::surfaceClass($key);
        $case = str_starts_with($key, 'case:') ? substr($key, 5, (int) strpos($key, '|') - 5) : null;
        $sources = [$key => ['capture', 'surface', 'normalization', 'path']];
        if ($case !== null && ReportViews::recordBearingSurface($surface)) {
            $source = self::source($surface, $case, $run);
            if ($source !== null) {
                $sources[$source] = array_values(array_unique([...($sources[$source] ?? []), 'capture', 'surface', 'normalization', 'path', 'records']));
                if (!\in_array($surface, ['format:metrics', 'format:suppressed', 'directives'], true)) {
                    $sources[$source][] = 'ranking';
                }
            }
            $sources['case:' . $case . '|format:json'][] = 'outcome';
            if ($surface !== 'baseline-file' && !\in_array($surface, ['baseline:cleanup:file', 'baseline:rename-channels:file', 'baseline:update:file'], true)) {
                $sources[$key] = array_values(array_unique([...($sources[$key] ?? []), 'records']));
            }
            if (\in_array($surface, ['format:sarif', 'format:gitlab'], true)) {
                $sources[$key][] = 'fingerprint';
            }
            $schemaReport = match ($surface) {
                'format:metrics' => 'metrics',
                'format:suppressed' => 'suppressed',
                'directives' => 'directives',
                default => 'json',
            };
            $schemaView = \in_array($surface, ['check:output:file', 'check:parallel', 'check:baseline', 'check:baseline-source'], true) ? $surface : ReportViews::main($schemaReport);
            if ($run->declarations->fields->changes($schemaReport, $schemaView) !== []) {
                $sources['case:' . $case . '|' . $schemaView][] = 'schema';
            }
            if ($schemaReport === 'json' && $run->declarations->fields->changes('json', 'ranking') !== []) {
                $sources['case:' . $case . '|ranking'][] = 'schema';
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
            }
        }
        $required = [];
        foreach ($sources as $source => $roles) {
            foreach (['candidate', 'reference'] as $side) {
                foreach (array_unique($roles) as $role) {
                    $required[] = ['side' => $side, 'key' => $source, 'role' => $role];
                }
                if ($side === 'candidate') {
                    $required[] = ['side' => $side, 'key' => $source, 'role' => 'repeatable'];
                }
            }
        }
        if ($case !== null && ReportViews::recordBearingSurface($surface) && !\in_array($surface, ['format:metrics', 'format:suppressed', 'directives'], true)) {
            $required[] = ['side' => '*', 'key' => 'finding', 'role' => 'tuple-schema'];
            foreach (['candidate', 'reference'] as $side) {
                $required[] = ['side' => $side, 'key' => 'case:' . $case . '|format:json', 'role' => 'tuple'];
            }
        }
        return $required;
    }

    private static function one(SurfacePair $pair, string $side, CaptureResult $capture, RunContext $run): string
    {
        $visible = $side === 'candidate' ? $pair->candidate : $pair->reference;
        if ($visible === null) {
            throw new GateError('An exact surface has no complete visible publication: ' . $pair->key);
        }
        $framed = self::frame('visible', $visible);
        if (!ReportViews::recordBearingSurface($pair->surface)) {
            return $framed;
        }
        $case = substr($pair->key, 5, (int) strpos($pair->key, '|') - 5);
        $source = self::source($pair->surface, $case, $run);
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

    private static function source(string $surface, string $case, RunContext $run): ?string
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
