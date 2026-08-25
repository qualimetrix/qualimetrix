<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

/**
 * A finding producer that no rule class declares.
 *
 * The registry of producers used to be the registry of rule classes, and the
 * two words were interchangeable. They stopped being: the six built-in health
 * dimensions each publish under their own name while sharing one class, and two
 * classes cannot carry one `NAME`. So the producers a capability owns without a
 * class of their own are handed to {@see RuleExecutionInterface} as these
 * values, alongside the rule instances, and every answer about "every
 * registered rule" is the union.
 *
 * Deliberately not a rule: it has no `analyze()`, and nothing here can be run.
 * A producer without a class runs inside the class that shares its family.
 */
final readonly class ProducerDeclaration
{
    /**
     * @param class-string<RuleOptionsInterface> $optionsClass the class whose configuration this
     *                                                         producer's own `rules:` key is read into
     * @param string $hostRuleName the rule whose instance actually runs this producer's analysis.
     *                             Selection has to know it: `--only-rule health.typing` names a producer
     *                             with nothing to execute, and the host would otherwise be filtered out
     *                             before it could publish the very findings that were asked for
     * @param array<string, string> $aliases CLI alias => canonical option name; a producer with no
     *                                       class has no attribute to carry one, so the empty answer is
     *                                       declared rather than defaulted
     */
    public function __construct(
        public string $name,
        public string $hostRuleName,
        public string $optionsClass,
        public RuleCategory $category,
        public string $description,
        public array $aliases = [],
    ) {}
}
