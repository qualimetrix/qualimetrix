<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;

/**
 * Default implementation of RuleExecutionInterface.
 *
 * Executes the producers admitted by the resolved invocation snapshot and
 * publishes their findings through the same channel decisions.
 */
final class RuleExecution implements RuleExecutionInterface
{
    public function __construct(
        private readonly RuleMaterialization $materialization,
        private readonly ProfilerInterface $profiler,
        private readonly FindingPublication $publication,
    ) {}

    public function execute(AnalysisContext $context, ?string $restrictToProducer = null): RuleExecutionResult
    {
        $this->publication->prepareSnapshot();
        $produced = [];
        $published = [];
        $profiler = $this->profiler;

        $this->publication->begin();

        $enablement = $this->publication->readyEnablement();
        $population = $this->publication->populationSession($enablement, $restrictToProducer);
        $context = $context->withPopulationTrace($population);
        $removed = [];
        foreach ($this->materialization->activeRules($enablement, $restrictToProducer) as $rule) {
            $ruleName = $rule->getName();

            // One span, and the validators run inside it: a configuration
            // validator occupies its producer's slot in the execution order,
            // which is what keeps the position of its findings — and therefore
            // the order of every report that does not sort — exactly where it
            // was while the diagnostics lived in the rule class.
            $spanName = 'rule.' . $ruleName;
            $profiler->start($spanName, 'rules');
            $ruleFindings = $rule->analyze($context);
            $this->publication->prepareSnapshot();
            foreach ($this->materialization->validatorsFor($ruleName) as $validator) {
                $ruleFindings = [...$ruleFindings, ...$this->validate($validator, $context)];
            }
            $profiler->stop($spanName);

            $produced = [...$produced, ...$ruleFindings];
            $published = [...$published, ...$this->publication->published($ruleName, $ruleFindings, $enablement, $restrictToProducer, $removed)];
        }

        $levelActivity = $this->publication->levelActivity();

        $result = $this->publication->complete($produced, $published, $levelActivity, $removed, $enablement);
        return new RuleExecutionResult($result->produced, $result->published, $result->exclusions, $result->levelActivity, $result->selection, $population->freeze());
    }

    /**
     * Runs one validator and refuses a finding on a channel it did not
     * declare.
     *
     * This is where the discriminator is enforced rather than merely
     * described. "Configuration error" is now a consequence of the producing
     * type, so a validator emitting on a rule-declared channel would publish a
     * finding classified as ordinary debt from a producer that has no
     * thresholds, no baseline story and no suppression — the exact confusion
     * the split exists to remove. It is a wiring error, so it ends the run.
     *
     * @return list<Finding>
     */
    private function validate(ConfigurationValidatorInterface $validator, AnalysisContext $context): array
    {
        $declared = $validator::channelDeclarations();
        $findings = $validator->validate($context);

        foreach ($findings as $finding) {
            $key = $finding->channel()->code;

            if (isset($declared[$key])) {
                continue;
            }

            throw new LogicException(\sprintf(
                'Configuration validator %s emitted a finding on channel "%s", which it does not declare.'
                . ' A validator\'s findings are configuration errors by virtue of its type; emitting on a'
                . ' channel declared elsewhere would publish one under the wrong classification.',
                $validator::class,
                $key,
            ));
        }

        return $findings;
    }

    public function allRules(): array
    {
        return $this->materialization->allProducers($this->publication->enablement());
    }
}
