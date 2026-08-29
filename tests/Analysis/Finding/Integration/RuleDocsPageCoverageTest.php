<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDocsPageReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionClass;

/**
 * "Every rule declares its documentation page" is only an invariant if
 * something checks it on every registered rule, not just on the ones a human
 * remembered to look at — see `docs/internal/plans/sarif-channel-descriptions.md`,
 * package P1.
 *
 * The sweep has two halves, because a producer is no longer the same thing as
 * a rule class. The class half checks that each class declares its own
 * `DOCS_PAGE` and that the page carries that rule's anchor. The classless half
 * checks the same anchor for the producers of the computed-metric family that
 * have no class — without it the split would have quietly removed six names
 * from the guarantee while every assertion still passed.
 *
 * The number of rule **classes** is asserted, not assumed, so a rule quietly
 * dropped from registration cannot shrink the swept set and pass by vacuous
 * agreement. It is 45 and is a count of `RuleRegistryInterface::getClasses()`;
 * `bin/qmx rules` now reports 51, because it counts producers.
 */
#[CoversClass(RuleDocsPageReader::class)]
final class RuleDocsPageCoverageTest extends TestCase
{
    private const int REGISTERED_RULE_COUNT = 45;

    #[Test]
    public function everyRegisteredRuleDeclaresItsOwnDocsPage(): void
    {
        $ruleClasses = self::ruleClasses();
        self::assertCount(self::REGISTERED_RULE_COUNT, $ruleClasses);

        foreach ($ruleClasses as $ruleClass) {
            $reflection = new ReflectionClass($ruleClass);
            self::assertTrue(
                $reflection->hasConstant('DOCS_PAGE'),
                \sprintf('%s does not declare a DOCS_PAGE constant.', $ruleClass),
            );

            $constant = $reflection->getReflectionConstant('DOCS_PAGE');
            self::assertNotFalse($constant, $ruleClass);
            self::assertSame(
                $ruleClass,
                $constant->getDeclaringClass()->getName(),
                \sprintf(
                    '%s does not declare its own DOCS_PAGE — it only inherits one from %s.',
                    $ruleClass,
                    $constant->getDeclaringClass()->getName(),
                ),
            );
        }
    }

    /**
     * Correspondence, not existence: the declared page must carry *this
     * rule's* `Rule ID:` anchor, not merely exist on disk. This is the check
     * today's `CATEGORY_DOCS_MAP` (`duplication.* -> architecture/`) would
     * have failed, had anyone written it.
     */
    #[Test]
    public function everyDeclaredDocsPageCarriesTheRulesOwnAnchor(): void
    {
        foreach (self::ruleClasses() as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);
            $docsPage = RuleDocsPageReader::read($ruleClass);

            $path = self::docsRoot() . '/' . $docsPage;
            self::assertFileExists($path, \sprintf('%s (rule %s) names a page that does not exist.', $ruleClass, $ruleName));

            $contents = file_get_contents($path);
            self::assertIsString($contents, $path);

            self::assertStringContainsString(
                \sprintf('**Rule ID:** `%s`', $ruleName),
                $contents,
                \sprintf(
                    '%s declares DOCS_PAGE=%s, but that page does not carry the "Rule ID: %s" anchor.',
                    $ruleClass,
                    $docsPage,
                    $ruleName,
                ),
            );
        }
    }

    /**
     * The classless half: every producer of the computed-metric family carries
     * the same anchor on the page the family declares, so that a reader who
     * meets `health.coupling` in a report finds it named on the page a report
     * links to.
     */
    #[Test]
    public function everyClasslessProducerOfTheComputedFamilyCarriesItsAnchor(): void
    {
        $path = self::docsRoot() . '/' . ComputedMetricChannelFamily::DOCS_PAGE;
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents, $path);

        foreach (ComputedMetricChannelFamily::PRODUCER_RULE_NAMES as $producerRuleName) {
            self::assertStringContainsString(
                \sprintf('**Rule ID:** `%s`', $producerRuleName),
                $contents,
                \sprintf(
                    '%s does not carry the "Rule ID: %s" anchor, so that producer\'s name appears in reports'
                    . ' and nowhere in the documentation those reports point at.',
                    ComputedMetricChannelFamily::DOCS_PAGE,
                    $producerRuleName,
                ),
            );
        }
    }

    /**
     * The two rules whose page cannot be derived from their name's prefix,
     * asserted by name so they read as deliberate cases rather than
     * incidental passes of the loop above — see {@see RuleDocsPageReader}'s
     * docblock.
     */
    #[Test]
    public function theTwoNonPrefixPagesAreDeclaredExplicitly(): void
    {
        self::assertSame('rules/cohesion.md', RuleDocsPageReader::read(LcomRule::class));
        self::assertSame('reference/health-scores.md', RuleDocsPageReader::read(ComputedMetricRule::class));
    }

    private static function docsRoot(): string
    {
        return \dirname(__DIR__, 4) . '/website/docs';
    }

    /** @return list<class-string> */
    private static function ruleClasses(): array
    {
        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        return $registry->getClasses();
    }
}
