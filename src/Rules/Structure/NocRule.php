<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Structure;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use InvalidArgumentException;

/**
 * Rule that checks NOC (Number of Children) at class level.
 *
 * NOC measures how many classes directly extend (inherit from) a given class.
 * High NOC indicates:
 * - Wide reuse through inheritance
 * - High impact of changes (affects many subclasses)
 * - Potential need for interface instead of class inheritance
 * - Possible violation of Liskov Substitution Principle
 */
final class NocRule extends AbstractRule
{
    public const string NAME = 'noc';
    private const string METRIC_NOC = 'noc';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof NocOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', NocOptions::class, $options::class),
            );
        }
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks Number of Children (many direct subclasses indicate wide impact)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Design;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [self::METRIC_NOC];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof NocOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $noc = $metrics->get(self::METRIC_NOC);

            if ($noc === null || $noc === 0) {
                continue;
            }

            $nocValue = (int) $noc;
            $severity = $this->options->getSeverity($nocValue);

            if ($severity !== null) {
                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf('Has %d direct subclasses (consider using interfaces)', $nocValue),
                    severity: $severity,
                    metricValue: $nocValue,
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<NocOptions>
     */
    public static function getOptionsClass(): string
    {
        return NocOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'noc-warning' => 'warning',
            'noc-error' => 'error',
        ];
    }
}
