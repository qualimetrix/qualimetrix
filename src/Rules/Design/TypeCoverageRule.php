<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Design;

use LogicException;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\Attribute\CliAlias;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Rules\AbstractRule;

/**
 * Rule that checks type coverage per class.
 *
 * Produces up to 3 violations per class:
 * - Parameter type coverage below threshold
 * - Return type coverage below threshold
 * - Property type coverage below threshold
 */
#[CliAlias('type-coverage-param-warning', 'param_warning')]
#[CliAlias('type-coverage-param-error', 'param_error')]
#[CliAlias('type-coverage-return-warning', 'return_warning')]
#[CliAlias('type-coverage-return-error', 'return_error')]
#[CliAlias('type-coverage-property-warning', 'property_warning')]
#[CliAlias('type-coverage-property-error', 'property_error')]
final class TypeCoverageRule extends AbstractRule
{
    public const string NAME = 'design.type-coverage';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Checks type coverage of parameters, return types, and properties per class';
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
        return [MetricName::TYPE_COVERAGE_PARAM];
    }

    /**
     * @return class-string<TypeCoverageOptions>
     */
    public static function getOptionsClass(): string
    {
        return TypeCoverageOptions::class;
    }

    /**
     * All three type-coverage channels (`.param`, `.return`, `.property`)
     * share one emission helper, {@see checkCoverage()}, which reports the
     * coverage percentage (`$coverage`) as `metricValue` and is judged worse
     * the *lower* it goes. The error threshold is checked first, followed by
     * the warning threshold, and both comparisons are strict — less type
     * coverage is worse debt.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            (new ViolationChannel(self::NAME, self::NAME . '.param'))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Lower),
            (new ViolationChannel(self::NAME, self::NAME . '.return'))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Lower),
            (new ViolationChannel(self::NAME, self::NAME . '.property'))->toKey() => ChannelDeclaration::magnitude(WorseDirection::Lower),
        ];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof TypeCoverageOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->allDeclarations() as $classInfo) {
            $subject = $classInfo->subject ?? throw new LogicException('Type coverage findings require an exact class declaration subject');
            if ($subject->toSymbolPath()->getType() !== SymbolType::Class_) {
                continue;
            }
            $metrics = $context->metrics->get($subject->toSymbolPath());

            // Apply @qmx-threshold overrides for this class
            $effectiveOptions = $this->getEffectiveOptions(
                $context,
                $this->options,
                $subject,
            );
            \assert($effectiveOptions instanceof TypeCoverageOptions);

            $specs = [
                [
                    'totalMetric' => MetricName::TYPE_COVERAGE_PARAM_TOTAL,
                    'coverageMetric' => MetricName::TYPE_COVERAGE_PARAM,
                    'spec' => ['label' => 'Parameter', 'code' => 'param', 'hint' => 'Add type declarations to method parameters', 'warning' => $effectiveOptions->paramWarning, 'error' => $effectiveOptions->paramError],
                ],
                [
                    'totalMetric' => MetricName::TYPE_COVERAGE_RETURN_TOTAL,
                    'coverageMetric' => MetricName::TYPE_COVERAGE_RETURN,
                    'spec' => ['label' => 'Return', 'code' => 'return', 'hint' => 'Add return type declarations to methods', 'warning' => $effectiveOptions->returnWarning, 'error' => $effectiveOptions->returnError],
                ],
                [
                    'totalMetric' => MetricName::TYPE_COVERAGE_PROPERTY_TOTAL,
                    'coverageMetric' => MetricName::TYPE_COVERAGE_PROPERTY,
                    'spec' => ['label' => 'Property', 'code' => 'property', 'hint' => 'Add type declarations to properties', 'warning' => $effectiveOptions->propertyWarning, 'error' => $effectiveOptions->propertyError],
                ],
            ];

            foreach ($specs as $coverageSpec) {
                $violation = $this->checkCoverage(
                    $metrics,
                    $subject,
                    $classInfo,
                    $coverageSpec['totalMetric'],
                    $coverageSpec['coverageMetric'],
                    $coverageSpec['spec'],
                );
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    /**
     * @param array{label: string, code: 'param'|'return'|'property', hint: string, warning: float, error: float} $spec
     */
    private function checkCoverage(
        MetricBag $metrics,
        MetricSubject $subject,
        SymbolInfo $classInfo,
        string $totalMetric,
        string $coverageMetric,
        array $spec,
    ): ?Violation {
        $total = $metrics->get($totalMetric);

        if ($total === null || (int) $total <= 0) {
            return null;
        }

        $coverage = (float) ($metrics->get($coverageMetric) ?? 0.0);

        if ($coverage < $spec['error']) {
            $severity = Severity::Error;
            $threshold = $spec['error'];
        } elseif ($coverage < $spec['warning']) {
            $severity = Severity::Warning;
            $threshold = $spec['warning'];
        } else {
            return null;
        }

        return new Violation(
            location: new Location($classInfo->file, $classInfo->line),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: $this->getName(),
            violationCode: match ($spec['code']) {
                'param' => 'design.type-coverage.param',
                'return' => 'design.type-coverage.return',
                'property' => 'design.type-coverage.property',
            },
            message: \sprintf(
                '%s type coverage is %.1f%% (minimum: %.1f%%). %s',
                $spec['label'],
                $coverage,
                $threshold,
                $spec['hint'],
            ),
            severity: $severity,
            metricValue: $coverage,
            recommendation: \sprintf('%s type coverage: %.1f%% (threshold: %.1f%%) — missing type declarations', $spec['label'], $coverage, $threshold),
            threshold: $threshold,
        );
    }
}
