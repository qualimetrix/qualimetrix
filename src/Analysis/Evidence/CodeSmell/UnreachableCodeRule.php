<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;

use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\Attribute\CliAlias;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Rule that detects unreachable code after terminal statements.
 *
 * Statements after return, throw, exit/die, continue, or break
 * are unreachable and should be removed.
 */
#[CliAlias('unreachable-code-warning', 'warning')]
#[CliAlias('unreachable-code-error', 'error')]
final class UnreachableCodeRule extends AbstractRule
{
    public const string NAME = 'code-smell.unreachable-code';
    public const string DOCS_PAGE = 'rules/code-smell.md';

    public const int REMEDIATION_MINUTES = 10;

    public const ChannelShape SHAPE = ChannelShape::Magnitude;
    public function getName(): string
    {
        return self::NAME;
    }

    public static function getDescription(): string
    {
        return 'Detects unreachable code after terminal statements';
    }

    /**
     * @return class-string<UnreachableCodeOptions>
     */
    public static function getOptionsClass(): string
    {
        return UnreachableCodeOptions::class;
    }

    /**
     * `code-smell.unreachable-code` reports the count of unreachable
     * statements (`$unreachableCountValue` — see the emission above) as
     * `metricValue`, judged worse the higher it goes:
     * {@see UnreachableCodeOptions::getSeverity()}'s `$value >= $this->error`
     * and `$value >= $this->warning`.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => self::judgingHigher(
                [MetricName::CODE_SMELL_UNREACHABLE_CODE],
                SymbolLevel::Callable,
            )->withGates(
                self::populationGate('callable-coordinate', self::NAME, SymbolLevel::Callable, 'callable', self::kindIn('callable-coordinate', [SymbolType::Method, SymbolType::Function_]), 'The subject is outside the declared symbol coordinate.'),
                self::populationGate('published-value', self::NAME, SymbolLevel::Callable, 'callable', self::keyPresent('published-value', [MetricName::CODE_SMELL_UNREACHABLE_CODE]), 'The rule metric was not published.'),
            ),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof UnreachableCodeOptions || !$this->options->isEnabled()) {
            return [];
        }

        return $this->findingsForReachableSymbols($context);
    }

    /**
     * @return list<Finding>
     */
    private function findingsForReachableSymbols(AnalysisContext $context): array
    {
        \assert($this->options instanceof UnreachableCodeOptions);
        $findings = [];
        $populationDeclaration = self::channelDeclarations()[self::NAME];

        foreach ($context->metrics->allCallables() as $symbolInfo) {
            $subject = $symbolInfo->subject ?? throw new LogicException('Unreachable code findings require an exact callable subject');
            $declaration = $subject->declarationPath() ?? throw new LogicException('Unreachable code findings require a declaration subject');
            $metrics = $this->admittedMetrics($context, $subject, $populationDeclaration, static fn(MetricBag $metrics): array => [GateInput::metrics('published-value', $metrics)], [GateInput::kind('callable-coordinate', $declaration->logical->getType())], unit: 'callable', level: SymbolLevel::Callable);
            if ($metrics === null) {
                continue;
            }
            $unreachableCount = $metrics->get(MetricName::CODE_SMELL_UNREACHABLE_CODE);

            $unreachableCountValue = (int) $unreachableCount;
            $severity = $this->getEffectiveSeverity($context, $this->options, $subject, $unreachableCountValue);
            if ($severity === null) {
                continue;
            }

            $firstLine = $metrics->get(MetricName::CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE);
            $line = \is_int($firstLine) ? $firstLine : ($symbolInfo->line ?? 1);
            $findings[] = $this->checkSymbol($symbolInfo, $subject, $line, $unreachableCountValue, $severity);
        }

        return $findings;
    }

    private function checkSymbol(
        SymbolInfo $symbolInfo,
        MetricSubject $subject,
        int $line,
        int $unreachableCountValue,
        Severity $severity,
    ): Finding {
        return new Finding(
            location: new Location($symbolInfo->file, $line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            code: self::NAME,
            message: \sprintf(
                'Found %d unreachable statement(s) after terminal statement (return/throw/exit/break/continue). Dead code should be removed',
                $unreachableCountValue,
            ),
            severity: $severity,
            metricValue: $unreachableCountValue,
            recommendation: 'Remove dead code after the terminal statement.',
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
