<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(FindingChannel::class)]
final class FindingChannelTest extends TestCase
{
    #[Test]
    public function itRejectsAnEmptyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('code must not be empty');

        new FindingChannel('');
    }

    /**
     * A rule class may emit findings under a rule name it does not declare as
     * its own, so the channel is read off the emitted finding rather than
     * inferred from the class that produced it.
     */
    #[Test]
    public function itReadsTheChannelOffAnEmittedFinding(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/App.php'), 1),
            symbolPath: SymbolPath::forClass('App', 'App'),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'App'), RelativePath::fromString('src/App.php'), DeclarationOrdinal::fromRank(0))),
            ruleName: 'architecture.unreachable-layer',
            code: 'architecture.unreachable-layer',
            message: 'Layer never matched',
            severity: Severity::Error,
        );

        $channel = $finding->channel();

        self::assertSame('architecture.unreachable-layer', $channel->code);
        self::assertTrue(
            $channel->equals(
                new FindingChannel('architecture.unreachable-layer'),
            ),
        );
    }

    /**
     * One rule can produce several channels, so the whole name is the address
     * and the rule that produces it is not part of it.
     */
    #[Test]
    public function itDistinguishesTwoChannelsOfOneRule(): void
    {
        $method = new FindingChannel('complexity.cyclomatic.callable');
        $class = new FindingChannel('complexity.cyclomatic.class');

        self::assertFalse($method->equals($class));
        self::assertNotSame($method->code, $class->code);
    }

    #[Test]
    public function itIsEqualToAnIdenticallyAddressedChannel(): void
    {
        $a = new FindingChannel('complexity.cyclomatic.callable');
        $b = new FindingChannel('complexity.cyclomatic.callable');

        self::assertTrue($a->equals($b));
        self::assertSame($a->code, $b->code);
    }

    #[Test]
    public function itRendersAsItsName(): void
    {
        $channel = new FindingChannel('rules.a.callable');

        self::assertSame('rules.a.callable', $channel->code);
        self::assertSame($channel->code, (string) $channel);
    }

    /**
     * A level is a coordinate beside the name, so the separator that writes
     * the pair down can never be part of a code: `coupling.cbo:class` would
     * otherwise be a name that decomposes two ways, and a producer could
     * declare one.
     */
    #[Test]
    public function itRefusesACodeCarryingTheLevelSeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('coupling.cbo:class');

        new FindingChannel('coupling.cbo' . ChannelLevelSelector::LEVEL_SEPARATOR . SymbolLevel::Class_->value);
    }

    /**
     * The retired spelling has to be refused rather than accepted as a name
     * nothing can carry: a channel whose name contains the separator would
     * silently match no finding, which is exactly the outcome the collapse of
     * the pair exists to remove.
     */
    #[Test]
    public function itRefusesTheRetiredChannelPairSpelling(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Write "architecture.coverage"');

        new FindingChannel('architecture.layer-violation#architecture.coverage');
    }

    #[Test]
    public function itRecognisesTheRetiredChannelPairSpelling(): void
    {
        self::assertTrue(FindingChannel::isRetiredPairSpelling('a#b'));
        self::assertFalse(FindingChannel::isRetiredPairSpelling('a.b'));
    }

    /** The advice names the half that is now the whole name, not the whole text. */
    #[Test]
    public function itAdvisesTheNameToWriteInsteadOfTheRetiredPair(): void
    {
        self::assertStringContainsString(
            'Write "complexity.cyclomatic.callable"',
            FindingChannel::retiredPairAdvice('complexity.cyclomatic#complexity.cyclomatic.callable'),
        );
    }
}
