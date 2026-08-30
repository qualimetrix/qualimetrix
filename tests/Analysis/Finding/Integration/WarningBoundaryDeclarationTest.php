<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NoConfiguredBoundary;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionObject;
use ReflectionProperty;
use Throwable;

/**
 * What an options class SAYS about its warning boundary, against what its
 * `getSeverity()` DOES. Two witnesses, and the test is the place they meet.
 *
 * The declaration alone cannot be trusted — it is written by hand, and naming
 * the wrong member is exactly the mistake the removed property-name guess used
 * to make on the reader's side. `getSeverity()` alone cannot be trusted either:
 * two rules weigh several metrics inside `analyze()` and answer their options'
 * `getSeverity()` never. Each covers the other's blind spot.
 *
 * **A switch point is defined here, once.** `b` is a switch point of an object
 * when `getSeverity()` is not constant over `{b-1, b, b+1}` for an integer `b`,
 * or `{b-δ, b, b+δ, b+1}` for a fractional one. The `b+1` probe is not padding:
 * an exclusive comparison over `(int) $value` moves the switch to `b+1`, and a
 * neighbourhood of `b∓δ` cannot see it. The `severity_switch_points` column of
 * `enumeration-configured-boundary-population.tsv` is a coarse grid sweep meant
 * for reading and is deliberately not this definition.
 *
 * An exception from `getSeverity()` is its own outcome, never folded into
 * "no severity": a probe that throws must not read as a probe that declined.
 *
 * **What is deliberately not tested here.** That an overridden copy of an
 * ambiguous class stays ambiguous is carried by the return type —
 * `LongParameterListOptions::warningBoundary(): NoConfiguredBoundary`, an enum
 * with one case — so an assertion about it could not fail, and PHPStan says so.
 * A check that cannot go red is not a check.
 */
#[CoversClass(NoConfiguredBoundary::class)]
final class WarningBoundaryDeclarationTest extends TestCase
{
    private const float DELTA = 0.001;

    /**
     * Classes whose rule decides inside `analyze()` and leaves `getSeverity()`
     * a stub. Their declared member is real — `design.god-class` warns from
     * `matchedCount >= minCriteria`, `design.data-class` emits while
     * `woc <= wocThreshold` — but `getSeverity()` never reads it, so the
     * behaviour witness is blind to them and `withOverride()` is the only one
     * they have.
     *
     * The set is pinned rather than derived: a third such class must be a
     * visible event, because it arrives with a boundary no automatic witness
     * can confirm. The first draft of this test derived nothing and simply
     * believed both classes when they claimed to hold no boundary at all.
     *
     * @var list<string>
     */
    private const array DECIDES_INSIDE_THE_RULE = [
        'Qualimetrix\Analysis\Evidence\Design\DataClassOptions',
        'Qualimetrix\Analysis\Evidence\Design\GodClassOptions',
    ];

    #[Test]
    public function everyDeclaredBoundaryIsWhereSeverityStarts(): void
    {
        $checked = 0;

        foreach (self::reachableOptions() as $label => $options) {
            if (!$options instanceof ThresholdAwareOptionsInterface) {
                continue;
            }

            $boundary = $options->warningBoundary();

            if ($boundary instanceof NoConfiguredBoundary) {
                continue;
            }

            if (\in_array($options::class, self::DECIDES_INSIDE_THE_RULE, true)) {
                // getSeverity() is a stub here and would answer about a
                // comparison the rule does not make. Their witness is
                // anOverriddenCopyReportsTheOverriddenNumber().
                continue;
            }

            $delta = \is_int($boundary) ? 1 : self::DELTA;
            $below = self::severity($options, $boundary - $delta);
            $above = self::severity($options, $boundary + $delta);

            self::assertNotSame('threw', $below, $label . ': getSeverity() threw below the declared boundary');
            self::assertNotSame('threw', $above, $label . ': getSeverity() threw above the declared boundary');

            // Exactly one side silent: that is what makes the declared number
            // the WARNING boundary rather than the error one, where both sides
            // report. Asserting "not null AT the boundary" would be wrong —
            // the inverted rules compare strictly and are silent there.
            self::assertTrue(
                ($below === 'none') !== ($above === 'none'),
                \sprintf(
                    '%s declares %s as its warning boundary, but getSeverity() answers "%s" below it and "%s" above it.'
                    . ' Exactly one side must be silent; both silent means the number decides nothing, and neither'
                    . ' silent means it is the error boundary, not the warning one.',
                    $label,
                    (string) $boundary,
                    $below,
                    $above,
                ),
            );

            ++$checked;
        }

        self::assertGreaterThan(20, $checked, 'The walk found almost no declared boundaries — it is broken, not the rules.');
    }

    /**
     * The other half: options that stay outside `ThresholdAwareOptionsInterface`
     * must not be sitting on a boundary. Silence is how such a class reports to
     * the reader, so a configured threshold hiding behind that silence would be
     * printed as "not resolvable" forever.
     *
     * The scope is exactly those options, and that is narrower than it reads.
     * Inside the interface nothing denies a boundary any more: the only enum
     * case left is `MoreThanOneBoundary`, whose class does hold switching
     * members — two of them — and says so.
     */
    #[Test]
    public function nothingThatDeniesABoundaryIsSittingOnOne(): void
    {
        $checked = 0;

        foreach (self::reachableOptions() as $label => $options) {
            if ($options instanceof ThresholdAwareOptionsInterface) {
                continue;
            }

            ++$checked;

            foreach ((new ReflectionObject($options))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $value = $property->getValue($options);

                if (!\is_int($value) && !\is_float($value)) {
                    continue;
                }

                self::assertFalse(
                    self::isSwitchPoint($options, $value),
                    \sprintf(
                        '%s reports no warning boundary, yet its public member $%s = %s is where getSeverity() changes'
                        . ' its answer. Either the member is a boundary and the class must say so, or the comparison'
                        . ' around it is not the one the class believes it is.',
                        $label,
                        $property->getName(),
                        (string) $value,
                    ),
                );
            }
        }

        self::assertGreaterThan(10, $checked, 'The walk reached almost no plain options — it is broken, not the rules.');
    }

    /**
     * `MoreThanOneBoundary` claims a second decision exists. The witness is the
     * second deciding METHOD, not a second member with a promising name:
     * counting `…Warning` properties would restore, inside the test, the very
     * heuristic the reader dropped.
     */
    #[Test]
    public function moreThanOneBoundaryIsClaimedOnlyWithASecondDecision(): void
    {
        $claimed = 0;

        foreach (self::reachableOptions() as $label => $options) {
            if (!$options instanceof ThresholdAwareOptionsInterface) {
                continue;
            }

            if ($options->warningBoundary() !== NoConfiguredBoundary::MoreThanOneBoundary) {
                continue;
            }

            $deciders = array_filter(
                get_class_methods($options),
                static fn(string $method): bool => preg_match('/^get\w*Severity$/', $method) === 1,
            );

            self::assertGreaterThan(
                1,
                \count($deciders),
                $label . ' claims more than one boundary but exposes a single severity decision.',
            );

            ++$claimed;
        }

        self::assertSame(1, $claimed, 'Exactly one options class claims more than one boundary today.');
    }

    /**
     * The whole design rests on this: a hierarchical parent holds no number of
     * its own, its levels do. Keeping the obligation on
     * `ThresholdAwareOptionsInterface` is honest only while no parent joins it.
     *
     * This pin is green today and catches a future change, not a present bug —
     * which is exactly what it is for.
     */
    #[Test]
    public function noHierarchicalParentClaimsToHoldABoundary(): void
    {
        $parents = 0;

        foreach (self::rootOptions() as $label => $options) {
            if (!$options instanceof HierarchicalRuleOptionsInterface) {
                continue;
            }

            self::assertNotInstanceOf(
                ThresholdAwareOptionsInterface::class,
                $options,
                $label . ' is a hierarchical parent: its levels hold the numbers, and it has no honest answer to give.',
            );

            ++$parents;
        }

        self::assertGreaterThan(0, $parents, 'No hierarchical parent was reached — the walk is broken.');
    }

    /**
     * The method answers about the object it is asked, not about `qmx.yaml`:
     * on the copy `withOverride()` returns, the number is the overridden one.
     * `baseline:explain` gets the configured value because it asks an object no
     * override has touched, and that is a property of the caller.
     */
    #[Test]
    public function anOverriddenCopyReportsTheOverriddenNumber(): void
    {
        foreach (self::reachableOptions() as $label => $options) {
            if (!$options instanceof ThresholdAwareOptionsInterface) {
                continue;
            }

            $boundary = $options->warningBoundary();

            if ($boundary instanceof NoConfiguredBoundary) {
                continue;
            }

            // The two halves must differ. Sending one number twice makes this
            // pass for a class that names its ERROR member — which is not
            // hypothetical: `DataClassOptions` maps warning to `wocThreshold`
            // and error to `wmcThreshold`, and it is one of the two classes for
            // which this is the only witness there is.
            $raised = \is_int($boundary) ? $boundary + 1 : $boundary + 1.0;
            $lowered = \is_int($boundary) ? $boundary + 2 : $boundary + 2.0;
            $overridden = $options->withOverride($raised, $lowered)->warningBoundary();

            self::assertEquals($raised, $overridden, $label . ' ignored an override of its own warning boundary.');
        }
    }

    /**
     * The pinned set of classes whose boundary no behaviour witness can reach.
     * It is checked in both directions: a class that leaves the set must lose
     * its exemption, and a class that joins it must be noticed.
     */
    #[Test]
    public function onlyThePinnedClassesDecideInsideTheirRule(): void
    {
        $stubs = [];

        foreach (self::reachableOptions() as $options) {
            if (!$options instanceof ThresholdAwareOptionsInterface) {
                continue;
            }

            $boundary = $options->warningBoundary();

            if ($boundary instanceof NoConfiguredBoundary) {
                continue;
            }

            $delta = \is_int($boundary) ? 1 : self::DELTA;
            $below = self::severity($options, $boundary - $delta);
            $above = self::severity($options, $boundary + $delta);

            if (($below === 'none') !== ($above === 'none')) {
                continue;
            }

            $stubs[] = $options::class;
        }

        $expected = self::DECIDES_INSIDE_THE_RULE;
        sort($expected);
        sort($stubs);

        self::assertSame(
            $expected,
            array_values(array_unique($stubs)),
            'A class declares a boundary that getSeverity() does not witness, and is not pinned as deciding inside its rule.',
        );
    }

    /**
     * @return iterable<string, RuleOptionsInterface|LevelOptionsInterface>
     */
    private static function reachableOptions(): iterable
    {
        foreach (self::rootOptions() as $ruleName => $options) {
            if (!$options instanceof HierarchicalRuleOptionsInterface) {
                yield $ruleName => $options;

                continue;
            }

            foreach ($options->getSupportedLevels() as $level) {
                yield $ruleName . '@' . $level->value => $options->forLevel($level);
            }
        }
    }

    /**
     * @return iterable<string, RuleOptionsInterface>
     */
    private static function rootOptions(): iterable
    {
        $container = (new ContainerFactory())->create();

        $rules = $container->get(RuleRegistryInterface::class);
        \assert($rules instanceof RuleRegistryInterface);

        $factory = $container->get(RuleOptionsFactory::class);
        \assert($factory instanceof RuleOptionsFactory);

        foreach ($rules->getClasses() as $ruleClass) {
            if (ChannelDeclarationReader::read($ruleClass) === []) {
                continue;
            }

            $name = RuleNameReader::read($ruleClass);

            yield $name => $factory->create($name, $ruleClass::getOptionsClass());
        }
    }

    private static function isSwitchPoint(RuleOptionsInterface|LevelOptionsInterface $options, int|float $value): bool
    {
        $probes = \is_int($value)
            ? [$value - 1, $value, $value + 1]
            : [$value - self::DELTA, $value, $value + self::DELTA, $value + 1];

        $answers = [];

        foreach ($probes as $probe) {
            $answers[] = self::severity($options, $probe);
        }

        return \count(array_unique($answers)) > 1;
    }

    private static function severity(RuleOptionsInterface|LevelOptionsInterface $options, int|float $value): string
    {
        try {
            $severity = $options->getSeverity($value);
        } catch (Throwable) {
            return 'threw';
        }

        return $severity === null ? 'none' : $severity->value;
    }
}
