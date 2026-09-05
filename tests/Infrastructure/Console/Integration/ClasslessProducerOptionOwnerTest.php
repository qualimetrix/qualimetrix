<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Every producer of the computed-metric family owns its own configuration —
 * its options, on both surfaces that name an option owner, and its channels.
 *
 * Splitting `computed.health` into seven producers moved this input the same
 * way it moved `@qmx-threshold`, and in the opposite direction: `rules: {
 * health.cohesion: … }` and `--rule-opt health.cohesion:…` went from refused
 * ("does not match any registered producer rule") to accepted, while the
 * retired `computed.health` went from accepted to refused. Neither move changes
 * the number of findings a run publishes, so the finding-equivalence gate
 * cannot express either one.
 *
 * The root `qmx.yaml` exercising one of these keys is not the proof: it shows
 * one name working in one configuration, not that the refusal turned into an
 * acceptance for the seven — and it could not fail at all for a name nobody
 * put in that file.
 *
 * The channel half moved with it, and narrowed: `channelsProducedBy()` used to
 * hand the one family producer the whole definition catalog, so an
 * `suppress_namespace_channels` key under it could name any dimension at all.
 * Now each producer answers with its own channel only.
 *
 * Answered against the production container's channel universe, because the
 * claim is about what `ChannelDeclarationCompilerPass` assembles for a producer
 * that has no rule class. A hand-built universe would agree with whatever this
 * test wrote into it.
 */
#[CoversClass(RuleInputValidator::class)]
final class ClasslessProducerOptionOwnerTest extends TestCase
{
    /**
     * Spelled out rather than read from `ComputedMetricChannelFamily`: this
     * case exists to fail on a tree where these are not producer names, and a
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
     * If this disappears, a `rules:` key naming a health dimension can go back
     * to being refused as an unknown owner, and the root `qmx.yaml` stops
     * loading.
     */
    #[Test]
    #[DataProvider('provideFamilyProducers')]
    public function aRulesSectionKeyMayNameAnyProducerOfTheFamily(string $producerRuleName): void
    {
        $snapshot = self::validator()->validate(
            self::inputWithoutRuleOpt(),
            self::configurationOwning($producerRuleName),
            new ResolvedComputedMetricDefinitions([]),
        );

        self::assertInstanceOf(ChannelUniverseInterface::class, $snapshot);
        self::assertTrue($snapshot->hasRule($producerRuleName));
    }

    /**
     * If this disappears, `--rule-opt health.typing:…` can go back to being
     * refused while the `rules:` key spelling of the same option still works —
     * the two surfaces read one list and must never answer differently.
     */
    #[Test]
    #[DataProvider('provideFamilyProducers')]
    public function aRuleOptOwnerMayNameAnyProducerOfTheFamily(string $producerRuleName): void
    {
        $snapshot = self::validator()->validate(
            self::inputWithRuleOpt($producerRuleName . ':enabled=false'),
            self::emptyConfiguration(),
            new ResolvedComputedMetricDefinitions([]),
        );

        self::assertInstanceOf(ChannelUniverseInterface::class, $snapshot);
        self::assertTrue($snapshot->hasRule($producerRuleName));
    }

    /**
     * Option ownership is addressability, not retunability — the two are
     * separately declared facts about the same name, and this is what stops the
     * cases above from being satisfied by "the seven became retunable".
     *
     * If this disappears, declaring `SUPPORTS_THRESHOLD_OVERRIDE = true` for
     * the family would look like a way to make its `rules:` keys work.
     */
    #[Test]
    #[DataProvider('provideFamilyProducers')]
    public function aFamilyProducerOwnsItsOptionsWhileDeclaringNoThresholdSupport(string $producerRuleName): void
    {
        self::assertFalse(
            self::universe()->supportsThresholdOverride($producerRuleName),
            \sprintf('"%s" is addressable as an option owner precisely without being retunable.', $producerRuleName),
        );
    }

    /**
     * If this disappears, the cases above stop distinguishing anything: a
     * validator that accepted every owner would pass them.
     *
     * Two names, because they fail for different reasons and the refusal must
     * not start telling them apart: `computed.health` is the retired producer
     * name — accepted before the split, and a name a consumer's `qmx.yaml` may
     * still carry — while `nosuch.producer` never named anything.
     */
    #[Test]
    public function anOwnerThatNamesNoProducerIsStillRefusedAsUnaddressable(): void
    {
        foreach (['computed.health', 'nosuch.producer'] as $owner) {
            $message = self::refusalFor($owner);

            self::assertStringContainsString(
                \sprintf('Rule option owner "%s" does not match any registered producer rule.', $owner),
                $message,
            );

            // The refusal is about the name resolving to no producer at all.
            // A producer that resolves and merely cannot be retuned is refused
            // nowhere on this surface — see the case above — so this wording
            // must never drift into the threshold vocabulary.
            self::assertStringNotContainsString('@qmx-threshold', $message);
            self::assertStringNotContainsString('declares no', $message);
        }
    }

    /**
     * If this disappears, the narrowing below can take the working case with
     * it: an owner whose channel list came back empty would satisfy the
     * refusal on its own, and the option would be unusable for everyone.
     */
    #[Test]
    public function anExclusionKeyMayNameTheChannelItsOwnerPublishes(): void
    {
        $definitions = self::twoNamespaceDimensions();
        $owner = self::producerOf('health.cohesion', $definitions);

        $snapshot = self::validator()->validate(
            self::inputWithoutRuleOpt(),
            self::configurationExcluding($owner, 'health.cohesion'),
            $definitions,
        );

        self::assertInstanceOf(ChannelUniverseInterface::class, $snapshot);
        self::assertContains(
            'health.cohesion',
            array_map(
                static fn(FindingChannel $channel): string => $channel->code,
                $snapshot->channelsProducedBy($owner),
            ),
        );
    }

    /**
     * If this disappears, a `suppress_namespace_channels` key can go back to
     * being accepted under a producer that does not publish it — the silent
     * no-op the split repaired: the key looked configured, resolved to a real
     * channel, and excluded nothing, because the exclusion is applied under the
     * producer's own name.
     *
     * The owner is asked of the product rather than spelled, so the case is
     * about the narrowing and nothing else: before the split it resolves to the
     * one producer that carried the whole catalog, and the foreign key was
     * accepted there.
     */
    #[Test]
    public function anExclusionKeyNamingAChannelItsOwnerDoesNotPublishIsRefused(): void
    {
        $definitions = self::twoNamespaceDimensions();
        $owner = self::producerOf('health.cohesion', $definitions);

        try {
            self::validator()->validate(
                self::inputWithoutRuleOpt(),
                self::configurationExcluding($owner, 'health.typing'),
                $definitions,
            );
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString(
                \sprintf('none of them produced by "%s"', $owner),
                $refusal->getMessage(),
                'The refusal must say that this producer does not publish that channel.',
            );

            // Not the owner refusal: the owner came from the product's own
            // reverse lookup, so a case passing because the owner was unknown
            // would be measuring the previous surface all over again.
            self::assertStringNotContainsString(
                'does not match any registered producer rule',
                $refusal->getMessage(),
            );

            return;
        }

        self::fail(\sprintf(
            'Producer "%s" does not publish "health.typing", so an exclusion key naming it can never exclude'
            . ' anything and must be refused.',
            $owner,
        ));
    }

    /**
     * The producer the run itself says publishes `$code`, read off the same
     * snapshot the validator will judge against.
     */
    private static function producerOf(string $code, ResolvedComputedMetricDefinitions $definitions): string
    {
        $producer = self::universe()->snapshot($definitions)->producerOf($code);

        self::assertIsString($producer, \sprintf('Channel "%s" names no producer in this run.', $code));

        return $producer;
    }

    /**
     * Two dimensions at namespace level, so the refused key names a channel
     * that really exists and really reports at the level the option applies
     * at — otherwise the refusal could be about either of those instead.
     */
    private static function twoNamespaceDimensions(): ResolvedComputedMetricDefinitions
    {
        return new ResolvedComputedMetricDefinitions([
            self::namespaceDimension('health.cohesion'),
            self::namespaceDimension('health.typing'),
        ]);
    }

    private static function namespaceDimension(string $name): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition(
            name: $name,
            formulas: ['namespace' => 'cohesion.lcom'],
            description: 'Fixture dimension',
            levels: [SymbolLevel::Namespace_],
            inverted: true,
        );
    }

    private static function configurationExcluding(string $owner, string $key): FindingConfiguration
    {
        return new FindingConfiguration(
            new RuleOptionsDocument([$owner => ['suppress_namespace_channels' => [$key => ['App\\Legacy']]]]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );
    }

    private static function refusalFor(string $owner): string
    {
        try {
            self::validator()->validate(
                self::inputWithoutRuleOpt(),
                self::configurationOwning($owner),
                new ResolvedComputedMetricDefinitions([]),
            );
        } catch (InvalidArgumentException $refusal) {
            return $refusal->getMessage();
        }

        self::fail(\sprintf('Rule option owner "%s" names no registered producer and must be refused.', $owner));
    }

    private static function configurationOwning(string $owner): FindingConfiguration
    {
        return new FindingConfiguration(
            new RuleOptionsDocument([$owner => ['enabled' => false]]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        );
    }

    private static function emptyConfiguration(): FindingConfiguration
    {
        return new FindingConfiguration(new RuleOptionsDocument([]), new FindingCliOverrides([]), new RuleSelection());
    }

    private static function inputWithoutRuleOpt(): InputInterface
    {
        return new ArrayInput([], new InputDefinition());
    }

    private static function inputWithRuleOpt(string $option): InputInterface
    {
        return new ArrayInput(['--rule-opt' => [$option]], new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]));
    }

    private static function validator(): RuleInputValidator
    {
        $container = (new ContainerFactory())->create();
        $universe = self::universe();

        $registry = $container->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        return new RuleInputValidator(
            $registry,
            new RuleSelector($universe),
            new FindingConfigurationResolver(),
            $universe,
        );
    }

    private static function universe(): ChannelUniverse
    {
        $universe = (new ContainerFactory())->create()->get(ChannelUniverse::class);
        \assert($universe instanceof ChannelUniverse);

        return $universe;
    }
}
