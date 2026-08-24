<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\AbstractCodeSmellRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Evidence\Security\AbstractSecurityPatternRule;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelDeclarationReader;
use RuntimeException;

#[CoversClass(ChannelDeclarationReader::class)]
final class ChannelDeclarationReaderTest extends TestCase
{
    #[Test]
    public function itReadsTheDeclaredChannelsWithoutInstantiatingTheRule(): void
    {
        // FixtureRuleThatThrowsIfConstructed's constructor throws. Reading its
        // static channelDeclarations() succeeding at all — let alone without
        // an exception — is the proof that no instance was ever built.
        $declarations = ChannelDeclarationReader::read(FixtureRuleThatThrowsIfConstructed::class);
        $key = (new FindingChannel('fixture.rule', 'fixture.occurrence-channel'))->toKey();

        self::assertSame([$key], array_keys($declarations));
        self::assertEquals(ChannelDeclaration::occurrence(SymbolLevel::Class_), $declarations[$key]);
    }

    #[Test]
    public function itReturnsAnEmptyArrayForARuleThatDeclaresNothing(): void
    {
        // GotoRule's sibling code-smell rules (BooleanArgumentRule, EvalRule,
        // ...) deliberately have no channelDeclarations() method at all —
        // this is the "stays untouched" contract, exercised on a real rule.
        self::assertSame([], ChannelDeclarationReader::read(FixtureRuleWithNoDeclarations::class));
    }

    #[Test]
    public function itReadsARealRulesDeclarationsCorrectly(): void
    {
        $declarations = ChannelDeclarationReader::read(GotoRule::class);
        $key = (new FindingChannel(GotoRule::NAME, GotoRule::NAME))->toKey();

        self::assertSame([$key], array_keys($declarations));
        self::assertEquals(ChannelDeclaration::occurrence(SymbolLevel::Callable), $declarations[$key]);
    }

    #[Test]
    public function itRejectsANonStaticDeclarationMethod(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must be public and static');

        ChannelDeclarationReader::read(FixtureRuleWithNonStaticMethod::class);
    }

    #[Test]
    public function itRejectsAPrivateDeclarationMethod(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must be public and static');

        ChannelDeclarationReader::read(FixtureRuleWithPrivateMethod::class);
    }

    #[Test]
    public function itRejectsADeclarationMethodNotTypedToReturnArray(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must be declared to return array');

        ChannelDeclarationReader::read(FixtureRuleWithWronglyTypedMethod::class);
    }

    #[Test]
    public function itRejectsAnEntryKeyedByAnEmptyString(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must be keyed by a non-empty channel key string');

        ChannelDeclarationReader::read(FixtureRuleWithEmptyKey::class);
    }

    #[Test]
    public function itRejectsAnEntryKeyedByAStringWithNoSeparator(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('is not a valid channel key');

        ChannelDeclarationReader::read(FixtureRuleWithKeyMissingSeparator::class);
    }

    #[Test]
    public function itRejectsAnEntryKeyedByAKeyWithAnEmptyRuleNameHalf(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('ruleName must not be empty');

        ChannelDeclarationReader::read(FixtureRuleWithEmptyRuleNameHalf::class);
    }

    #[Test]
    public function itRejectsAnEntryKeyedByAKeyWithAnEmptyCodeHalf(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('code must not be empty');

        ChannelDeclarationReader::read(FixtureRuleWithEmptyCodeHalf::class);
    }

    #[Test]
    public function itRejectsAnEntryWhoseValueIsNotAChannelDeclaration(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('must be a');

        ChannelDeclarationReader::read(FixtureRuleWithWrongValueType::class);
    }

    #[Test]
    public function itRejectsAClassThatCannotBeLoaded(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('does not exist or cannot be autoloaded');

        /** @phpstan-ignore argument.type (deliberately passing an unloadable class) */
        ChannelDeclarationReader::read('Qualimetrix\Analysis\Evidence\CodeSmell\NoSuchRule');
    }

    /**
     * A rule's channelDeclarations() body can throw before this reader ever
     * gets a return value to inspect — e.g. building an invalid VO inline.
     * The reader's own docblock promises LogicException for every failure
     * mode; without wrapping the invoke() call, whatever the method throws
     * (here a plain RuntimeException) would escape unwrapped instead.
     */
    #[Test]
    public function itWrapsAnExceptionThrownFromWithinChannelDeclarationsInALogicException(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('threw while being invoked');

        ChannelDeclarationReader::read(FixtureRuleThatThrowsFromWithinChannelDeclarations::class);
    }

    /**
     * The real-world instance of the case above: AbstractCodeSmellRule and
     * AbstractSecurityPatternRule both declare `NAME = ''` so their shared
     * channelDeclarations() has something to bind via late static binding.
     * Reading the declaration directly off the abstract base — reflection
     * has no notion of "this class is never meant to be read directly" —
     * makes `static::NAME` resolve to `''`, and `channelDeclarations()`
     * builds `new FindingChannel('', '')`, which throws
     * InvalidArgumentException from inside the invoked method. This must
     * surface as the documented LogicException, not the VO's own exception
     * type.
     */
    #[Test]
    public function itThrowsALogicExceptionRatherThanTheVosOwnExceptionWhenReadingAbstractCodeSmellRuleDirectly(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('threw while being invoked');

        ChannelDeclarationReader::read(AbstractCodeSmellRule::class);
    }

    #[Test]
    public function itThrowsALogicExceptionRatherThanTheVosOwnExceptionWhenReadingAbstractSecurityPatternRuleDirectly(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('threw while being invoked');

        ChannelDeclarationReader::read(AbstractSecurityPatternRule::class);
    }
}

/**
 * @internal
 */
final class FixtureRuleThatThrowsIfConstructed
{
    public function __construct()
    {
        throw new LogicException('ChannelDeclarationReader must never instantiate a rule.');
    }

    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [(new FindingChannel('fixture.rule', 'fixture.occurrence-channel'))->toKey() => ChannelDeclaration::occurrence(SymbolLevel::Class_)];
    }
}

/**
 * @internal
 */
final class FixtureRuleWithNoDeclarations {}

/**
 * @internal
 */
final class FixtureRuleWithNonStaticMethod
{
    /**
     * @return array<string, ChannelDeclaration>
     */
    public function channelDeclarations(): array
    {
        return [];
    }
}

/**
 * @internal
 */
final class FixtureRuleWithPrivateMethod
{
    /**
     * @return array<string, ChannelDeclaration>
     *
     * @phpstan-ignore method.unused (only ever invoked via reflection, to prove the reader rejects it)
     */
    private static function channelDeclarations(): array
    {
        return [];
    }
}

/**
 * @internal
 */
final class FixtureRuleWithWronglyTypedMethod
{
    public static function channelDeclarations(): string
    {
        return 'not an array';
    }
}

/**
 * @internal
 */
final class FixtureRuleWithEmptyKey
{
    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return ['' => ChannelDeclaration::occurrence(SymbolLevel::Class_)];
    }
}

/**
 * @internal
 */
final class FixtureRuleWithKeyMissingSeparator
{
    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return ['fixture.no-separator' => ChannelDeclaration::occurrence(SymbolLevel::Class_)];
    }
}

/**
 * @internal
 */
final class FixtureRuleWithEmptyRuleNameHalf
{
    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return ['#fixture.violation-code' => ChannelDeclaration::occurrence(SymbolLevel::Class_)];
    }
}

/**
 * @internal
 */
final class FixtureRuleWithEmptyCodeHalf
{
    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return ['fixture.rule-name#' => ChannelDeclaration::occurrence(SymbolLevel::Class_)];
    }
}

/**
 * @internal
 */
final class FixtureRuleWithWrongValueType
{
    /**
     * @return array<string, mixed>
     */
    public static function channelDeclarations(): array
    {
        return ['fixture.rule#fixture.bad-value' => 'not a ChannelDeclaration'];
    }
}

/**
 * @internal
 */
final class FixtureRuleThatThrowsFromWithinChannelDeclarations
{
    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        throw new RuntimeException('boom — thrown from inside the declared method, not by the reader.');
    }
}
