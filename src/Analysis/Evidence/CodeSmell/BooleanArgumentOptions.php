<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for the boolean-argument rule.
 *
 * Allows whitelisting boolean parameters with self-documenting prefixes
 * (e.g., $isActive, $hasPermission, $canEdit).
 *
 * By default, promoted constructor properties (`public bool $x`) are not
 * flagged: a promoted parameter is a field declaration, not a behavior
 * switch, so the rule's "split into two methods / use an enum"
 * recommendation doesn't apply to it. Set `flag_promoted_properties: true`
 * to restore the old behavior and flag them too.
 */
final readonly class BooleanArgumentOptions implements RuleOptionsInterface, EntryFilteringOptionsInterface
{
    private const array DEFAULT_PREFIXES = ['is', 'has', 'can', 'should', 'will', 'did', 'was'];

    /**
     * @param list<string> $allowedPrefixes Prefixes for allowed boolean param names (camelCase boundary)
     */
    public function __construct(
        public bool $enabled = true,
        public array $allowedPrefixes = self::DEFAULT_PREFIXES,
        public bool $flagPromotedProperties = false,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $raw = $config['allowedPrefixes'] ?? $config['allowed_prefixes'] ?? null;

        $prefixes = self::DEFAULT_PREFIXES;
        if (\is_string($raw)) {
            $prefixes = [$raw];
        } elseif (\is_array($raw)) {
            $prefixes = array_values(array_filter($raw, 'is_string'));
        }

        $flagPromotedProperties = $config['flagPromotedProperties'] ?? $config['flag_promoted_properties'] ?? false;

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            allowedPrefixes: $prefixes,
            flagPromotedProperties: (bool) $flagPromotedProperties,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $value > 0 ? Severity::Warning : null;
    }

    /**
     * Check if param name matches an allowed prefix at a word boundary.
     *
     * Uses strncasecmp for case-insensitive prefix match, then checks that
     * the boundary is valid: end of string, underscore (snake_case), or
     * uppercase following lowercase (camelCase). This prevents $ISLAND
     * matching "is" -- both "IS" and "LA" are uppercase, so no camelCase boundary.
     *
     * Results: $isActive=yes, $island=no, $has_value=yes, $ISLAND=no,
     *          $IS_ACTIVE=yes (underscore), $is=yes (exact), $cannon=no
     */
    public function isAllowedPrefix(string $paramName): bool
    {
        $name = ltrim($paramName, '$');
        if ($name === '') {
            return false;
        }

        foreach ($this->allowedPrefixes as $prefix) {
            $len = \strlen($prefix);
            if (strncasecmp($name, $prefix, $len) !== 0) {
                continue;
            }

            $next = $name[$len] ?? '';
            if ($next === '') {
                return true;           // exact match: $is, $has
            }
            if ($next === '_') {
                return true;           // snake_case: $has_value, $IS_ACTIVE
            }
            // camelCase boundary: next char uppercase AND previous char lowercase
            // This rejects $ISLAND (prev='S' uppercase) but allows $isActive (prev='s' lowercase)
            $prev = $name[$len - 1];
            if (ctype_upper($next) && !ctype_upper($prev)) {
                return true;
            }
        }

        return false;
    }

    public function isExtraAllowed(string $extra): bool
    {
        return $this->isAllowedPrefix($extra);
    }
}
