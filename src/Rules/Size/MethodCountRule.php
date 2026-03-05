<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

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
 * Rule that checks number of methods per class.
 *
 * Too many methods indicate a class may be doing too much
 * and should be split into smaller focused classes.
 */
final class MethodCountRule extends AbstractRule
{
    public const string NAME = 'size.method-count';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof MethodCountOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', MethodCountOptions::class, $options::class),
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
        return 'Checks number of methods per class';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Size;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['methodCount'];
    }

    /**
     * @return class-string<MethodCountOptions>
     */
    public static function getOptionsClass(): string
    {
        return MethodCountOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'size-class-warning' => 'warning',
            'size-class-error' => 'error',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof MethodCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Class_) as $classInfo) {
            $metrics = $context->metrics->get($classInfo->symbolPath);
            $methodCount = $metrics->get('methodCount');

            if ($methodCount === null) {
                continue;
            }

            $methodCountValue = (int) $methodCount;
            $severity = $this->options->getSeverity($methodCountValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error ? $this->options->error : $this->options->warning;

                $violations[] = new Violation(
                    location: new Location($classInfo->file, $classInfo->line),
                    symbolPath: $classInfo->symbolPath,
                    ruleName: $this->getName(),
                    violationCode: self::NAME,
                    message: \sprintf('Method count is %d, exceeds threshold of %d. Consider splitting into smaller focused classes', $methodCountValue, $threshold),
                    severity: $severity,
                    metricValue: $methodCountValue,
                );
            }
        }

        return $violations;
    }
}
