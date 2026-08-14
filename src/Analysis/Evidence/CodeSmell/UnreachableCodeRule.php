<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

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
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
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
        return [MetricName::CODE_SMELL_UNREACHABLE_CODE];
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
     * (line 65) and `$value >= $this->warning` (line 69).
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
        if (!$this->options instanceof UnreachableCodeOptions || !$this->options->isEnabled()) {
            return [];
        }

        return $this->violationsForReachableSymbols($context);
    }

    /**
     * @return list<Violation>
     */
    private function violationsForReachableSymbols(AnalysisContext $context): array
    {
        \assert($this->options instanceof UnreachableCodeOptions);
        $violations = [];

        foreach ($context->metrics->allCallables() as $symbolInfo) {
            $subject = $symbolInfo->subject ?? throw new LogicException('Unreachable code findings require an exact callable subject');
            $declaration = $subject->declarationPath() ?? throw new LogicException('Unreachable code findings require a declaration subject');
            if (!\in_array($declaration->logical->getType(), [SymbolType::Method, SymbolType::Function_], true)) {
                continue;
            }

            $metrics = $context->metrics->getSubject($subject);
            $unreachableCount = $metrics->get(MetricName::CODE_SMELL_UNREACHABLE_CODE);
            if ($unreachableCount === null) {
                continue;
            }

            $unreachableCountValue = (int) $unreachableCount;
            $severity = $this->getEffectiveSeverity($context, $this->options, $subject, $unreachableCountValue);
            if ($severity === null) {
                continue;
            }

            $firstLine = $metrics->get(MetricName::CODE_SMELL_UNREACHABLE_CODE_FIRST_LINE);
            $line = \is_int($firstLine) ? $firstLine : ($symbolInfo->line ?? 1);
            $violations[] = $this->checkSymbol($symbolInfo, $subject, $line, $unreachableCountValue, $severity);
        }

        return $violations;
    }

    private function checkSymbol(
        SymbolInfo $symbolInfo,
        MetricSubject $subject,
        int $line,
        int $unreachableCountValue,
        Severity $severity,
    ): Violation {
        return new Violation(
            location: new Location($symbolInfo->file, $line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf(
                'Found %d unreachable statement(s) after terminal statement (return/throw/exit/break/continue). Dead code should be removed',
                $unreachableCountValue,
            ),
            severity: $severity,
            metricValue: $unreachableCountValue,
            recommendation: 'Remove dead code after the terminal statement.',
        );
    }
}
