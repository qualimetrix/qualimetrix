<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Scoped enumeration positions in a published JSON report, preserving all other bytes.
 *
 * @phpstan-import-type MapPair from RenameMaps
 */
final class ReportEnumerationMap
{
    /**
     * @return array{surface: string, path: list<string>, kind: string, members: list<string>} */
    public static function descriptor(string $text, string $row): array
    {
        $value = json_decode($text, true);
        if (!\is_array($value) || array_keys($value) !== ['surface', 'path', 'kind', 'members']
            || !\is_string($value['surface']) || !\in_array($value['kind'], ['keys', 'values'], true)
            || !\is_array($value['members']) || !array_is_list($value['members']) || $value['members'] === []
            || json_encode($value, \JSON_UNESCAPED_SLASHES) !== $text) {
            throw new GateError($row . ': an enumeration descriptor must be canonical and name surface, path, kind and members.');
        }
        $path = YamlInputMap::canonicalPath((string) json_encode($value['path'], \JSON_UNESCAPED_SLASHES), $row);
        $members = [];
        foreach ($value['members'] as $member) {
            if (!\is_string($member) || $member === '') {
                throw new GateError($row . ': enumeration members must be non-empty strings.');
            }
            $members[] = $member;
        }
        if (\count(array_unique($value['members'])) !== \count($value['members'])) {
            throw new GateError($row . ': enumeration members must be unique.');
        }
        return ['surface' => $value['surface'], 'path' => $path, 'kind' => $value['kind'], 'members' => $members];
    }

    /** @param list<MapPair> $pairs
     * @return array{string, array<int, int>} */
    public static function translate(string $text, string $surface, array $pairs): array
    {
        $hits = [];
        foreach ($pairs as $index => $pair) {
            if (!\in_array(RenameMaps::REPORT_VALUES, $pair['sources'], true) || !str_starts_with($pair['old'], '{')) {
                continue;
            }
            $old = self::descriptor($pair['old'], $pair['row']);
            $new = self::descriptor($pair['new'], $pair['row']);
            if ([$old['surface'], $old['path'], $old['kind']] !== [$new['surface'], $new['path'], $new['kind']]
                || \count($old['members']) !== \count($new['members'])) {
                throw new GateError('Enumeration correspondence must retain its surface, path, kind and member count.');
            }
            if ($old['surface'] !== $surface) {
                continue;
            }
            $value = self::atPath(json_decode($text, true), $old['path']);
            if (!\is_array($value) || ($old['kind'] === 'keys' ? array_keys($value) : $value) !== $old['members']) {
                continue;
            }
            $original = $text;
            $edits = [];
            foreach ($old['members'] as $position => $member) {
                if ($old['kind'] === 'values') {
                    [$start, $end] = self::jsonSpan($original, [...$old['path'], (string) $position]);
                    $edits[] = [$start, $end, (string) json_encode($new['members'][$position], \JSON_THROW_ON_ERROR)];
                    continue;
                }
                $target = $new['members'][$position];
                if (!\array_key_exists($target, $value)) {
                    throw new GateError('An object enumeration may permute keys but cannot infer renamed member values.');
                }
                [$start, $end] = self::jsonSpan($original, [...$old['path'], $member]);
                [$targetStart, $targetEnd] = self::jsonSpan($original, [...$old['path'], $target]);
                [$keyStart, $keyEnd] = self::jsonKeySpan($original, $start);
                $edits[] = [$keyStart, $keyEnd, (string) json_encode($target, \JSON_THROW_ON_ERROR)];
                $edits[] = [$start, $end, substr($original, $targetStart, $targetEnd - $targetStart)];
            }
            $text = self::editSpans($original, $edits);
            $hits[$index] = ($hits[$index] ?? 0) + 1;
        }
        if ($surface === 'format:suppressed') {
            $text = self::forwardReportValues($text, $pairs, $hits);
        }
        return [$text, $hits];
    }

    /** @param list<string> $path
     * @return array{int, int} */
    private static function jsonSpan(string $text, array $path): array
    {
        $marker = '"<finding-gate-value-span>"';
        if (str_contains($text, $marker)) {
            throw new GateError('The JSON span marker already occurs in the publication.');
        }
        [$replaced, $hits] = JsonText::redact($text, $path, $marker);
        $start = strpos($replaced, $marker);
        if ($hits !== 1 || $start === false) {
            throw new GateError('An enumeration path must address exactly one JSON value.');
        }
        return [$start, $start + \strlen($text) - \strlen($replaced) + \strlen($marker)];
    }

    /**
     * @return array{int, int} */
    private static function jsonKeySpan(string $text, int $valueStart): array
    {
        if (preg_match('~("(?:[^"\\\\]|\\\\.)*")(\s*:\s*)$~s', substr($text, 0, $valueStart), $match, \PREG_OFFSET_CAPTURE) !== 1) {
            throw new GateError('A mapped enumeration value has no exact JSON member key.');
        }
        return [$match[1][1], $match[1][1] + \strlen($match[1][0])];
    }

    /** @param list<array{int, int, string}> $edits */
    private static function editSpans(string $text, array $edits): string
    {
        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        $boundary = \strlen($text);
        foreach ($edits as [$start, $end, $replacement]) {
            if ($end > $boundary) {
                throw new GateError('Two declarations overlap a publication span.');
            }
            $text = substr($text, 0, $start) . $replacement . substr($text, $end);
            $boundary = $start;
        }
        return $text;
    }

    /** @param list<MapPair> $pairs
     * @param array<int, int> $hits */
    private static function forwardReportValues(string $text, array $pairs, array &$hits): string
    {
        $decoded = json_decode($text, true);
        if (!\is_array($decoded)) {
            return $text;
        }
        $edits = [];
        foreach ($pairs as $index => $pair) {
            if ($pair['sources'] !== [RenameMaps::REPORT_VALUES] || str_starts_with($pair['old'], '{')) {
                continue;
            }
            $paths = [['mechanism']];
            foreach (['mechanisms', 'suppressed', 'neverMatched'] as $field) {
                foreach ((array) ($decoded[$field] ?? []) as $position => $entry) {
                    $paths[] = $field === 'mechanisms' ? [$field, (string) $position] : [$field, (string) $position, 'mechanism'];
                }
            }
            foreach ($paths as $path) {
                if (self::atPath($decoded, $path) !== $pair['old']) {
                    continue;
                }
                [$start, $end] = self::jsonSpan($text, $path);
                $edits[] = [$start, $end, (string) json_encode($pair['new'], \JSON_THROW_ON_ERROR)];
                $hits[$index] = ($hits[$index] ?? 0) + 1;
            }
            if (\is_array($decoded['byMechanism'] ?? null) && \array_key_exists($pair['old'], $decoded['byMechanism'])) {
                if (\array_key_exists($pair['new'], $decoded['byMechanism'])) {
                    throw new GateError('A renamed mechanism collides with an existing byMechanism key.');
                }
                [$start] = self::jsonSpan($text, ['byMechanism', $pair['old']]);
                [$keyStart, $keyEnd] = self::jsonKeySpan($text, $start);
                $edits[] = [$keyStart, $keyEnd, (string) json_encode($pair['new'], \JSON_THROW_ON_ERROR)];
                $hits[$index] = ($hits[$index] ?? 0) + 1;
            }
        }
        return self::editSpans($text, $edits);
    }
    /** @param list<string> $path */
    private static function atPath(mixed $value, array $path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
