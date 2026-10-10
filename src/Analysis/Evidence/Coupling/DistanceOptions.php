<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use InvalidArgumentException;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusal;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Pattern\NamespaceMatcher;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Throwable;

/**
 * Options for DistanceRule.
 *
 * Distance from main sequence: D = |A + I - 1|
 * Range: [0, 1]
 * - 0: on the main sequence (balanced)
 * - 1: far from the main sequence (problematic)
 *
 * Namespace filtering:
 * - By default, auto-detects project namespaces from composer.json (autoload.psr-4)
 * - Use `includeNamespaces` to override auto-detection with explicit list
 * - Use `suppress_namespaces` (universal per-rule option) to exclude specific namespaces
 * - External dependencies (not matching project namespaces) are always excluded
 */
final readonly class DistanceOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    private const string RULE_NAME = 'coupling.distance';

    /**
     * @param bool $enabled Enable distance rule
     * @param float $maxDistanceWarning Warning threshold for distance
     * @param float $maxDistanceError Error threshold for distance
     * @param list<NamespacePattern>|null $includeNamespaces Override auto-detected project namespaces (null = auto-detect from composer.json)
     * @param int $minTypeCount Minimum number of own types in namespace for analysis (0 = disabled)
     */
    public function __construct(
        public bool $enabled = true,
        public float $maxDistanceWarning = 0.3,
        public float $maxDistanceError = 0.5,
        public ?array $includeNamespaces = null,
        public int $minTypeCount = 3,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdParser::parse($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 0.3, 0.5);
        return new self(
            enabled: $config->boolean('enabled', true),
            maxDistanceWarning: $thresholds['warning'],
            maxDistanceError: $thresholds['error'],
            includeNamespaces: self::includeNamespaces($config->list('include-namespaces')),
            minTypeCount: $config->integer('min-type-count', 3),
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'max-distance-error' => RuleOptionShape::number()->orNull(),
            'max-distance-warning' => RuleOptionShape::number()->orNull(),
            'min-type-count' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
        ])->alsoAcceptedAndValidatedByTheClass(
            'include-namespaces',
            RuleOptionShape::listOf(RuleOptionShape::mapOf(RuleOptionShape::nonEmptyText())->judgedInEachLayer(
                static function (\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value, array $path): void {
                    if (!$value instanceof \Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface) {
                        throw new LogicException('A namespace selector judgement requires its declared mapping.');
                    }
                    try {
                        if ($path === []) {
                            throw new LogicException('A namespace selector judgement requires its exact element position.');
                        }
                        self::namespacePattern($value->plain(), $path[\count($path) - 1]);
                    } catch (RuleOptionRefusal $error) {
                        $value->refuse($error->getMessage());
                    }
                },
            ))->judgedInEachLayer(
                static function (\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value): void {
                    if (!$value instanceof ResolvedListInterface) {
                        throw new LogicException('A namespace selector list judgement requires its declared list.');
                    }
                    try {
                        self::includeNamespaces($value);
                    } catch (RuleOptionRefusal $error) {
                        $value->refuse($error->getMessage());
                    }
                },
            ),
        )->band('threshold', 'max-distance-warning', 'max-distance-error', BandDirection::Rising);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $distance = (float) $value;

        if ($distance >= $this->maxDistanceError) {
            return Severity::Error;
        }

        if ($distance >= $this->maxDistanceWarning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            maxDistanceWarning: $warning !== null ? (float) $warning : $this->maxDistanceWarning,
            maxDistanceError: $error !== null ? (float) $error : $this->maxDistanceError,
            includeNamespaces: $this->includeNamespaces,
            minTypeCount: $this->minTypeCount,
        );
    }

    public function warningBoundary(): float
    {
        return $this->maxDistanceWarning;
    }

    /** @return list<NamespacePattern>|null */
    private static function includeNamespaces(?ResolvedListInterface $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $patterns = [];
        foreach ($value->items() as $index => $selector) {
            $patterns[] = self::namespacePattern($selector->plain(), $index);
        }

        try {
            new NamespaceMatcher($patterns);
        } catch (InvalidArgumentException $e) {
            throw self::selectorRefusal($e->getMessage(), $e);
        }

        return $patterns;
    }

    private static function namespacePattern(mixed $selector, int|string $index): NamespacePattern
    {
        if (!\is_array($selector) || \count($selector) !== 1) {
            throw self::selectorRefusal(\sprintf('entry %s must be a one-entry mapping: {exact: value}, {subtree: value}, or {regex: value}; bare strings are not supported', $index));
        }

        $kind = array_key_first($selector);
        $pattern = \is_string($kind) ? $selector[$kind] : null;
        if (!\is_string($kind) || !\is_string($pattern) || $pattern === '') {
            throw self::selectorRefusal(\sprintf('entry %s must name exact, subtree, or regex with a non-empty string value', $index));
        }

        try {
            return new NamespacePattern(SelectorDefinition::fromKindAndValue($kind, $pattern));
        } catch (InvalidArgumentException $e) {
            throw self::selectorRefusal(\sprintf('entry %s is invalid: %s', $index, $e->getMessage()), $e);
        }
    }

    private static function selectorRefusal(string $problem, ?Throwable $previous = null): RuleOptionRefusal
    {
        return new RuleOptionRefusal(['include-namespaces'], \sprintf(
            'Option "include_namespaces" for rule "%s" %s.',
            self::RULE_NAME,
            $problem,
        ));
    }
}
