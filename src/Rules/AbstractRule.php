<?php

declare(strict_types=1);

namespace Qualimetrix\Rules;

use InvalidArgumentException;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\LevelOptionsInterface;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

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
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [];
    }

    /**
     * Returns options with `@qmx-threshold` overrides applied for a specific symbol.
     *
     * Use this when the rule needs to read threshold fields from the options
     * (e.g., to build messages or determine which threshold was exceeded).
     *
     * @template T of RuleOptionsInterface|LevelOptionsInterface
     *
     * @param T $options The options to apply overrides to
     * @param string $file File path of the symbol
     * @param int $line Line number of the symbol
     *
     * @return T
     */
    protected function getEffectiveOptions(
        AnalysisContext $context,
        RuleOptionsInterface|LevelOptionsInterface $options,
        string $file,
        int $line,
    ): RuleOptionsInterface|LevelOptionsInterface {
        $override = $context->getThresholdOverride($this->getName(), $file, $line);

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
     * @param string $file File path of the symbol
     * @param int $line Line number of the symbol
     * @param int|float $value The metric value to check
     */
    protected function getEffectiveSeverity(
        AnalysisContext $context,
        RuleOptionsInterface|LevelOptionsInterface $options,
        string $file,
        int $line,
        int|float $value,
    ): ?Severity {
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $file, $line);

        return $effectiveOptions->getSeverity($value);
    }
}
