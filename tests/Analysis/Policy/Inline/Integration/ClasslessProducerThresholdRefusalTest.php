<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveRejection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * The producers of the computed-metric family are addressable rule names that
 * cannot be retuned — two facts that only hold together.
 *
 * Splitting `computed.health` into seven producers moved `@qmx-threshold
 * health.cohesion` from one configuration diagnostic to another: from "names no
 * rule" (the name resolved to nothing) to "declares no @qmx-threshold support"
 * (it resolves, and still can never do anything). Both are refusals and both
 * end in the same rejected directive, so nothing about the *count* of findings
 * changes — which is exactly why the finding-equivalence gate cannot express
 * this move, and why it is pinned here instead.
 *
 * Answered against the production container, not a hand-built universe: the
 * claim is about what `ChannelDeclarationCompilerPass` actually assembles for a
 * producer that has no rule class, and a fixture universe filled in by this
 * test would agree with whatever the test wrote into it.
 */
#[CoversClass(DirectiveAddressability::class)]
#[CoversClass(DirectiveRejection::class)]
final class ClasslessProducerThresholdRefusalTest extends TestCase
{
    /**
     * Spelled out rather than read from `ComputedMetricChannelFamily`: this
     * case exists to fail on a tree where those names are not rule names, and a
     * constant imported from the capability that introduced them would make it
     * fail to load there instead of failing its assertion.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideFamilyProducers(): iterable
    {
        yield 'health.complexity' => ['health.complexity'];
        yield 'health.cohesion' => ['health.cohesion'];
        yield 'health.coupling' => ['health.coupling'];
        yield 'health.typing' => ['health.typing'];
        yield 'health.maintainability' => ['health.maintainability'];
        yield 'health.overall' => ['health.overall'];
        yield 'computed' => ['computed'];
    }

    /**
     * If this disappears, `@qmx-threshold health.cohesion` can silently go back
     * to being reported as a typo instead of as an unsupported retune.
     */
    #[Test]
    #[DataProvider('provideFamilyProducers')]
    public function aThresholdNamingAFamilyProducerIsRefusedAsUnretunableNotAsUnknown(string $producerRuleName): void
    {
        $rejection = self::addressability()->problemWithThreshold(self::threshold($producerRuleName));

        self::assertNotNull(
            $rejection,
            \sprintf('"%s" declares no @qmx-threshold support, so a threshold naming it must be refused.', $producerRuleName),
        );
        self::assertTrue(
            $rejection->ruleExistsButCannotBeRetuned,
            \sprintf(
                '"%s" is a registered producer, so the refusal must be the one that says a rule exists and'
                . ' cannot be retuned — the flag every consumer reads to tell the two refusals apart.',
                $producerRuleName,
            ),
        );
        self::assertStringContainsString(
            \sprintf('Rule "%s" declares no @qmx-threshold support', $producerRuleName),
            $rejection->message,
        );
        self::assertStringNotContainsString('names no rule', $rejection->message);
    }

    /**
     * The paired spelling moved with the bare one, and further: before the
     * split `health.cohesion:class` resolved to no rule at all, so the level
     * half was never reached. Now the rule half resolves, and the refusal has
     * to say that the rule cannot be retuned at any level — not offer the
     * level-blind advice, whose `--rule-opt` recommendation the CLI accepts,
     * warns about as an unknown option, and exits zero on.
     *
     * If this disappears, the product can go back to recommending that no-op.
     */
    #[Test]
    #[DataProvider('provideFamilyProducers')]
    public function aPairedThresholdOnAFamilyProducerRefusesWithoutAdvisingANoOp(string $producerRuleName): void
    {
        $rejection = self::addressability()->problemWithThreshold(self::threshold($producerRuleName . ':class'));

        self::assertNotNull($rejection);
        self::assertStringContainsString(
            \sprintf('rule "%s" at level "class", and that rule declares no @qmx-threshold support', $producerRuleName),
            $rejection->message,
        );
        self::assertStringNotContainsString('--rule-opt', $rejection->message);
        self::assertStringNotContainsString('is not a rule name', $rejection->message);
    }

    /**
     * If this disappears, the case above stops distinguishing anything: a
     * universe that answered "cannot be retuned" to every name would pass it.
     *
     * The unknown-name half of {@see DirectiveAddressabilityTest::itNamesTheBadHalfWhenTheThresholdRuleDoesNotExist()}
     * covers the `rule:level` pair branch; this is the bare-name branch, the
     * one `health.cohesion` itself took before the split.
     */
    #[Test]
    public function aThresholdNamingNothingIsStillRefusedAsUnknown(): void
    {
        $rejection = self::addressability()->problemWithThreshold(self::threshold('health.nosuchdimension'));

        self::assertNotNull($rejection);
        self::assertFalse($rejection->ruleExistsButCannotBeRetuned);
        self::assertStringContainsString('names no rule', $rejection->message);
    }

    private static function addressability(): DirectiveAddressability
    {
        $identity = (new ContainerFactory())->create()->get(ChannelIdentityInterface::class);
        \assert($identity instanceof ChannelIdentityInterface);

        return new DirectiveAddressability($identity);
    }

    private static function threshold(string $rulePattern): ThresholdOverride
    {
        return new ThresholdOverride(
            $rulePattern,
            10,
            null,
            1,
            MetricSubject::declaration(DeclarationPath::of(
                SymbolPath::forClass('App', 'Foo'),
                RelativePath::fromString('src/Foo.php'),
                DeclarationOrdinal::fromRank(0),
            )),
            ControlScope::Class_,
        );
    }
}
