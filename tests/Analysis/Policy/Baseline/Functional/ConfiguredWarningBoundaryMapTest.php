<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Infrastructure\Console\Command\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;

/**
 * The whole map `baseline:explain` builds, pinned line by line against the real
 * container rather than against a hand-built registry of two rules.
 *
 * A per-rule test cannot see what this one is for: the reader this replaced
 * guessed a property name from a list of three, so its failure mode was a
 * channel silently missing from the map — `coupling.distance`, whose boundary
 * is spelled `maxDistanceWarning`, was absent for exactly that reason and no
 * rule-level test noticed. Only the full map, taken from every rule the
 * container registers, shows an absence.
 *
 * The numbers are the options classes' DEFAULTS. `ContainerFactory` does not
 * read `qmx.yaml` — which sets `max_distance_warning: 0.5` where this file
 * expects 0.3 — so a change here means a changed default or a changed reader,
 * never a changed repository configuration.
 */
#[CoversClass(BaselineConfiguredThresholds::class)]
final class ConfiguredWarningBoundaryMapTest extends TestCase
{
    /**
     * @var array<string, array<string, int|float>>
     */
    private const array EXPECTED = [
        'code-smell.constructor-overinjection' => ['callable' => 8],
        'code-smell.unreachable-code' => ['callable' => 1],
        'cohesion.lcom' => ['class' => 3],
        'complexity.cognitive' => ['callable' => 15, 'class' => 30],
        'complexity.cyclomatic' => ['callable' => 10, 'class' => 30],
        'complexity.npath' => ['callable' => 200, 'class' => 500],
        'complexity.wmc' => ['class' => 50],
        'coupling.cbo' => ['class' => 14, 'namespace' => 14],
        'coupling.class-rank' => ['class' => 0.02],
        'coupling.distance' => ['namespace' => 0.3],
        'coupling.instability' => ['class' => 0.8, 'namespace' => 0.8],
        'design.data-class' => ['class' => 33],
        'design.god-class' => ['class' => 3],
        'design.inheritance' => ['class' => 4],
        'design.noc' => ['class' => 10],
        'design.type-coverage.param' => ['class' => 80.0],
        'design.type-coverage.property' => ['class' => 80.0],
        'design.type-coverage.return' => ['class' => 80.0],
        'duplication.code-duplication' => ['project' => 5],
        'maintainability.index' => ['callable' => 40.0],
        'size.class-count' => ['namespace' => 15],
        'size.method-count' => ['class' => 20],
        'size.property-count' => ['class' => 15],
    ];

    #[Test]
    public function itResolvesTheBoundaryOfEveryChannelThatHasOne(): void
    {
        $actual = self::realMap();

        self::assertSame(self::EXPECTED, $actual);
    }

    /**
     * `coupling.distance` gets its own case because it is the row the naming
     * guess got wrong, and an assertion on the whole map would let a future
     * wholesale regeneration of EXPECTED quietly drop it again.
     */
    #[Test]
    public function itResolvesABoundaryWhoseMemberIsNotSpelledWarning(): void
    {
        $actual = self::realMap();

        self::assertArrayHasKey('coupling.distance', $actual);
        self::assertSame(['namespace' => 0.3], $actual['coupling.distance']);
    }

    /**
     * The reader must not go back to reading members by name. This guards the
     * token, not the behaviour — what guards the behaviour is
     * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\WarningBoundaryDeclarationTest},
     * and a green line here proves only that one way back is closed.
     */
    #[Test]
    public function itReadsNoOptionsMemberByName(): void
    {
        $source = file_get_contents(
            \dirname(__DIR__, 5) . '/src/Infrastructure/Console/Command/BaselineConfiguredThresholds.php',
        );

        self::assertIsString($source);
        self::assertStringNotContainsString('ReflectionObject', $source);
        self::assertStringNotContainsString('ReflectionProperty', $source);
        self::assertStringNotContainsString('hasProperty', $source);
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    private static function realMap(): array
    {
        $container = (new ContainerFactory())->create();

        $rules = $container->get(RuleRegistryInterface::class);
        \assert($rules instanceof RuleRegistryInterface);

        $options = $container->get(RuleOptionsFactory::class);
        \assert($options instanceof RuleOptionsFactory);

        $map = (new BaselineConfiguredThresholds($rules, $options))->resolve();
        ksort($map);

        foreach ($map as &$levels) {
            ksort($levels);
        }

        return $map;
    }
}
