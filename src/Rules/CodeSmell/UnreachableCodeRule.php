<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\CodeSmell;

use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Rules\AbstractRule;
use InvalidArgumentException;

/**
 * Rule that detects unreachable code after terminal statements.
 *
 * Statements after return, throw, exit/die, continue, or break
 * are unreachable and should be removed.
 */
final class UnreachableCodeRule extends AbstractRule
{
    public const string NAME = 'code-smell.unreachable-code';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof UnreachableCodeOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', UnreachableCodeOptions::class, $options::class),
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
        return 'Detects unreachable code after terminal statements';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::CodeSmell;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return ['unreachableCode'];
    }

    /**
     * @return class-string<UnreachableCodeOptions>
     */
    public static function getOptionsClass(): string
    {
        return UnreachableCodeOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'unreachable-code-warning' => 'warning',
            'unreachable-code-error' => 'error',
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof UnreachableCodeOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ([SymbolType::Method, SymbolType::Function_] as $type) {
            foreach ($context->metrics->all($type) as $symbolInfo) {
                $violation = $this->checkSymbol($symbolInfo, $context);

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    private function checkSymbol(SymbolInfo $symbolInfo, AnalysisContext $context): ?Violation
    {
        /** @var UnreachableCodeOptions $options */
        $options = $this->options;

        $metrics = $context->metrics->get($symbolInfo->symbolPath);
        $unreachableCount = $metrics->get('unreachableCode');

        if ($unreachableCount === null) {
            return null;
        }

        $unreachableCountValue = (int) $unreachableCount;
        $severity = $options->getSeverity($unreachableCountValue);

        if ($severity === null) {
            return null;
        }

        $firstLine = $metrics->get('unreachableCode.firstLine');
        $line = $firstLine !== null ? (int) $firstLine : $symbolInfo->line;

        return new Violation(
            location: new Location($symbolInfo->file, $line),
            symbolPath: $symbolInfo->symbolPath,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'Found %d unreachable statement(s) after terminal statement (return/throw/exit/break/continue). Dead code should be removed',
                $unreachableCountValue,
            ),
            severity: $severity,
            metricValue: $unreachableCountValue,
        );
    }
}
