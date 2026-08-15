<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Base class for all analysis rules.
 *
 * Provides common functionality and protected access to options.
 * Validates that the options instance matches the expected class from getOptionsClass().
 */
abstract class AbstractRule implements RuleInterface
{
    /**
     * @param RuleOptionsInterface $options Rule options
     */
    public function __construct(
        protected readonly RuleOptionsInterface $options,
    ) {
        $expected = static::getOptionsClass();
        if (!$options instanceof $expected) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', $expected, $options::class),
            );
        }
    }

    abstract public function getName(): string;

    abstract public function getDescription(): string;

    abstract public function getCategory(): RuleCategory;

    /**
     * Returns options with `@qmx-threshold` overrides applied for a specific symbol.
     *
     * Use this when the rule needs to read threshold fields from the options
     * (e.g., to build messages or determine which threshold was exceeded).
     *
     * @template T of RuleOptionsInterface|LevelOptionsInterface
     *
     * @param T $options The options to apply overrides to
     * @param MetricSubject $subject Exact or aggregate subject under evaluation
     *
     * @return T
     */
    protected function getEffectiveOptions(
        AnalysisContext $context,
        RuleOptionsInterface|LevelOptionsInterface $options,
        MetricSubject $subject,
    ): RuleOptionsInterface|LevelOptionsInterface {
        $override = $context->getThresholdOverride($this->getName(), $subject);

        if ($override !== null && $options instanceof ThresholdAwareOptionsInterface) {
            return $options->withOverride($override->warning, $override->error);
        }

        return $options;
    }

    /**
     * Returns the effective severity for a metric value, applying `@qmx-threshold` overrides.
     *
     * Rules should call this instead of $options->getSeverity() directly to support
     * per-symbol threshold overrides via `@qmx-threshold` annotations.
     *
     * @param RuleOptionsInterface|LevelOptionsInterface $options The options to use for severity check
     * @param MetricSubject $subject Exact or aggregate subject under evaluation
     * @param int|float $value The metric value to check
     */
    protected function getEffectiveSeverity(
        AnalysisContext $context,
        RuleOptionsInterface|LevelOptionsInterface $options,
        MetricSubject $subject,
        int|float $value,
    ): ?Severity {
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);

        return $effectiveOptions->getSeverity($value);
    }
}
