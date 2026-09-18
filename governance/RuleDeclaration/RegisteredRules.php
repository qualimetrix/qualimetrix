<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleDeclaration;

use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;

/**
 * The population both declaration sweeps in this group walk, read once.
 *
 * {@see RuleDocsPageCoverageTest} and {@see RuleRemediationMinutesCoverageTest}
 * ask different questions — does every registered rule name its own docs page,
 * does every registered rule name its own remediation estimate — of the same
 * set, and each carried its own byte-identical copy of how to obtain that set
 * and of how large it is. Two copies of a population is two things to keep
 * true; the questions stay where they are, the population moves here.
 *
 * The count is a promise about the product ("this is how many rule classes the
 * container registers"), so it stays a literal and stays asserted: a rule
 * quietly dropped from registration would otherwise shrink the swept set and
 * pass by vacuous agreement.
 */
final class RegisteredRules
{
    /**
     * A count of `RuleRegistryInterface::getClasses()`. `bin/qmx rules`
     * reports 54, because it counts producers rather than classes.
     */
    public const int COUNT = 48;

    /** @return list<class-string> */
    public static function classes(): array
    {
        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        return $registry->getClasses();
    }

    public static function docsRoot(): string
    {
        return \dirname(__DIR__, 2) . '/website/docs';
    }
}
