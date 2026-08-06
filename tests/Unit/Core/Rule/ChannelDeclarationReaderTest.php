<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Rule;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Rule\ChannelDeclarationReader;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Rules\CodeSmell\GotoRule;

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
        $key = (new ViolationChannel('fixture.rule', 'fixture.occurrence-channel'))->toKey();

        self::assertSame([$key], array_keys($declarations));
        self::assertEquals(ChannelDeclaration::occurrence(), $declarations[$key]);
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
        $key = (new ViolationChannel(GotoRule::NAME, GotoRule::NAME))->toKey();

        self::assertSame([$key], array_keys($declarations));
        self::assertEquals(ChannelDeclaration::occurrence(), $declarations[$key]);
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
    public function itRejectsAnEntryKeyedByAKeyWithAnEmptyViolationCodeHalf(): void
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage('violationCode must not be empty');

        ChannelDeclarationReader::read(FixtureRuleWithEmptyViolationCodeHalf::class);
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
        ChannelDeclarationReader::read('Qualimetrix\Rules\NoSuchRule');
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
        return [(new ViolationChannel('fixture.rule', 'fixture.occurrence-channel'))->toKey() => ChannelDeclaration::occurrence()];
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
        return ['' => ChannelDeclaration::occurrence()];
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
        return ['fixture.no-separator' => ChannelDeclaration::occurrence()];
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
        return ['#fixture.violation-code' => ChannelDeclaration::occurrence()];
    }
}

/**
 * @internal
 */
final class FixtureRuleWithEmptyViolationCodeHalf
{
    /**
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return ['fixture.rule-name#' => ChannelDeclaration::occurrence()];
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
