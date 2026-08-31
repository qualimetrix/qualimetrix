<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\TypeCoverage;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for one type-coverage dimension.
 *
 * One class, three producers: `design.type-coverage.param`,
 * `design.type-coverage.return` and `design.type-coverage.property` measure
 * different declarations and are configured independently, but the shape of
 * the answer — one warning boundary, one error boundary, coverage in percent
 * where lower is worse — is the same for all three. Configuration is keyed by
 * producer rule name, never by Options class
 * ({@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass::optionsServiceId()}),
 * so sharing the implementation does not share the configured instance.
 *
 * Default thresholds:
 * - warning: below 80% coverage
 * - error: below 50% coverage
 */
final readonly class TypeCoverageOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface, ShorthandOptionKeysInterface
{
    public function __construct(
        public bool $enabled = true,
        public float $warning = 80.0,
        public float $error = 50.0,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 80.0, 50.0);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (float) $thresholds['warning'],
            error: (float) $thresholds['error'],
        );
    }

    /**
     * @return list<string>
     */
    public static function getShorthandOptionKeys(): array
    {
        return ['threshold'];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value < $this->error) {
            return Severity::Error;
        }

        if ($value < $this->warning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (float) $warning : $this->warning,
            error: $error !== null ? (float) $error : $this->error,
        );
    }

    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return InvertedOverrideValidator::instance();
    }

    public function warningBoundary(): float
    {
        return $this->warning;
    }
}
