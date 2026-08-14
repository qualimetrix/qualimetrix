<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolInfo;

/**
 * Rule that checks number of constructor parameters (dependencies).
 *
 * Too many constructor parameters indicate a class has too many dependencies
 * and likely violates the Single Responsibility Principle.
 */
#[CliAlias('constructor-overinjection-warning', 'warning')]
#[CliAlias('constructor-overinjection-error', 'error')]
final class ConstructorOverinjectionRule extends AbstractRule
{
    public const string NAME = 'code-smell.constructor-overinjection';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks number of constructor parameters (dependencies)';
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
        return [MetricName::CODE_SMELL_PARAMETER_COUNT];
    }

    /**
     * @return class-string<ConstructorOverinjectionOptions>
     */
    public static function getOptionsClass(): string
    {
        return ConstructorOverinjectionOptions::class;
    }

    /**
     * `code-smell.constructor-overinjection` reports the constructor's
     * parameter count (`$parameterCountValue` — see the emission above) as
     * `metricValue`, judged worse the higher it goes:
     * {@see ConstructorOverinjectionOptions::getSeverity()}'s `$value >=
     * $this->error` (line 67) and `$value >= $this->warning` (line 71).
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Higher),
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ConstructorOverinjectionOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allCallables() as $symbolInfo) {
            $violations[] = $this->checkSymbol($symbolInfo, $context);
        }

        return array_values(array_filter(
            $violations,
            static fn(?Violation $violation): bool => $violation !== null,
        ));
    }

    private function checkSymbol(SymbolInfo $symbolInfo, AnalysisContext $context): ?Violation
    {
        /** @var ConstructorOverinjectionOptions $options */
        $options = $this->options;

        $subject = $symbolInfo->subject ?? throw new LogicException('Constructor findings require an exact callable subject');
        $declaration = $subject->declarationPath() ?? throw new LogicException('Constructor findings require a declaration subject');

        // Only check constructors.
        if ($declaration->logical->member !== '__construct') {
            return null;
        }

        // Skip global functions (no class context)
        if ($declaration->logical->type === null) {
            return null;
        }

        $metrics = $context->metrics->getSubject($subject);
        $parameterCount = $metrics->get(MetricName::CODE_SMELL_PARAMETER_COUNT);

        if ($parameterCount === null) {
            return null;
        }

        $parameterCountValue = (int) $parameterCount;

        /** @var ConstructorOverinjectionOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($parameterCountValue);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $className = $declaration->logical->type;

        return new Violation(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            subject: $subject,
            symbolPath: $declaration->logical,
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'Constructor of %s has %d parameters (threshold %d). Consider using a parameter object or splitting responsibilities',
                $className,
                $parameterCountValue,
                $threshold,
            ),
            severity: $severity,
            metricValue: $parameterCountValue,
            recommendation: \sprintf('Constructor parameters: %d (threshold: %d) — consider splitting responsibilities', $parameterCountValue, $threshold),
            threshold: $threshold,
        );
    }
}
