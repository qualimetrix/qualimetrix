<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ViolationChannel::class)]
final class ViolationChannelTest extends TestCase
{
    #[Test]
    public function itRejectsAnEmptyRuleName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ruleName must not be empty');

        new ViolationChannel('', 'code');
    }

    #[Test]
    public function itRejectsAnEmptyViolationCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('violationCode must not be empty');

        new ViolationChannel('rule', '');
    }

    /**
     * A rule class may emit findings under a rule name it does not declare as
     * its own, so the channel is read off the emitted violation rather than
     * inferred from the class that produced it.
     */
    #[Test]
    public function itReadsTheChannelOffAnEmittedViolation(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/App.php'), 1),
            symbolPath: SymbolPath::forClass('App', 'App'),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App', 'App'), RelativePath::fromString('src/App.php'), DeclarationOrdinal::fromRank(0))),
            ruleName: 'architecture.unreachable-layer',
            violationCode: 'architecture.unreachable-layer',
            message: 'Layer never matched',
            severity: Severity::Error,
        );

        $channel = $violation->channel();

        self::assertSame('architecture.unreachable-layer', $channel->ruleName);
        self::assertSame('architecture.unreachable-layer', $channel->violationCode);
        self::assertTrue(
            $channel->equals(
                new ViolationChannel('architecture.unreachable-layer', 'architecture.unreachable-layer'),
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
        $method = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $class = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.class');

        self::assertFalse($method->equals($class));
        self::assertNotSame($method->toKey(), $class->toKey());
    }

    #[Test]
    public function itIsEqualToAnIdenticallyAddressedChannel(): void
    {
        $a = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $b = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');

        self::assertTrue($a->equals($b));
        self::assertSame($a->toKey(), $b->toKey());
    }

    #[Test]
    public function itRendersAsItsKey(): void
    {
        $channel = new ViolationChannel('rules.a', 'rules.a.callable');

        self::assertSame('rules.a#rules.a.callable', $channel->toKey());
        self::assertSame($channel->toKey(), (string) $channel);
    }

    #[Test]
    public function itParsesAKeyBackIntoTheSameChannel(): void
    {
        $original = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');

        $parsed = ViolationChannel::fromKey($original->toKey());

        self::assertTrue($original->equals($parsed));
    }

    #[Test]
    public function itParsesAKeyWhoseRuleNameDiffersFromItsViolationCode(): void
    {
        $channel = ViolationChannel::fromKey('architecture.layer-violation#architecture.coverage');

        self::assertSame('architecture.layer-violation', $channel->ruleName);
        self::assertSame('architecture.coverage', $channel->violationCode);
    }

    #[Test]
    public function itRejectsAKeyWithNoSeparator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid channel key');

        ViolationChannel::fromKey('no-separator-here');
    }

    #[Test]
    public function itRejectsAKeyWithAnEmptyRuleNameHalf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ruleName must not be empty');

        ViolationChannel::fromKey('#violation-code');
    }

    #[Test]
    public function itRejectsAKeyWithAnEmptyViolationCodeHalf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('violationCode must not be empty');

        ViolationChannel::fromKey('rule-name#');
    }
}
