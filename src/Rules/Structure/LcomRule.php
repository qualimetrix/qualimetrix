<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Structure;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use InvalidArgumentException;

/**
 * Rule that checks LCOM (Lack of Cohesion of Methods) at class level.
 *
 * LCOM measures how well methods in a class work together:
 * - LCOM = 1: all methods share at least one property (cohesive)
 * - LCOM > 1: class could potentially be split into multiple classes
 */
final class LcomRule extends AbstractRule
{
    public const string NAME = 'design.lcom';
    private const string METRIC_LCOM = 'lcom';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof LcomOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', LcomOptions::class, $options::class),
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
        return 'Checks Lack of Cohesion of Methods (high values indicate class should be split)';
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
        return [self::METRIC_LCOM, 'methodCount', 'isReadonly'];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof LcomOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);

            // Skip readonly classes if configured
            if ($this->options->excludeReadonly && $metrics->get('isReadonly') === 1) {
                continue;
            }

            // Skip classes with too few methods
            $methodCount = (int) ($metrics->get('methodCount') ?? 0);
            if ($methodCount < $this->options->minMethods) {
                continue;
            }

            $lcom = $metrics->get(self::METRIC_LCOM);

            if ($lcom === null) {
                continue;
            }

            $lcomValue = (int) $lcom;
            $severity = $this->options->getSeverity($lcomValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error
                    ? $this->options->error
                    : $this->options->warning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf(
                        'LCOM (Lack of Cohesion) is %d, exceeds threshold of %d. Class could be split into %d cohesive parts',
                        $lcomValue,
                        $threshold,
                        $lcomValue,
                    ),
                    severity: $severity,
                    metricValue: $lcomValue,
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<LcomOptions>
     */
    public static function getOptionsClass(): string
    {
        return LcomOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'lcom-warning' => 'warning',
            'lcom-error' => 'error',
            'lcom-exclude-readonly' => 'excludeReadonly',
            'lcom-min-methods' => 'minMethods',
        ];
    }
}
