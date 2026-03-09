<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Security;

/**
 * Determines if a variable/property/constant name refers to a credential.
 *
 * Algorithm:
 * 1. Normalize: convert camelCase/PascalCase to snake_case, then lowercase, split by `_`
 * 2. Check suffix-match sensitive words (password, secret, etc.)
 * 3. Check compound-only words ("key" and "token") with qualifying prefixes
 * 4. Apply suffix and prefix blacklists to eliminate non-credential contexts
 */
final class SensitiveNameMatcher
{
    /** @var list<string> Words that are sensitive by themselves or as the last meaningful segment */
    private const SUFFIX_SENSITIVE_WORDS = [
        'password', 'passwd', 'pwd', 'secret', 'credential', 'credentials',
    ];

    /** @var list<string> "key" matches only when preceded by one of these */
    private const KEY_QUALIFYING_PREFIXES = [
        'api', 'secret', 'private', 'encryption', 'signing', 'auth', 'access',
    ];

    /** @var list<string> "token" matches only when preceded by one of these */
    private const TOKEN_QUALIFYING_PREFIXES = [
        'auth', 'access', 'bearer', 'api', 'refresh', 'jwt',
    ];

    /** @var list<string> Segments AFTER the sensitive word that indicate it's NOT a credential */
    private const SUFFIX_BLACKLIST = [
        'field', 'column', 'name', 'label', 'type', 'length', 'hash',
        'reset', 'policy', 'manager', 'service', 'provider', 'format',
        'pattern', 'regex', 'rule', 'input', 'param', 'header',
        'validator', 'checker', 'encoder', 'hasher', 'min', 'max',
        'expiry', 'expires', 'lifetime', 'timeout', 'prefix', 'suffix',
        'path', 'url', 'file', 'storage', 'handler', 'factory',
        'builder', 'generator', 'upgrader', 'verifier', 'extractor',
        'error', 'option', 'config', 'setting', 'strength',
    ];

    /** @var list<string> Segments BEFORE the sensitive word that indicate it's NOT a credential */
    private const PREFIX_BLACKLIST = [
        'option', 'config', 'setting', 'reset', 'type', 'error', 'invalid',
        'event', 'subject', 'name', 'field', 'column', 'url', 'path',
        'cache', 'storage', 'format', 'min', 'max', 'default', 'hashed', 'encoded',
        'is', 'has', 'can', 'should', 'needs', 'requires',
    ];

    /** @var list<list<string>> Normalized extra sensitive name patterns (each is a list of segments) */
    private readonly array $extraSensitivePatterns;

    /** @param list<string> $extraSensitiveNames Additional names to treat as sensitive (use snake_case) */
    public function __construct(array $extraSensitiveNames = [])
    {
        $patterns = [];
        foreach ($extraSensitiveNames as $name) {
            $segments = $this->normalize($name);
            if ($segments !== []) {
                $patterns[] = $segments;
            }
        }
        $this->extraSensitivePatterns = $patterns;
    }

    public function isSensitive(string $name): bool
    {
        $segments = $this->normalize($name);

        if ($segments === []) {
            return false;
        }

        return $this->checkSuffixSensitiveWord($segments)
            || $this->checkExtraSensitivePatterns($segments)
            || $this->checkCompoundWord($segments, 'key', self::KEY_QUALIFYING_PREFIXES)
            || $this->checkCompoundWord($segments, 'token', self::TOKEN_QUALIFYING_PREFIXES);
    }

    /**
     * Normalize a name into lowercase segments.
     *
     * Converts camelCase/PascalCase to snake_case, lowercases, splits by underscore.
     *
     * @return list<string>
     */
    private function normalize(string $name): array
    {
        // Insert underscore before uppercase letters (camelCase → camel_Case)
        $snaked = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);

        // Also handle sequences like "XMLParser" → "XML_Parser"
        $snaked = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snaked);

        $lower = strtolower($snaked);

        $parts = explode('_', $lower);

        return array_values(array_filter($parts, static fn(string $s): bool => $s !== ''));
    }

    /**
     * Check if any segment is a suffix-sensitive word (password, secret, etc.)
     * and not negated by blacklists.
     *
     * @param list<string> $segments
     */
    private function checkSuffixSensitiveWord(array $segments): bool
    {
        foreach ($segments as $index => $segment) {
            if (!\in_array($segment, self::SUFFIX_SENSITIVE_WORDS, true)) {
                continue;
            }

            if ($this->isNegatedByBlacklists($segments, $index)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Check extra sensitive name patterns (multi-segment matching).
     *
     * @param list<string> $segments
     */
    private function checkExtraSensitivePatterns(array $segments): bool
    {
        foreach ($this->extraSensitivePatterns as $pattern) {
            $patternLen = \count($pattern);
            $segmentLen = \count($segments);

            // Single-segment patterns: behave like SUFFIX_SENSITIVE_WORDS
            if ($patternLen === 1) {
                foreach ($segments as $index => $segment) {
                    if ($segment === $pattern[0] && !$this->isNegatedByBlacklists($segments, $index)) {
                        return true;
                    }
                }
                continue;
            }

            // Multi-segment patterns: find the subsequence in segments
            for ($start = 0; $start <= $segmentLen - $patternLen; $start++) {
                $match = true;
                for ($j = 0; $j < $patternLen; $j++) {
                    if ($segments[$start + $j] !== $pattern[$j]) {
                        $match = false;
                        break;
                    }
                }

                if (!$match) {
                    continue;
                }

                // Use the last segment of the pattern as the "sensitive index"
                // for blacklist checking (suffix blacklist checks segments AFTER it,
                // prefix blacklist checks segments BEFORE the pattern start)
                $lastIndex = $start + $patternLen - 1;

                if ($this->isNegatedByBlacklists($segments, $lastIndex, $start)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Check compound word with qualifying prefix.
     *
     * @param list<string> $segments
     * @param list<string> $qualifyingPrefixes
     */
    private function checkCompoundWord(array $segments, string $word, array $qualifyingPrefixes): bool
    {
        foreach ($segments as $index => $segment) {
            if ($segment !== $word) {
                continue;
            }

            if ($index === 0) {
                continue;
            }

            if (!\in_array($segments[$index - 1], $qualifyingPrefixes, true)) {
                continue;
            }

            if ($this->isNegatedByBlacklists($segments, $index)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Check if blacklist segments negate the sensitive word at the given position.
     *
     * @param list<string> $segments
     * @param int $prefixCheckEnd For multi-segment patterns, prefix blacklist checks
     *                            segments before this index (start of the pattern).
     *                            Defaults to $sensitiveIndex for single-word patterns.
     */
    private function isNegatedByBlacklists(array $segments, int $sensitiveIndex, ?int $prefixCheckEnd = null): bool
    {
        $prefixCheckEnd ??= $sensitiveIndex;

        // Check suffix blacklist: any segment AFTER the sensitive word
        $segmentCount = \count($segments);
        for ($i = $sensitiveIndex + 1; $i < $segmentCount; $i++) {
            if (\in_array($segments[$i], self::SUFFIX_BLACKLIST, true)) {
                return true;
            }
        }

        // Check prefix blacklist: any segment BEFORE the sensitive pattern
        for ($i = 0; $i < $prefixCheckEnd; $i++) {
            if (\in_array($segments[$i], self::PREFIX_BLACKLIST, true)) {
                return true;
            }
        }

        return false;
    }
}
