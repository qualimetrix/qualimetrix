<?php

declare(strict_types=1);

namespace QmxFindingGate;

use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Exact key positions in the supported block YAML input grammar.
 *
 * @phpstan-import-type MapPair from RenameMaps
 */
final class YamlInputMap
{
    /** @param list<MapPair> $pairs
     * @return array{string, array<int, int>} */
    public static function reverse(string $text, array $pairs): array
    {
        $hits = [];
        if (array_filter($pairs, static fn(array $pair): bool => array_intersect($pair['sources'], [RenameMaps::INPUTS, RenameMaps::METRIC_KEYS, RenameMaps::CHANNELS, RenameMaps::SYMBOLS]) !== []) === []) {
            return [$text, $hits];
        }
        $changes = [];
        foreach ($pairs as $index => $pair) {
            if (!\in_array(RenameMaps::INPUTS, $pair['sources'], true) || str_starts_with($pair['new'], '--')) {
                continue;
            }
            $old = self::path($pair['old'], $pair['row']);
            $new = self::path($pair['new'], $pair['row']);
            $changes[] = ['old' => $old, 'new' => $new, 'index' => $index];
        }
        if (!class_exists(Yaml::class)) {
            require_once \dirname(__DIR__, 2) . '/vendor/autoload.php';
        }
        try {
            $parsed = Yaml::parse($text);
        } catch (Throwable $error) {
            throw new GateError('A mapped YAML input could not be parsed: ' . $error->getMessage());
        }
        self::refuseUnsupportedExpressions($parsed, $pairs);
        if ($changes === []) {
            return self::reverseSymbols($text, $parsed, $pairs);
        }
        $stack = [];
        $edits = [];
        $matched = [];
        $targets = [];
        $offset = 0;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('~^( *)([A-Za-z_][A-Za-z0-9_.-]*):(?=\s|$)~', $line, $match) === 1) {
                $indent = \strlen($match[1]);
                while ($stack !== [] && $stack[array_key_last($stack)]['indent'] >= $indent) {
                    array_pop($stack);
                }
                $path = [...array_column($stack, 'key'), $match[2]];
                foreach ($changes as $change) {
                    if ($path !== $change['new']) {
                        continue;
                    }
                    if (!self::hasPath($parsed, $path)) {
                        continue;
                    }
                    if (\array_slice($change['old'], 0, -1) !== \array_slice($change['new'], 0, -1)) {
                        throw new GateError('A YAML key map renames a leaf in one parent; structural moves need a structural declaration.');
                    }
                    $oldKey = $change['old'][array_key_last($change['old'])];
                    $target = json_encode($change['old'], \JSON_THROW_ON_ERROR);
                    if (isset($targets[$target])) {
                        throw new GateError('Two mapped YAML keys would produce the same key path.');
                    }
                    $targets[$target] = true;
                    $parent = self::atPath($parsed, \array_slice($path, 0, -1));
                    if (\is_array($parent) && \array_key_exists($oldKey, $parent)) {
                        throw new GateError('A translated YAML key collides with an existing sibling key.');
                    }
                    $edits[] = [$offset + $indent, \strlen($match[2]), $oldKey, $change['index']];
                    $matched[$change['index']] = true;
                }
                $stack[] = ['indent' => $indent, 'key' => $match[2]];
            } elseif (preg_match('~^( *)-\s~', $line, $match) === 1) {
                $indent = \strlen($match[1]);
                while ($stack !== [] && $stack[array_key_last($stack)]['indent'] >= $indent) {
                    array_pop($stack);
                }
                $stack[] = ['indent' => $indent, 'key' => '<sequence>'];
            }
            $offset += \strlen($line) + 1;
        }
        foreach ($changes as $change) {
            if (!isset($matched[$change['index']]) && self::hasPath($parsed, $change['new'])) {
                throw new GateError('The mapped YAML path is not an addressable plain block key; flow, alias and quoted key forms are unsupported.');
            }
        }
        $expected = $parsed;
        foreach ($changes as $change) {
            if (isset($matched[$change['index']])) {
                self::renamePath($expected, $change['new'], $change['old'][array_key_last($change['old'])]);
            }
        }
        foreach (array_reverse($edits) as [$start, $length, $key, $index]) {
            $text = substr($text, 0, $start) . $key . substr($text, $start + $length);
            $hits[$index] = ($hits[$index] ?? 0) + 1;
        }
        if (Yaml::parse($text) !== $expected) {
            throw new GateError('A mapped YAML key changes an alias or another undeclared structure.');
        }
        [$text, $symbolHits] = self::reverseSymbols($text, Yaml::parse($text), $pairs);
        foreach ($symbolHits as $index => $count) {
            $hits[$index] = ($hits[$index] ?? 0) + $count;
        }
        return [$text, $hits];
    }

    /** @param list<MapPair> $pairs
     * @return array{string,array<int,int>} */
    private static function reverseSymbols(string $text, mixed $parsed, array $pairs): array
    {
        $lookup = [];
        foreach ($pairs as $index => $pair) {
            if (\in_array(RenameMaps::SYMBOLS, $pair['sources'], true)) {
                $lookup[$pair['new']] = [$pair['old'], $index];
            }
        }
        if ($lookup === []) {
            return [$text, []];
        }
        $expected = $parsed;
        $required = [];
        self::symbolValues($expected, [], $lookup, $required);
        $stack = [];
        $counts = [];
        $edits = [];
        $matched = [];
        $offset = 0;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('~^( *)(?:-\s+)?([A-Za-z_][A-Za-z0-9_.-]*):(?=\s|$)~', $line, $key) === 1) {
                $indent = \strlen($key[1]);
                while ($stack !== [] && $stack[array_key_last($stack)]['indent'] >= $indent) {
                    array_pop($stack);
                }
                $parent = $stack === [] ? [] : $stack[array_key_last($stack)]['path'];
                if (preg_match('~^ *-\s+~', $line) === 1) {
                    $address = json_encode($parent, \JSON_THROW_ON_ERROR);
                    $position = $counts[$address] ?? 0;
                    $counts[$address] = $position + 1;
                    $parent[] = (string) $position;
                    $stack[] = ['indent' => $indent, 'path' => $parent];
                    $indent += 2;
                }
                $stack[] = ['indent' => $indent, 'path' => [...$parent, $key[2]]];
            } elseif (preg_match('~^( *)-\s+(.+)$~', $line, $scalar, \PREG_OFFSET_CAPTURE) === 1) {
                $indent = \strlen($scalar[1][0]);
                while ($stack !== [] && $stack[array_key_last($stack)]['indent'] >= $indent) {
                    array_pop($stack);
                }
                $parent = $stack === [] ? [] : $stack[array_key_last($stack)]['path'];
                $address = json_encode($parent, \JSON_THROW_ON_ERROR);
                $position = $counts[$address] ?? 0;
                $counts[$address] = $position + 1;
                $path = [...$parent, (string) $position];
                $pathKey = json_encode($path, \JSON_THROW_ON_ERROR);
                if (isset($required[$pathKey])) {
                    [$old, $index] = $required[$pathKey];
                    $spelling = $scalar[2][0];
                    if (preg_match('~^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^\s#\[\]{}&*!]+)(.*)$~', $spelling, $value) !== 1
                        || Yaml::parse($value[1]) !== self::atPath($parsed, $path)) {
                        throw new GateError('A mapped YAML symbol is not an addressable block scalar.');
                    }
                    $replacement = str_starts_with($value[1], '"') ? json_encode($old, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)
                        : (str_starts_with($value[1], "'") ? "'" . str_replace("'", "''", $old) . "'" : $old);
                    $edits[] = [$offset + $scalar[2][1], \strlen($value[1]), $replacement, $index];
                    $matched[$pathKey] = true;
                }
            }
            $offset += \strlen($line) + 1;
        }
        if (array_diff_key($required, $matched) !== []) {
            throw new GateError('A mapped YAML symbol uses unsupported flow, alias or scalar grammar.');
        }
        $hits = [];
        foreach (array_reverse($edits) as [$start, $length, $replacement, $index]) {
            $text = substr_replace($text, $replacement, $start, $length);
            $hits[$index] = ($hits[$index] ?? 0) + 1;
        }
        if (Yaml::parse($text) !== $expected) {
            throw new GateError('A YAML symbol map changes an alias or another undeclared value.');
        }
        return [$text, $hits];
    }

    /** @param list<string> $path
     * @param array<string,array{string,int}> $lookup
     * @param array<string,array{string,int}> $required */
    private static function symbolValues(mixed &$value, array $path, array $lookup, array &$required): void
    {
        if (\is_array($value)) {
            if (array_is_list($value)) {
                foreach ($lookup as $name => [$old]) {
                    if (\in_array($name, $value, true) && \in_array($old, $value, true)) {
                        throw new GateError('A mapped YAML symbol collides with an existing selector member.');
                    }
                }
            }
            foreach ($value as $key => &$child) {
                if (isset($lookup[(string) $key])) {
                    throw new GateError('A mapped YAML symbol key is outside the named input profile.');
                }
                self::symbolValues($child, [...$path, (string) $key], $lookup, $required);
            }
            unset($child);
            return;
        }
        if (!\is_string($value)) {
            return;
        }
        foreach ($lookup as $name => [$old, $index]) {
            if (str_contains($value, '*') && (fnmatch($value, $name) || str_contains($value, $name))) {
                throw new GateError('A mapped YAML symbol selector requires unsupported reach rewriting.');
            }
            if ($value !== $name) {
                continue;
            }
            $known = \count($path) === 5 && \array_slice($path, 0, 2) === ['architecture', 'layers']
                && ctype_digit($path[2]) && \in_array($path[3], ['patterns', 'extends', 'implements'], true) && ctype_digit($path[4]);
            $excluded = \count($path) === 6 && \array_slice($path, 0, 2) === ['architecture', 'layers'] && ctype_digit($path[2])
                && $path[3] === 'exclude' && \in_array($path[4], ['patterns', 'extends', 'implements'], true) && ctype_digit($path[5]);
            if (!$known && !$excluded) {
                throw new GateError('A mapped YAML symbol value is outside the named architecture input paths.');
            }
            $required[json_encode($path, \JSON_THROW_ON_ERROR)] = [$old, $index];
            $value = $old;
            return;
        }
    }

    /** @param list<MapPair> $pairs */
    private static function refuseUnsupportedExpressions(mixed $parsed, array $pairs): void
    {
        $names = [];
        $metrics = [];
        foreach ($pairs as $pair) {
            if (\in_array(RenameMaps::CHANNELS, $pair['sources'], true)) {
                array_push($names, ...explode('#', $pair['old']), ...explode('#', $pair['new']));
            }
            if (\in_array(RenameMaps::METRIC_KEYS, $pair['sources'], true) || \in_array(RenameMaps::INPUTS, $pair['sources'], true)) {
                $metrics[] = $pair['new'];
            }
        }
        if (!\is_array($parsed)) {
            return;
        }
        foreach (['only_rules', 'exclude_rules'] as $field) {
            foreach ((array) ($parsed[$field] ?? []) as $selector) {
                if (!\is_string($selector)) {
                    continue;
                }
                foreach ($names as $name) {
                    if (fnmatch($selector, $name)) {
                        throw new GateError('A mapped rule selector requires a reach-aware declaration; selector rewriting is unsupported.');
                    }
                }
            }
        }
        foreach ($parsed as $field => $value) {
            if ($field === 'formula' && \is_string($value)) {
                foreach ($metrics as $metric) {
                    $token = str_starts_with($metric, 'strategy:') ? '.' . substr($metric, 9) : $metric;
                    if ($token !== '' && str_contains($value, $token)) {
                        throw new GateError('A mapped metric expression requires a grammar-aware declaration; formula rewriting is unsupported.');
                    }
                }
            }
            if (\is_array($value)) {
                self::refuseUnsupportedExpressions($value, $pairs);
            }
        }
    }

    /** @param list<string> $path */
    private static function renamePath(mixed &$value, array $path, string $key): void
    {
        $leaf = array_pop($path);
        foreach ($path as $parent) {
            $value = &$value[$parent];
        }
        $renamed = [];
        foreach ($value as $oldKey => $item) {
            $renamed[$oldKey === $leaf ? $key : $oldKey] = $item;
        }
        $value = $renamed;
    }

    /**
     * @return non-empty-list<string> */
    public static function canonicalPath(string $text, string $row): array
    {
        $path = json_decode($text, true);
        if (!\is_array($path) || !array_is_list($path) || $path === [] || json_encode($path, \JSON_UNESCAPED_SLASHES) !== $text) {
            throw new GateError($row . ': a YAML path must be a canonical JSON string array.');
        }
        foreach ($path as $key) {
            if (!\is_string($key) || preg_match('~^[A-Za-z_][A-Za-z0-9_.-]*$~D', $key) !== 1) {
                throw new GateError($row . ': a YAML path contains an unsupported key.');
            }
        }
        return $path;
    }

    /**
     * @return non-empty-list<string> */
    public static function path(string $token, string $row): array
    {
        if (str_starts_with($token, '[')) {
            return self::canonicalPath($token, $row);
        }
        if (str_ends_with($token, ':')) {
            return [substr($token, 0, -1)];
        }
        if (str_contains($token, ':')) {
            return ['rules', ...explode(':', $token)];
        }
        return ['rules', $token];
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

    /** @param list<string> $path */
    private static function hasPath(mixed $value, array $path): bool
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }
        return true;
    }
}
