<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Pipeline;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;

/**
 * One run of the pipeline, up to and including the first rule execution.
 *
 * The step both entry points of {@see AnalysisPipeline} share, held as a value
 * so neither has to re-derive the other's half. It carries the context on
 * purpose: an audit that re-executes rules must re-execute them against
 * exactly the world this run measured, and rebuilding an equivalent context
 * from the parts would be a second chance to build a different one.
 *
 * It carries no measurement repository of its own: `$context->metrics` is the
 * one this run measured, and a second field naming the same object would be a
 * second thing to keep in step — and, on this tree, one more class depending on
 * `MetricRepositoryInterface`, whose coupling budget is spent.
 *
 * Internal to Run and deliberately so: it does not cross an owner boundary,
 * and the report the audit hands out is a different shape with a different
 * audience.
 */
final readonly class PreparedRun
{
    public function __construct(
        public NamespaceTree $namespaceTree,
        public CollectionPhaseOutput $collection,
        public AnalysisContext $context,
        public RuleExecutionResult $ruleExecution,
        public AnalysisCoverage $coverage,
    ) {}
}
