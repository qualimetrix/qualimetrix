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
        $physical = $slot['physical']['stdout'] ?? $slot['ranked']['stdout'];
        $ranked = $slot['ranked']['stdout'];
        $rawPhysical = ReportRecords::rawRecords($physical, 'violations');
        $rawIssues = ReportRecords::rawRecords($ranked, 'topIssues');
        $issues = [];
        foreach ($rawIssues as $raw) {
            $mapped = $side === 'reference' ? $run->maps->forward($raw, Surfaces::surfaceClass($source)) : $raw;
            $decoded = ReportRecords::object($mapped);
            $join = RankingSchema::joinKey($decoded, true);
            $members = self::objectMembers($mapped);
            $values = [];
            foreach (RankingSchema::VALUES as $field) {
                if (!isset($members[$field])) {
                    throw new GateError('An exact ranking has no joined value: ' . $field);
                }
                $values[$field] = $members[$field];
            }
            $issues[$join][] = $values;
        }
        $rows = [];
        foreach ($rawPhysical as $raw) {
            $mapped = $side === 'reference' ? $run->maps->forward($raw, Surfaces::surfaceClass($source)) : $raw;
            $decoded = ReportRecords::object($mapped);
            $join = RankingSchema::joinKey($decoded, false);
            if (($issues[$join] ?? []) === []) {
                throw new GateError('An exact physical finding has no complete ranked occurrence.');
            }
            $values = array_shift($issues[$join]);
            $rows[] = json_encode([self::canonical($mapped), $values], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }
        foreach ($issues as $remaining) {
            if ($remaining !== []) {
                throw new GateError('An exact ranking has no complete physical occurrence.');
            }
        }
        sort($rows, \SORT_STRING);
        return $framed . self::frame('records', json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
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
