<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Population\PopulationIdentity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;

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
    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 60;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Checks number of constructor parameters (dependencies)';
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
     * $this->error` and `$value >= $this->warning`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => self::judgingHigher(
                [MetricName::CODE_SMELL_PARAMETER_COUNT],
                SymbolLevel::Callable,
            )->withGates(
                self::populationGate('constructor-name', self::NAME, SymbolLevel::Callable, 'callable', self::nameMatches('constructor-name'), 'Only constructors are judged.'),
                self::populationGate('class-context', self::NAME, SymbolLevel::Callable, 'callable', self::contextGuard('callableHasClassContext'), 'The callable has no class context.'),
                self::populationGate('parameter-count', self::NAME, SymbolLevel::Callable, 'callable', self::keyPresent('parameter-count', [MetricName::CODE_SMELL_PARAMETER_COUNT]), 'Parameter count was not published.'),
            ),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof ConstructorOverinjectionOptions || !$this->options->isEnabled()) {
            return [];
        }

        $findings = [];
        $populationDeclaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allCallables() as $symbolInfo) {
            $findings[] = $this->checkSymbol($symbolInfo, $context, $populationDeclaration);
        }

        return array_values(array_filter(
            $findings,
            static fn(?Finding $finding): bool => $finding !== null,
        ));
    }

    private function checkSymbol(SymbolInfo $symbolInfo, AnalysisContext $context, ChannelDeclaration $populationDeclaration): ?Finding
    {
        /** @var ConstructorOverinjectionOptions $options */
        $options = $this->options;

        $subject = $symbolInfo->subject ?? throw new LogicException('Constructor findings require an exact callable subject');
        $declaration = $subject->declarationPath() ?? throw new LogicException('Constructor findings require a declaration subject');

        $metrics = null;
        if (!$context->admit(self::NAME, new FindingChannel(self::NAME), SymbolLevel::Callable, PopulationIdentity::subject($subject, 'callable'), $populationDeclaration, (static function () use ($context, $subject, $declaration, &$metrics): iterable {
            yield GateInput::boundName('constructor-name', strtolower($declaration->logical->member ?? '') === '__construct');
            yield GateInput::context('callableHasClassContext', $declaration->logical->type !== null);
            $metrics = $context->metrics->getSubject($subject);
            yield GateInput::metrics('parameter-count', $metrics);
        })())) {
            return null;
        }
        $parameterCount = $metrics->get(MetricName::CODE_SMELL_PARAMETER_COUNT);

        $parameterCountValue = (int) $parameterCount;

        /** @var ConstructorOverinjectionOptions $effectiveOptions */
        $effectiveOptions = $this->getEffectiveOptions($context, $options, $subject);
        $severity = $effectiveOptions->getSeverity($parameterCountValue);

        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $effectiveOptions->error : $effectiveOptions->warning;
        $className = $declaration->logical->type;

        return new Finding(
            location: new Location($symbolInfo->file, $symbolInfo->line),
            subject: $subject,
            symbolPath: $declaration->logical,
            ruleName: $this->getName(),
            code: self::NAME,
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

    /**
     * Declared, never inferred from the options class: `@qmx-threshold` can
     * retune this rule. See
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdOverrideSupportReader},
     * which also explains why this is a constant and why it is declared last.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = true;
}
