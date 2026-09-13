<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for class-level CBO (Coupling Between Objects) checks.
 *
 * CBO = |Ca ∪ Ce|
 * - Low CBO (<14): weakly coupled, easy to test
 * - Medium CBO (14-19): acceptable (warning)
 * - High CBO (>=20): tightly coupled, hard to isolate (error)
 *
 * The `scope` option controls which metric is checked:
 * - 'all' (default): uses CBO (original Chidamber & Kemerer, includes all dependencies)
 * - 'application': uses CBO_APP (excludes dependencies on configured framework namespaces)
 *
 * @qmx-threshold coupling.instability warning=0.84 -- The docblock this annotation replaced
 * already predicted this exact move: "0.81 silences today's 0.800 and still reports the next
 * efferent edge, which takes Ce to 9 and instability to 0.818." Two edges arrived instead of one --
 * `ConfigurationRefusal`/`RefusedPosition` -- because a review round decided the silent scope
 * fallback in `parseScope()` was the same silent-acceptance defect the project's closed word sets
 * exist to remove, and refusing it needed the same refusal framing
 * `LayerViolationOptions`/`UnassignedClassOptions` already use for their own `resolveSeverity()`/
 * `resolveMode()`. Ca=2, Ce=10 puts this at 0.833. The reasoning that made 0.800 and 0.81
 * mis-modelling rather than a defect is unchanged by which edge pushed the ratio: a rule options
 * class is efferent by construction, and the sibling classes carrying this shape with a single
 * afferent edge are not judged at all only because `min_afferent: 2` filters them out. 0.84
 * silences today's 0.833 and still reports the next efferent edge.
 */
final readonly class ClassCboOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    /**
     * Duplicates the owning rule's name as a literal rather than referencing
     * its class constant, so this level Options DTO does not gain a
     * dependency edge onto the rule it configures — the same reason
     * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions} spells its own rule name out.
     */
    private const string RULE_NAME = 'coupling.cbo';

    /** @var list<string> */
    private const array KNOWN_SCOPES = ['all', 'application'];

    public function __construct(
        public bool $enabled = true,
        public int $warning = 14,
        public int $error = 20,
        public string $scope = 'all',
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // If config is empty, use defaults (all enabled)
        if ($config === []) {
            return new self();
        }

        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 14, 20);
        $scope = self::parseScope($config);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
            scope: $scope,
        );
    }

    /**
     * Keep `threshold` here even though no constructor parameter names it:
     * `ThresholdParser::parse()` reads that key through its default
     * `$thresholdKey`.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'error' => RuleOptionShape::integer()->orNull(),
            'scope' => RuleOptionShape::oneOf('all', 'application')->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $cbo = (int) $value;

        if ($cbo >= $this->error) {
            return Severity::Error;
        }

        if ($cbo >= $this->warning) {
            return Severity::Warning;
        }

        return null;
    }

    /**
     * The declaration above refuses an unknown word before `fromArray()` is
     * ever reached, but only on the path that goes through the option-key
     * seam (`RuleOptionsFactory`/`RuleOptionKeyRecognition`) — and this
     * method is public and reachable directly, bypassing that seam. Silently
     * measuring 'all' for a typo the seam never saw is the silent-acceptance
     * defect the project's closed word sets exist to remove elsewhere; this
     * class refuses it here for the same reason instead of keeping the
     * fallback alive for a caller that skips the seam.
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationRefusal When `scope` is set to something other
     *                              than a known scope word.
     */
    private static function parseScope(array $config): string
    {
        $scope = $config['scope'] ?? null;

        if ($scope === null) {
            return 'all';
        }

        if (!\is_string($scope) || !\in_array($scope, self::KNOWN_SCOPES, true)) {
            $allowed = implode(', ', array_map(static fn(string $word): string => "'{$word}'", self::KNOWN_SCOPES));

            throw self::refusal('scope', \sprintf(
                'Option "scope" for rule "%s" has unknown value %s; expected one of %s.',
                self::RULE_NAME,
                \is_string($scope) ? "\"{$scope}\"" : get_debug_type($scope),
                $allowed,
            ));
        }

        return $scope;
    }

    /**
     * This class answers about `scope` in its own words rather than letting
     * the generic "unknown option" refusal speak for it.
     */
    private static function refusal(string $option, string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open([self::RULE_NAME, $option], $option),
            $summary,
        );
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (int) $warning : $this->warning,
            error: $error !== null ? (int) $error : $this->error,
            scope: $this->scope,
        );
    }

    public function warningBoundary(): int
    {
        return $this->warning;
    }
}
