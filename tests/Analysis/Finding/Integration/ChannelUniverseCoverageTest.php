<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\Coupling\CboRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverageRule;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionClass;
use RuntimeException;

/**
 * The reverse lookup must be **total**: every declared channel names its
 * producer, no matter how that channel's identity was built.
 *
 * Totality is the whole point. A "did you mean" answer that works for
 * `coupling.cbo.class` and gives up on `architecture.coverage` is worse than
 * none — it teaches the reader that the diagnostic knows the vocabulary, then
 * silently omits the cases where the name is least guessable.
 *
 * **Two independent enumerations, compared against each other.** The channel
 * identity of a rule is built by at least six different mechanisms (equality
 * with the rule name, string concatenation, a ternary, a `match` over string
 * literals, sibling constants unrelated to the rule name, and declaration in
 * an abstract ancestor resolved by late static binding), and a hand-written
 * inventory of them has been wrong three times, each time by a mechanism the
 * previous inventory did not know about. So nothing here is written in prose:
 *
 * - **witness A** is the assembled universe from the production container,
 *   built by the compiler pass walking `qmx.rule`-tagged services;
 * - **witness B** is derived from the rule registry, reading each class's own
 *   metadata directly, and from the tracked fixture that the drift guard
 *   maintains independently of both.
 *
 * A disagreement between the two is the cheap detector; the absolute number is
 * only the tie-break. If the count ever changes, the correct response is to
 * find out which mechanism appeared or vanished — never to edit the number.
 */
#[CoversClass(ChannelIdentityInterface::class)]
final class ChannelUniverseCoverageTest extends TestCase
{
    /**
     * The count is asserted so that a silently shrinking enumeration cannot
     * pass by agreeing with itself on a smaller set. It is obtained, not
     * remembered: `grep -vc '^#\|^$' tests/Analysis/Finding/Fixtures/Channels/declared.txt`.
     */
    private const int DECLARED_CHANNEL_COUNT = 55;

    /**
     * Nine subclasses of `AbstractCodeSmellRule` plus three of
     * `AbstractSecurityPatternRule` declare their channel in the ancestor and
     * bind their own name through late static binding. A scan over
     * `*Rule.php` files does not see them, which is exactly why this count is
     * pinned separately from the total.
     */
    private const int CHANNELS_DECLARED_BY_AN_ANCESTOR = 12;

    #[Test]
    public function everyDeclaredChannelHasAProducer(): void
    {
        $universe = self::universe();
        $orphans = [];

        foreach (array_keys($universe->staticDeclarations()) as $key) {
            $channel = ViolationChannel::fromKey($key);

            if ($universe->producerOf($channel->violationCode) === null) {
                $orphans[] = $key;
            }
        }

        self::assertSame(
            [],
            $orphans,
            \sprintf(
                'Channel(s) whose producing rule the universe cannot name: %s. A channel with no producer cannot'
                . ' appear in any diagnostic that lists what a mistyped directive should have addressed.',
                implode(', ', $orphans),
            ),
        );
        self::assertCount(self::DECLARED_CHANNEL_COUNT, $universe->staticDeclarations());
    }

    #[Test]
    public function theAssembledUniverseAgreesWithTheRuleClassesReadDirectly(): void
    {
        $universe = self::universe();

        $witnessA = [];
        foreach (array_keys($universe->staticDeclarations()) as $key) {
            $violationCode = ViolationChannel::fromKey($key)->violationCode;
            $witnessA[$violationCode] = $universe->producerOf($violationCode);
        }
        ksort($witnessA);

        $witnessB = self::producersReadFromRuleClasses();
        ksort($witnessB);

        self::assertSame(
            $witnessB,
            $witnessA,
            'The compiler-pass-assembled universe and the rule classes read directly disagree about which rule'
            . ' produces which channel. One of the two enumerations missed a declaration mechanism.',
        );
        self::assertSame(self::DECLARED_CHANNEL_COUNT, \count($witnessA));
    }

    #[Test]
    public function theEnumerationAgreesWithTheTrackedFixture(): void
    {
        $universe = self::universe();

        $fromUniverse = array_map(
            static fn(string $key): string => ViolationChannel::fromKey($key)->violationCode,
            array_keys($universe->staticDeclarations()),
        );
        sort($fromUniverse);

        $fromFixture = self::violationCodesFromFixture();
        sort($fromFixture);

        self::assertSame($fromFixture, $fromUniverse);
        self::assertCount(self::DECLARED_CHANNEL_COUNT, $fromFixture);
    }

    /**
     * The mechanisms a file scan or a suffix rule cannot handle, named one by
     * one so a regression says which one broke rather than only that the
     * count moved.
     */
    #[Test]
    public function itNamesTheProducerForEveryIdentityMechanismThatDefeatsAScan(): void
    {
        $universe = self::universe();

        // Sibling constants: four channels carrying rule names no class owns.
        foreach ([
            LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME,
            LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
            LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
            LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
        ] as $siblingCode) {
            self::assertSame(LayerViolationRule::NAME, $universe->producerOf($siblingCode), $siblingCode);
        }

        // Ternary over the level, in CboRule.
        self::assertSame(CboRule::NAME, $universe->producerOf('coupling.cbo.class'));
        self::assertSame(CboRule::NAME, $universe->producerOf('coupling.cbo.namespace'));

        // match over string literals, in TypeCoverageRule.
        foreach (['param', 'property', 'return'] as $facet) {
            self::assertSame(
                TypeCoverageRule::NAME,
                $universe->producerOf('design.type-coverage.' . $facet),
                $facet,
            );
        }

        // Declaration in an abstract ancestor, resolved by late static binding.
        $inherited = self::channelsDeclaredByAnAncestor();
        self::assertCount(self::CHANNELS_DECLARED_BY_AN_ANCESTOR, $inherited);
        foreach ($inherited as $violationCode => $expectedProducer) {
            self::assertSame($expectedProducer, $universe->producerOf($violationCode), $violationCode);
        }
    }

    #[Test]
    public function itAnswersDidYouMeanByQueryingTheRegistryNotByStrippingASuffix(): void
    {
        $universe = self::universe();

        // The reported channel a user copies into @qmx-threshold. It names no
        // rule; the rule that owns its thresholds is the answer.
        self::assertFalse($universe->hasRule('coupling.cbo.class'));
        self::assertSame('coupling.cbo', $universe->producerOf('coupling.cbo.class'));
        self::assertTrue($universe->supportsThresholdOverride('coupling.cbo'));

        // Where suffix stripping would answer wrongly rather than merely fail:
        // "architecture.coverage" minus its last segment is "architecture",
        // which is not a rule at all, while the producer is a rule whose name
        // shares no suffix relation with the channel.
        self::assertFalse($universe->hasRule('architecture'));
        self::assertSame(
            LayerViolationRule::NAME,
            $universe->producerOf(LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME),
        );
    }

    #[Test]
    public function everyRegisteredRuleIsAddressableIncludingOnesThatDeclareNoChannel(): void
    {
        $universe = self::universe();

        foreach (self::ruleClasses() as $ruleClass) {
            self::assertTrue($universe->hasRule(RuleNameReader::read($ruleClass)), $ruleClass);
        }

        self::assertTrue(
            $universe->hasRule(ComputedMetricRule::NAME),
            'The computed-metric producer declares no static channel at all, and must still be an addressable rule.',
        );
        self::assertCount(\count(self::ruleClasses()), $universe->ruleNames());
    }

    /**
     * Declaration replaced inference, so the two must be pinned against each
     * other: a rule that declares `true` while nothing in its options can
     * carry an override would be a promise the runtime cannot keep, and a rule
     * that stays silent while its options are threshold-aware silently loses a
     * feature it used to have.
     *
     * A future divergence is not necessarily a bug — a rule may legitimately
     * decline to honour an override its options could technically carry — but
     * it has to be an explicit, reviewed decision, which is what failing here
     * forces.
     */
    #[Test]
    public function everyRulesDeclaredThresholdSupportMatchesWhatItsOptionsCanHonour(): void
    {
        $universe = self::universe();
        $mismatches = [];

        foreach (self::ruleClasses() as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);
            $declared = $universe->supportsThresholdOverride($ruleName);
            $mechanical = self::optionsCanCarryAnOverride($ruleClass::getOptionsClass());

            if ($declared !== $mechanical) {
                $mismatches[$ruleName] = \sprintf('declared=%s options=%s', var_export($declared, true), var_export($mechanical, true));
            }
        }

        self::assertSame([], $mismatches, 'Declared threshold-override support diverges from what the options class can carry.');
    }

    /**
     * Witness B for the producer map: read off the rule registry, class by
     * class, without going through the compiler pass or the universe.
     *
     * @return array<string, string> violation code => producing rule name
     */
    private static function producersReadFromRuleClasses(): array
    {
        $producers = [];

        foreach (self::ruleClasses() as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);

            foreach (array_keys(ChannelDeclarationReader::read($ruleClass)) as $key) {
                $producers[ViolationChannel::fromKey($key)->violationCode] = $ruleName;
            }
        }

        return $producers;
    }

    /**
     * @return array<string, string> violation code => producing rule name, for
     *                               channels whose declaration lives in an abstract ancestor
     */
    private static function channelsDeclaredByAnAncestor(): array
    {
        $inherited = [];

        foreach (self::ruleClasses() as $ruleClass) {
            $reflection = new ReflectionClass($ruleClass);

            if (!$reflection->hasMethod('channelDeclarations')) {
                continue;
            }

            if ($reflection->getMethod('channelDeclarations')->getDeclaringClass()->getName() === $ruleClass) {
                continue;
            }

            foreach (array_keys(ChannelDeclarationReader::read($ruleClass)) as $key) {
                $inherited[ViolationChannel::fromKey($key)->violationCode] = RuleNameReader::read($ruleClass);
            }
        }

        return $inherited;
    }

    /**
     * The inference this package replaced, kept only as the cross-check above.
     *
     * @param class-string $optionsClass
     */
    private static function optionsCanCarryAnOverride(string $optionsClass): bool
    {
        if (is_subclass_of($optionsClass, ThresholdAwareOptionsInterface::class)) {
            return true;
        }

        if (!is_subclass_of($optionsClass, HierarchicalRuleOptionsInterface::class)) {
            return false;
        }

        $options = $optionsClass::fromArray([]);
        \assert($options instanceof HierarchicalRuleOptionsInterface);

        foreach ($options->getSupportedLevels() as $level) {
            if ($options->forLevel($level) instanceof ThresholdAwareOptionsInterface) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function violationCodesFromFixture(): array
    {
        $path = \dirname(__DIR__) . '/Fixtures/Channels/declared.txt';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read fixture file %s.', $path));
        }

        $codes = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $key = strtok($line, ' ');
            \assert(\is_string($key));
            $codes[] = ViolationChannel::fromKey($key)->violationCode;
        }

        return $codes;
    }

    /** @return list<class-string> */
    private static function ruleClasses(): array
    {
        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        return $registry->getClasses();
    }

    private static function universe(): ChannelUniverseInterface
    {
        $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        return $universe;
    }
}
