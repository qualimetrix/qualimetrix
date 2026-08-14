<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Size;

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
 * Rule that checks number of methods per class.
 *
 * Too many methods indicate a class may be doing too much
 * and should be split into smaller focused classes.
 */
#[CliAlias('method-count-warning', 'warning')]
#[CliAlias('method-count-error', 'error')]
final class MethodCountRule extends AbstractRule
{
    public const string NAME = 'size.method-count';

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
        return [MetricName::STRUCTURE_METHOD_COUNT];
    }

    /**
     * @return class-string<MethodCountOptions>
     */
    public static function getOptionsClass(): string
    {
        return MethodCountOptions::class;
    }

    /**
     * `size.method-count` reports the class's method count
     * (`$methodCountValue` — see the emission above) as `metricValue`,
     * judged worse the higher it goes:
     * {@see MethodCountOptions::getSeverity()}'s `$value >= $this->error`
     * (line 67) / `$value >= $this->warning` (line 71).
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
        if (!$this->options instanceof MethodCountOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Method count findings require an exact class declaration subject');
            if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }
            $metrics = $context->metrics->get($subject->toSymbolPath());
            $methodCount = $metrics->get(MetricName::STRUCTURE_METHOD_COUNT);

            if ($methodCount === null) {
                continue;
            }

            $methodCountValue = (int) $methodCount;
            /** @var MethodCountOptions $effectiveOptions */
            $effectiveOptions = $this->getEffectiveOptions($context, $this->options, $subject);
            $violation = $this->violationForClass($classInfo, $subject, $methodCountValue, $effectiveOptions);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private function violationForClass(
        SymbolInfo $classInfo,
        MetricSubject $subject,
        int $methodCount,
        MethodCountOptions $options,
    ): ?Violation {
        $severity = $options->getSeverity($methodCount);
        if ($severity === null) {
            return null;
        }

        $threshold = $severity === Severity::Error ? $options->error : $options->warning;

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: self::NAME,
            message: \sprintf('Method count is %d, exceeds threshold of %d. Consider splitting into smaller focused classes', $methodCount, $threshold),
            severity: $severity,
            metricValue: $methodCount,
            recommendation: \sprintf('Methods: %d (threshold: %d) — too many methods', $methodCount, $threshold),
            threshold: $threshold,
        );
    }
}
