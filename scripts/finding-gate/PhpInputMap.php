<?php

declare(strict_types=1);

namespace QmxFindingGate;

use ParseError;
use PhpToken;

/**
 * Named PHP input positions; aliases, declarations and strings receive no inferred rename.
 *
 * @phpstan-import-type MapPair from RenameMaps
 */
final class PhpInputMap
{
    private const array TAGS = ['@qmx-ignore', '@qmx-ignore-file', '@qmx-ignore-next-line', '@qmx-threshold'];

    /** @param list<MapPair> $pairs
     * @return array{string,array<int,int>} */
    public static function reverse(string $text, array $pairs): array
    {
        if ($pairs === []) {
            return [$text, []];
        }
        try {
            $tokens = PhpToken::tokenize($text, \TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new GateError('A mapped PHP input is outside the supported token grammar: ' . $error->getMessage());
        }
        $channels = [];
        $symbols = [];
        $other = [];
        foreach ($pairs as $index => $pair) {
            if (\in_array(RenameMaps::CHANNELS, $pair['sources'], true)) {
                $channels[$pair['new']][$pair['old']][] = $index;
            }
            if (\in_array(RenameMaps::SYMBOLS, $pair['sources'], true)) {
                $symbols[$pair['new']][$pair['old']][] = $index;
            }
            if (array_intersect($pair['sources'], [RenameMaps::INPUTS, RenameMaps::METRIC_KEYS, RenameMaps::REPORT_VALUES]) !== []) {
                $other[] = $pair['new'];
            }
        }
        $significant = array_values(array_filter($tokens, static fn(PhpToken $token): bool => !$token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])));
        $contexts = self::contexts($significant);
        $aliases = [];
        $imports = [];
        $inImport = false;
        foreach ($significant as $position => $token) {
            if ($token->is(\T_USE)) {
                $inImport = true;
            }
            $imports[$position] = $inImport;
            if ($token->text === ';') {
                $inImport = false;
            }
            if (isset($symbols[ltrim($token->text, '\\')]) && ($significant[$position - 1]->id ?? null) === \T_USE
                && ($significant[$position + 1]->id ?? null) === \T_AS && ($significant[$position + 2]->id ?? null) === \T_STRING) {
                $aliases[$contexts[$position][0]][$significant[$position + 2]->text] = true;
            }
        }
        $hits = [];
        $edits = [];
        foreach ($tokens as $token) {
            $position = array_search($token, $significant, true);
            [$namespace, $class] = $position === false ? ['', ''] : $contexts[$position];
            if ($token->is(\T_NAMESPACE)) {
                $position = array_search($token, $significant, true);
                $next = $position === false ? null : ($significant[$position + 1] ?? null);
                $namespace = $next !== null && $next->is([\T_STRING, \T_NAME_QUALIFIED]) ? $next->text : '';
                foreach (array_keys($symbols) as $symbol) {
                    if ($namespace === $symbol || str_starts_with($namespace, $symbol . '\\')) {
                        throw new GateError('A mapped PHP namespace declaration or namespace prefix is unsupported.');
                    }
                }
            }
            if ($token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                $masked = self::maskDocumentation($token->text);
                preg_match_all('~(@qmx-[a-z-]+)[\t ]+([A-Za-z0-9_.*#:\\-]+)~', $masked, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
                foreach ($matches as $match) {
                    $tag = $match[1][0];
                    $target = $match[2][0];
                    [$name] = explode(':', $target, 2);
                    $parts = explode(':', $target);
                    if (isset($channels[$name]) && (\count($parts) > 2 || (isset($parts[1]) && !\in_array($parts[1], SubjectLevel::levels(), true)))) {
                        throw new GateError('A mapped PHP directive has an unsupported level shape.');
                    }
                    if (str_contains($name, '#') && array_intersect(explode('#', $name), array_keys($channels)) !== []) {
                        throw new GateError('A mapped PHP directive pair requires unsupported reach rewriting.');
                    }
                    if (!isset($channels[$name])) {
                        foreach (array_keys($channels) as $candidate) {
                            if (fnmatch($name, $candidate)) {
                                throw new GateError('A mapped PHP directive wildcard or pair requires unsupported reach rewriting.');
                            }
                        }
                        continue;
                    }
                    if (!\in_array($tag, self::TAGS, true) || str_contains($name, '#') || str_contains($name, '*')) {
                        throw new GateError('A mapped PHP directive has an unsupported tag or target shape.');
                    }
                    [$old, $indices] = self::image($channels[$name]);
                    if (str_contains($old, '#')) {
                        throw new GateError('A PHP directive cannot infer a retired channel-pair target.');
                    }
                    $edits[] = [$token->pos + $match[2][1], \strlen($name), $old];
                    self::credit($hits, $indices);
                }
                continue;
            }
            if ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE, \T_VARIABLE])) {
                $leaves = [];
                foreach (array_keys($symbols) as $symbol) {
                    $parts = preg_split('~\\\\|::~', $symbol);
                    $leaves[] = $parts === false ? $symbol : (string) end($parts);
                }
                foreach ([...array_keys($symbols), ...$leaves, ...array_keys($channels), ...$other] as $name) {
                    $spellings = [$name, str_replace('\\', '\\\\', $name)];
                    foreach ($spellings as $spelling) {
                        if (self::containsName($token->text, $spelling)) {
                            throw new GateError('A mapped PHP string or dynamic name is outside the supported input profile.');
                        }
                    }
                }
                continue;
            }
            if ($token->is([\T_NAME_RELATIVE, \T_NAME_QUALIFIED]) && $position !== false && !$imports[$position]) {
                $relative = $token->is(\T_NAME_RELATIVE) ? substr($token->text, \strlen('namespace\\')) : $token->text;
                $resolved = ($namespace === '' ? '' : $namespace . '\\') . $relative;
                if (isset($symbols[$resolved])) {
                    throw new GateError('A mapped PHP relative namespace name is outside the supported input profile.');
                }
            }
            $name = ltrim($token->text, '\\');
            if (isset($symbols[$name])) {
                $position = array_search($token, $significant, true);
                $previous = $position === false ? null : ($significant[$position - 1]->id ?? null);
                $next = $position === false ? null : ($significant[$position + 1]->id ?? null);
                if ($previous === \T_NAMESPACE || ($position !== false && $imports[$position] && ($previous !== \T_USE || $next !== \T_AS))
                    || (!$token->is(\T_NAME_FULLY_QUALIFIED) && !($previous === \T_USE && $next === \T_AS))) {
                    throw new GateError('A mapped PHP relative name, namespace or implicit import alias is unsupported.');
                }
                [$old, $indices] = self::image($symbols[$name]);
                $edits[] = [$token->pos, \strlen($token->text), ($token->is(\T_NAME_FULLY_QUALIFIED) ? '\\' : '') . $old];
                self::credit($hits, $indices);
                continue;
            }
            if ($token->is(\T_STRING)) {
                $position = array_search($token, $significant, true);
                $previous = $position === false ? null : ($significant[$position - 1]->id ?? null);
                $declaration = \in_array($previous, [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM, \T_FUNCTION], true);
                $fqn = ($namespace === '' ? '' : $namespace . '\\') . $token->text;
                if (\in_array($previous, [\T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                    $previous = $position === false ? null : ($significant[$position - 2]->id ?? null);
                    $declaration = $previous === \T_FUNCTION;
                }
                $method = $class === '' ? '' : $class . '::' . $token->text;
                if ($previous === \T_DOUBLE_COLON && $position !== false) {
                    $owner = $significant[$position - 2] ?? null;
                    if ($owner !== null && $owner->is([\T_NAME_FULLY_QUALIFIED, \T_NAME_QUALIFIED, \T_STRING])) {
                        $ownerName = $owner->is(\T_NAME_FULLY_QUALIFIED) ? ltrim($owner->text, '\\') : ($namespace === '' ? '' : $namespace . '\\') . $owner->text;
                        if (isset($symbols[$ownerName . '::' . $token->text])) {
                            throw new GateError('A mapped PHP method reference is outside the supported input profile.');
                        }
                    }
                }
                if ($declaration) {
                    if (isset($symbols[$fqn]) || ($previous === \T_FUNCTION && isset($symbols[$method]))) {
                        throw new GateError('A mapped PHP declaration is outside the supported input profile.');
                    }
                    continue;
                }
                if (!isset($aliases[$namespace][$token->text]) && (isset($symbols[$fqn]) || isset($symbols[$method]))) {
                    throw new GateError('A mapped PHP unresolved local name is unsupported.');
                }
            }
        }
        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        $boundary = \strlen($text);
        foreach ($edits as [$start, $length, $replacement]) {
            if ($start + $length > $boundary) {
                throw new GateError('Two PHP input declarations overlap a token span.');
            }
            $text = substr_replace($text, $replacement, $start, $length);
            $boundary = $start;
        }
        return [$text, $hits];
    }

    /** @param list<PhpToken> $tokens
     * @return array<int,array{string,string}> */
    private static function contexts(array $tokens): array
    {
        $namespace = '';
        $class = '';
        $pendingNamespace = null;
        $pendingClass = null;
        $stack = [];
        $contexts = [];
        foreach ($tokens as $position => $token) {
            $contexts[$position] = [$namespace, $class];
            if ($token->is(\T_NAMESPACE)) {
                $next = $tokens[$position + 1] ?? null;
                $pendingNamespace = $next !== null && $next->is([\T_STRING, \T_NAME_QUALIFIED]) ? $next->text : '';
            }
            if ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]) && ($tokens[$position - 1]->id ?? null) !== \T_DOUBLE_COLON) {
                $next = $tokens[$position + 1] ?? null;
                $pendingClass = $next !== null && $next->is(\T_STRING) ? ($namespace === '' ? '' : $namespace . '\\') . $next->text : '';
            }
            if ($token->text === '{') {
                $stack[] = [$namespace, $class];
                if ($pendingNamespace !== null) {
                    $namespace = $pendingNamespace;
                    $class = '';
                    $pendingNamespace = null;
                }
                if ($pendingClass !== null) {
                    $class = $pendingClass;
                    $pendingClass = null;
                }
            } elseif ($token->text === '}') {
                [$namespace, $class] = array_pop($stack) ?? ['', ''];
            } elseif ($token->text === ';' && $pendingNamespace !== null) {
                $namespace = $pendingNamespace;
                $class = '';
                $pendingNamespace = null;
            }
        }
        return $contexts;
    }

    /** @param array<string,list<int>> $images
     * @return array{string,list<int>} */
    private static function image(array $images): array
    {
        if (\count($images) !== 1) {
            throw new GateError('An ambiguous mapped PHP input cannot be inverted.');
        }
        $old = (string) array_key_first($images);
        return [$old, $images[$old]];
    }

    /** @param array<int,int> $hits
     * @param list<int> $indices */
    private static function credit(array &$hits, array $indices): void
    {
        foreach ($indices as $index) {
            $hits[$index] = ($hits[$index] ?? 0) + 1;
        }
    }

    private static function containsName(string $text, string $name): bool
    {
        return $name !== '' && preg_match('~(?<![A-Za-z0-9_.\\\\-])' . preg_quote($name, '~') . '(?![A-Za-z0-9_.\\\\-])~', $text) === 1;
    }

    private static function maskDocumentation(string $text): string
    {
        $fenced = false;
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            if (preg_match('~^\s*\*?\s*```~', $line) === 1) {
                $fenced = !$fenced;
                $line = str_repeat(' ', \strlen($line));
                continue;
            }
            if ($fenced) {
                $line = str_repeat(' ', \strlen($line));
                continue;
            }
            preg_match_all('~`~', $line, $matches, \PREG_OFFSET_CAPTURE);
            $rest = [];
            $pairs = [];
            for ($i = 0, $count = \count($matches[0]); $i < $count; ++$i) {
                $position = $matches[0][$i][1];
                if ($i + 1 < $count && substr($line, $position + 1, 5) === '@qmx-') {
                    $pairs[] = [$position, $matches[0][++$i][1]];
                } else {
                    $rest[] = $position;
                }
            }
            for ($i = 0, $count = \count($rest) - 1; $i < $count; $i += 2) {
                $pairs[] = [$rest[$i], $rest[$i + 1]];
            }
            foreach ($pairs as [$start, $end]) {
                $line = substr_replace($line, str_repeat(' ', $end - $start + 1), $start, $end - $start + 1);
            }
        }
        unset($line);
        return implode("\n", $lines);
    }
}
