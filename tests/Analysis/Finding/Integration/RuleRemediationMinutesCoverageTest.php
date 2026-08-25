<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleRemediationMinutesReader;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * "Every rule declares its remediation estimate" is only an invariant if
 * something checks it on every registered rule, not just on the ones a human
 * remembered to look at — mirrors {@see RuleDocsPageCoverageTest}, and see
 * `docs/internal/plans/sarif-channel-descriptions.md`, package P5.
 *
 * The sweep has two halves, because a producer is no longer the same thing as
 * a rule class: the computed-metric family runs in one class and publishes
 * under seven producer names, six of which declare no constant anywhere.
 *
 * The number of rule **classes** is asserted, not assumed, so a rule quietly
 * dropped from registration cannot shrink the swept set and pass by vacuous
 * agreement. It is 45 and counts `RuleRegistryInterface::getClasses()`;
 * `bin/qmx rules` reports 51, because it counts producers.
 */
#[CoversClass(RuleRemediationMinutesReader::class)]
final class RuleRemediationMinutesCoverageTest extends TestCase
{
    private const int REGISTERED_RULE_COUNT = 45;

    #[Test]
    public function everyRegisteredRuleDeclaresItsOwnRemediationMinutes(): void
    {
        $ruleClasses = self::ruleClasses();
        self::assertCount(self::REGISTERED_RULE_COUNT, $ruleClasses);

        foreach ($ruleClasses as $ruleClass) {
            $reflection = new ReflectionClass($ruleClass);
            self::assertTrue(
                $reflection->hasConstant('REMEDIATION_MINUTES'),
                \sprintf('%s does not declare a REMEDIATION_MINUTES constant.', $ruleClass),
            );

            $constant = $reflection->getReflectionConstant('REMEDIATION_MINUTES');
            self::assertNotFalse($constant, $ruleClass);
            self::assertSame(
                $ruleClass,
                $constant->getDeclaringClass()->getName(),
                \sprintf(
                    '%s does not declare its own REMEDIATION_MINUTES — it only inherits one from %s.',
                    $ruleClass,
                    $constant->getDeclaringClass()->getName(),
                ),
            );
        }
    }

    /**
     * The cross-view page (`website/docs/reference/remediation-time.md`)
     * exists so a reader can compare estimates across rules without visiting
     * 45 files — the same trade the project already makes for default
     * thresholds. A page that drifts from the constants it summarises is
     * worse than no page, so every number on it is checked against the rule
     * that declares it, not merely trusted to have been transcribed right.
     *
     * The Russian translation (`remediation-time.ru.md`) carries the same
     * table and is swept the same way — a page nobody checks is exactly how
     * the English page could have drifted in the first place.
     */
    #[Test]
    public function everyRulesRemediationMinutesMatchTheReferencePage(): void
    {
        foreach (['reference/remediation-time.md', 'reference/remediation-time.ru.md'] as $relativePage) {
            $this->assertReferencePageMatchesDeclaredMinutes($relativePage);
        }
    }

    private function assertReferencePageMatchesDeclaredMinutes(string $relativePage): void
    {
        $page = self::readFile(self::docsRoot() . '/' . $relativePage);

        $missing = [];
        $mismatched = [];

        foreach (self::ruleClasses() as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);
            $declared = RuleRemediationMinutesReader::read($ruleClass);

            if (preg_match('/`' . preg_quote($ruleName, '/') . '`\s*\|\s*(\d+)\s*\|/', $page, $match) !== 1) {
                $missing[] = $ruleName;

                continue;
            }

            $documented = (int) $match[1];

            if ($documented !== $declared) {
                $mismatched[] = \sprintf('%s: page says %d, rule declares %d', $ruleName, $documented, $declared);
            }
        }

        self::assertSame([], $missing, \sprintf("Rules missing from website/docs/%s:\n%s", $relativePage, implode("\n", $missing)));
        self::assertSame([], $mismatched, \sprintf("Rules whose page value disagrees with the declared constant on %s:\n%s", $relativePage, implode("\n", $mismatched)));
    }

    /**
     * If this disappears, the six `health.*` rows on the reference page stop
     * being checked against anything: delete them and every test stays green,
     * which is exactly the drift this file exists to prevent for rule classes.
     */
    #[Test]
    public function everyProducerOfTheComputedFamilyIsOnTheReferencePage(): void
    {
        foreach (['reference/remediation-time.md', 'reference/remediation-time.ru.md'] as $relativePage) {
            $page = self::readFile(self::docsRoot() . '/' . $relativePage);

            foreach (ComputedMetricChannelFamily::PRODUCER_RULE_NAMES as $producerRuleName) {
                self::assertSame(
                    1,
                    preg_match('/`' . preg_quote($producerRuleName, '/') . '`\s*\|\s*(\d+)\s*\|/', $page, $match),
                    \sprintf('Producer "%s" is missing from website/docs/%s.', $producerRuleName, $relativePage),
                );
                self::assertSame(
                    ComputedMetricChannelFamily::REMEDIATION_MINUTES,
                    (int) $match[1],
                    \sprintf('website/docs/%s disagrees with the estimate the family declares.', $relativePage),
                );
            }
        }
    }

    /**
     * If this disappears, a producer can go missing from the map the compiler
     * pass injects and nothing says so until a run publishes a finding under
     * that name — {@see \Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry::getBaseMinutes()}
     * is fail-closed and ends the run there. Today only the finding-gate corpus
     * would notice, and only for as long as it keeps a case with computed
     * metrics enabled.
     */
    #[Test]
    public function everyAddressableProducerHasAnInjectedRemediationEstimate(): void
    {
        $container = (new ContainerFactory())->create();

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $minutesByRule = self::injectedMinutesMap($container);

        self::assertSame(
            [],
            array_values(array_diff($universe->ruleNames(), array_keys($minutesByRule))),
            'A producer the universe can address has no remediation estimate, so any finding it publishes'
            . ' would end the run instead of being costed.',
        );
    }

    /**
     * The `$minutesByRule` argument the compiler pass wrote onto the registry.
     *
     * Located by shape rather than by position: the container resolves named
     * arguments to indices while compiling, so an index would be a second fact
     * about the constructor kept by hand here. Exactly one argument is an
     * array, and that is asserted rather than assumed.
     *
     * @return array<string, int>
     */
    private static function injectedMinutesMap(ContainerBuilder $container): array
    {
        $arrays = array_values(array_filter(
            $container->getDefinition(RemediationTimeRegistry::class)->getArguments(),
            static fn(mixed $argument): bool => \is_array($argument),
        ));

        self::assertCount(1, $arrays, 'RemediationTimeRegistry must take exactly one array argument.');

        /** @var array<string, int> $map */
        $map = $arrays[0];
        self::assertNotEmpty($map);

        return $map;
    }

    private static function readFile(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, $path);

        return $content;
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
