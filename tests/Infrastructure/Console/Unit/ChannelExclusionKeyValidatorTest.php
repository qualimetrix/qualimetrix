<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Infrastructure\Console\ChannelExclusionKeyValidator;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;

/**
 * The `exclude_namespace_channels` key: what it must address, and at what
 * level.
 *
 * The universe here holds two channels of two different producers, one
 * reporting at two levels and one at a single level. That is the shape a
 * single-witness question is needed for: with one channel, "some channel
 * reports at this level" and "this rule's channel reports at this level" cannot
 * be told apart.
 */
#[CoversClass(ChannelExclusionKeyValidator::class)]
final class ChannelExclusionKeyValidatorTest extends TestCase
{
    /**
     * The level witness is `coupling.cbo`, the production witness is
     * `coupling.class-rank`, and neither satisfies the other's condition: the
     * key was accepted while excluding nothing it could ever reach.
     */
    #[Test]
    public function itRefusesAWildcardKeyWhoseLevelAndProductionWitnessesAreDifferentChannels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'keyed by "coupling.*:namespace", addresses "coupling.class-rank",'
            . ' and it does not report at level "namespace"',
        );

        self::validator()->assertAddressesAProducedChannel('coupling.class-rank', 'coupling.*:namespace');
    }

    /** The same wildcard key under the rule that does report at that level stays accepted. */
    #[Test]
    public function itAcceptsAWildcardKeyWhoseOwnRuleReportsAtTheLevel(): void
    {
        self::validator()->assertAddressesAProducedChannel('coupling.cbo', 'coupling.*:namespace');

        $this->expectNotToPerformAssertions();
    }

    /**
     * The option is offered namespace aggregates only, so `:class` describes a
     * filter that can never fire — however truthfully the channel reports at
     * that level.
     */
    #[Test]
    public function itRefusesALevelTheOptionNeverAsksAbout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'names level "class", and this option removes namespace aggregates only: the one level it can name'
            . ' is "namespace". Drop the level, or write "coupling.cbo:namespace".',
        );

        self::validator()->assertAddressesAProducedChannel('coupling.cbo', 'coupling.cbo:class');
    }

    #[Test]
    public function itAcceptsTheNamespaceLevelAndTheLevelFreeSpelling(): void
    {
        self::validator()->assertAddressesAProducedChannel('coupling.cbo', 'coupling.cbo:namespace');
        self::validator()->assertAddressesAProducedChannel('coupling.cbo', 'coupling.cbo');

        $this->expectNotToPerformAssertions();
    }

    /**
     * A key carrying both a retired `#` pair and a level is answered about the
     * pair: the `#` half is not a name, so the level question could only call
     * it unparseable.
     */
    #[Test]
    public function itRefusesTheRetiredPairBeforeTheLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('spelling of a channel is gone');

        self::validator()->assertAddressesAProducedChannel('coupling.cbo', 'coupling.cbo#coupling.cbo:class');
    }

    /** A level-free key naming another rule's channel keeps naming this rule's channels back. */
    #[Test]
    public function itRefusesALevelFreeKeyNamingAnotherRulesChannel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('none of them produced by "coupling.class-rank"');

        self::validator()->assertAddressesAProducedChannel('coupling.class-rank', 'coupling.cbo');
    }

    private static function validator(): ChannelExclusionKeyValidator
    {
        return new ChannelExclusionKeyValidator(self::universe());
    }

    private static function universe(): ChannelUniverseInterface
    {
        return new ChannelUniverse(
            [
                'coupling.cbo' => ChannelDeclaration::magnitude(
                    WorseDirection::Higher,
                    SymbolLevel::Class_,
                    SymbolLevel::Namespace_,
                ),
                'coupling.class-rank' => ChannelDeclaration::magnitude(
                    WorseDirection::Higher,
                    SymbolLevel::Class_,
                ),
            ],
            [
                'coupling.cbo' => ['coupling.cbo'],
                'coupling.class-rank' => ['coupling.class-rank'],
            ],
            ['coupling.cbo' => true, 'coupling.class-rank' => true],
            new ResolvedComputedMetricDefinitions([]),
        );
    }
}
