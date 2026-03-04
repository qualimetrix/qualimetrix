<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Maintainability;

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
 * Rule that checks Maintainability Index at method level.
 *
 * MI thresholds (lower is worse):
 * - MI > 85: good
 * - MI 65-85: warning
 * - MI < 65: error
 */
final class MaintainabilityRule extends AbstractRule
{
    public const string NAME = 'maintainability';
    private const string METRIC_MI = 'mi';

    public function __construct(
        RuleOptionsInterface $options,
    ) {
        if (!$options instanceof MaintainabilityOptions) {
            throw new InvalidArgumentException(
                \sprintf('Expected %s, got %s', MaintainabilityOptions::class, $options::class),
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
        return 'Checks Maintainability Index (lower values indicate harder to maintain code)';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Maintainability;
    }

    /**
     * @return list<string>
     */
    public function requires(): array
    {
        return [self::METRIC_MI, 'methodLoc'];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        if (!$this->options instanceof MaintainabilityOptions || !$this->options->isEnabled()) {
            return [];
        }

        $violations = [];

        foreach ($context->metrics->all(SymbolType::Method) as $methodInfo) {
            // Skip test files if configured
            if ($this->options->excludeTests && $this->isTestFile($methodInfo->file)) {
                continue;
            }

            $metrics = $context->metrics->get($methodInfo->symbolPath);

            // Skip methods with too few LOC
            $methodLoc = (int) ($metrics->get('methodLoc') ?? 0);
            if ($methodLoc < $this->options->minLoc) {
                continue;
            }

            $mi = $metrics->get(self::METRIC_MI);

            if ($mi === null) {
                continue;
            }

            $miValue = (float) $mi;
            $severity = $this->options->getSeverity($miValue);

            if ($severity !== null) {
                $threshold = $severity === Severity::Error
                    ? $this->options->error
                    : $this->options->warning;

                $violations[] = new Violation(
                    location: new Location($methodInfo->file, $methodInfo->line),
                    symbolPath: $methodInfo->symbolPath,
                    ruleName: $this->getName(),
                    message: \sprintf(
                        'Maintainability Index is %.1f, below threshold of %.1f. Reduce complexity and size to improve maintainability',
                        $miValue,
                        $threshold,
                    ),
                    severity: $severity,
                    metricValue: (int) round($miValue),
                );
            }
        }

        return $violations;
    }

    /**
     * @return class-string<MaintainabilityOptions>
     */
    public static function getOptionsClass(): string
    {
        return MaintainabilityOptions::class;
    }

    /**
     * @return array<string, string>
     */
    public static function getCliAliases(): array
    {
        return [
            'mi-warning' => 'warning',
            'mi-error' => 'error',
            'mi-exclude-tests' => 'excludeTests',
            'mi-min-loc' => 'minLoc',
        ];
    }

    private function isTestFile(string $file): bool
    {
        return str_ends_with($file, 'Test.php')
            || str_contains($file, '/tests/')
            || str_contains($file, '/Tests/');
    }
}
