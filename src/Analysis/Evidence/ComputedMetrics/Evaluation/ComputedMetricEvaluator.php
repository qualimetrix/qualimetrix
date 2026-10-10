<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDependencyGraphCalculator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluatorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricValueAbsence;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

class ComputedMetricEvaluator implements ComputedMetricEvaluatorInterface
{
    private readonly ComputedMetricExpression $expression;
    private readonly ComputedMetricSubjectEvaluation $subjectEvaluation;
    private readonly ComputedMetricDependencyGraphCalculator $dependencyGraphCalculator;

    public function __construct(
        private readonly ComputedMetricAnalysis $analysis,
        private readonly ProfilerInterface $profiler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->expression = new ComputedMetricExpression();
        $this->subjectEvaluation = new ComputedMetricSubjectEvaluation($this->expression);
        $this->dependencyGraphCalculator = new ComputedMetricDependencyGraphCalculator($this->expression);
    }

    public function evaluate(MetricRepositoryInterface $repo, int $filesAnalyzed): ComputedMetricEvaluationSummary
    {
        $definitions = $this->analysis->all();
        $summary = new ComputedMetricEvaluationSummary();
        if ($filesAnalyzed === 0 || $definitions === []) {
            return $summary;
        }

        $this->profiler->start('computed', 'pipeline');
        try {
            foreach ($this->topologicalSort($definitions) as $definition) {
                $this->profiler->start('computed.' . $definition->name, 'computed');
                try {
                    foreach ($definition->levels as $level) {
                        $formula = $definition->getFormulaForLevel($level);
                        if ($formula !== null) {
                            $summary = $summary->merge($this->evaluateAtLevel($repo, $definition, $level, $formula));
                        }
                    }
                } finally {
                    $this->profiler->stop('computed.' . $definition->name);
                }
            }
        } finally {
            $this->profiler->stop('computed');
        }

        return $summary;
    }

    private function evaluateAtLevel(
        MetricRepositoryInterface $repo,
        ComputedMetricDefinition $definition,
        SymbolLevel $level,
        string $formula,
    ): ComputedMetricEvaluationSummary {
        $symbols = $this->getSymbolsForLevel($repo, $level);
        if (!$definition->isBuiltinFormulaForLevel($level)) {
            $this->validateFormulaVariables($repo, $definition, $level, $formula, $symbols);
        }
        $summary = new ComputedMetricEvaluationSummary();
        foreach ($symbols as [$subject]) {
            $outcome = $this->subjectEvaluation->evaluate($definition, $level, $repo->getSubject($subject)->all());
            $summary = $summary->merge($this->publishOutcome($repo, $definition, $level, $subject, $outcome));
        }

        return $summary;
    }

    private function publishOutcome(
        MetricRepositoryInterface $repo,
        ComputedMetricDefinition $definition,
        SymbolLevel $level,
        MetricSubject $subject,
        ComputedMetricOutcome $outcome,
    ): ComputedMetricEvaluationSummary {
        if ($outcome->kind === ComputedMetricOutcome::VALUE) {
            $repo->addSubjectScalar($subject, $definition->name, (float) $outcome->value);
            return new ComputedMetricEvaluationSummary();
        }
        if ($outcome->kind === ComputedMetricOutcome::NOT_APPLICABLE) {
            return new ComputedMetricEvaluationSummary();
        }
        if ($outcome->kind === ComputedMetricOutcome::FAILURE || $definition->isBuiltinFormulaForLevel($level)) {
            throw $this->analysis->refuseFormula($definition, $level, ComputedMetricRefusalWording::runtimeFailure(
                $definition->name,
                $level->value,
                $subject->toCanonical(),
                $outcome->reason ?? 'Applicable builtin formula produced ' . $outcome->kind . '.',
            ));
        }
        return new ComputedMetricEvaluationSummary([new ComputedMetricValueAbsence(
            metricName: $definition->name,
            level: $level,
            missingKeysCount: $outcome->kind === ComputedMetricOutcome::MISSING_KEYS ? 1 : 0,
            noValueCount: $outcome->kind === ComputedMetricOutcome::NO_VALUE ? 1 : 0,
            missingKeys: $outcome->missingKeys,
            subjects: [$subject],
        )]);
    }

    /**
     * Refuses a formula that would read, unguarded, a metric no symbol at this
     * level carries.
     *
     * Presence is the union over the level's symbols, so a key some symbol
     * carries is left to the per-symbol skip. A read behind `??` counts only
     * where the fallback is reached, and a read only one ternary branch or the
     * right side of `and`/`or` makes is left to the per-symbol run, which
     * knows the branch. A reference to another computed metric is
     * judged by the same union: evaluation runs in dependency order, so it is
     * already published wherever it will be. Configuration has refused a bare
     * one read at a level it does not declare; what remains is a chain such as
     * `m["computed.x"] ?? m["size.y"]`, whose measured link only a run can
     * judge.
     *
     * @param list<array{\Qualimetrix\Core\Symbol\MetricSubject, ?RelativePath, ?int}> $symbols
     *
     * @throws \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal if the formula names a metric no symbol at this level carries
     */
    private function validateFormulaVariables(
        MetricRepositoryInterface $repo,
        ComputedMetricDefinition $definition,
        SymbolLevel $level,
        string $formula,
        array $symbols,
    ): void {
        // Collect union of all known metric keys across all symbols at this level
        $allKnownKeys = $this->collectKnownMetricKeys($repo, $symbols);

        // Skip validation when no metrics exist at this level — there is no data to validate against.
        // In production, aggregation populates metrics before evaluation; in unit tests, data may be sparse.
        if ($allKnownKeys === []) {
            return;
        }

        $unknownVars = $this->expression->missingKeysOf(
            $formula,
            static fn(string $key): bool => isset($allKnownKeys[$key]),
        );

        if ($unknownVars !== []) {
            // The same class of user mistake as a misspelled key, and refused
            // by the same class. A `RuntimeException` here surfaced as
            // "Internal error" with exit code 1 — the code that means
            // "warnings were found", so CI read a refusal as an ordinary result.
            ComputedMetricFormulaValidator::refuseMetricsAbsentAtLevel(
                $definition,
                $unknownVars,
                $level,
                $formula,
                $this->analysis->refuseFormula(...),
            );
        }
    }

    /**
     * Collects the union of all known metric keys across all symbols at a level.
     *
     * @param list<array{\Qualimetrix\Core\Symbol\MetricSubject, ?RelativePath, ?int}> $symbols
     *
     * @return array<string, true>
     */
    private function collectKnownMetricKeys(MetricRepositoryInterface $repo, array $symbols): array
    {
        $allKnownKeys = [];
        foreach ($symbols as [$subject]) {
            foreach (array_keys($repo->getSubject($subject)->all()) as $key) {
                $allKnownKeys[$key] = true;
            }
        }

        return $allKnownKeys;
    }

    /**
     * Sorts definitions in dependency order using Kahn's algorithm.
     *
     * @param list<ComputedMetricDefinition> $definitions
     *
     * @return list<ComputedMetricDefinition>
     */
    private function topologicalSort(array $definitions): array
    {
        $sorted = $this->dependencyGraphCalculator->sort($definitions);

        if ($sorted === null) {
            // Circular dependency — return original order and let config validation catch it
            $this->logger->warning('Circular dependency detected among computed metrics');

            return $definitions;
        }

        return $sorted;
    }

    /**
     * @return list<array{\Qualimetrix\Core\Symbol\MetricSubject, ?RelativePath, ?int}>
     */
    private function getSymbolsForLevel(MetricRepositoryInterface $repo, SymbolLevel $level): array
    {
        return match ($level) {
            SymbolLevel::Project => [[\Qualimetrix\Core\Symbol\MetricSubject::aggregate(SymbolPath::forProject()), null, null]],
            SymbolLevel::Namespace_ => array_map(
                static fn(string $ns) => [\Qualimetrix\Core\Symbol\MetricSubject::aggregate(SymbolPath::forNamespace($ns)), null, null],
                $repo->getNamespaces(),
            ),
            SymbolLevel::Class_ => array_map(
                static fn($info) => [$info->subject ?? throw new LogicException('Computed class metric requires exact declaration subject'), $info->file, $info->line],
                iterator_to_array($repo->allClassDeclarations(), false),
            ),
            SymbolLevel::Callable, SymbolLevel::File => [],
        };
    }
}
