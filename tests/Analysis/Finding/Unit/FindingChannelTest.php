<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
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
    public function itRejectsAnEmptyRuleName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ruleName must not be empty');

        new FindingChannel('', 'code');
    }

    #[Test]
    public function itRejectsAnEmptyCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('code must not be empty');

        new FindingChannel('rule', '');
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

        self::assertSame('architecture.unreachable-layer', $channel->ruleName);
        self::assertSame('architecture.unreachable-layer', $channel->code);
        self::assertTrue(
            $channel->equals(
                new FindingChannel('architecture.unreachable-layer', 'architecture.unreachable-layer'),
            ),
        );
    }

    /**
     * One rule name can carry several codes, so the code is part of the
     * address, not a detail hanging off it.
     */
    #[Test]
    public function itDistinguishesTwoCodesUnderOneRuleName(): void
    {
        $method = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $class = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.class');

        self::assertFalse($method->equals($class));
        self::assertNotSame($method->toKey(), $class->toKey());
    }

    #[Test]
    public function itIsEqualToAnIdenticallyAddressedChannel(): void
    {
        $a = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $b = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');

        self::assertTrue($a->equals($b));
        self::assertSame($a->toKey(), $b->toKey());
    }

    #[Test]
    public function itRendersAsItsKey(): void
    {
        $channel = new FindingChannel('rules.a', 'rules.a.callable');

        self::assertSame('rules.a#rules.a.callable', $channel->toKey());
        self::assertSame($channel->toKey(), (string) $channel);
    }

    #[Test]
    public function itParsesAKeyBackIntoTheSameChannel(): void
    {
        $original = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');

        $parsed = FindingChannel::fromKey($original->toKey());

        self::assertTrue($original->equals($parsed));
    }

    #[Test]
    public function itParsesAKeyWhoseRuleNameDiffersFromItsCode(): void
    {
        $channel = FindingChannel::fromKey('architecture.layer-violation#architecture.coverage');

        self::assertSame('architecture.layer-violation', $channel->ruleName);
        self::assertSame('architecture.coverage', $channel->code);
    }

    #[Test]
    public function itRejectsAKeyWithNoSeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid channel key');

        FindingChannel::fromKey('no-separator-here');
    }

    #[Test]
    public function itRejectsAKeyWithAnEmptyRuleNameHalf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ruleName must not be empty');

        FindingChannel::fromKey('#violation-code');
    }

    #[Test]
    public function itRejectsAKeyWithAnEmptyCodeHalf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('code must not be empty');

        FindingChannel::fromKey('rule-name#');
    }
}
