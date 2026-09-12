<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassMode;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassRule;
use ReflectionClass;

#[CoversClass(UnassignedClassOptions::class)]
final class UnassignedClassOptionsTest extends TestCase
{
    #[Test]
    public function itLeavesTheGateOffByDefault(): void
    {
        self::assertSame(UnassignedClassMode::Ignore, (new UnassignedClassOptions())->mode);
        self::assertSame(UnassignedClassMode::Ignore, UnassignedClassOptions::fromArray([])->mode);
        self::assertFalse(UnassignedClassOptions::fromArray([])->isEnabled());
    }

    #[Test]
    #[TestWith(['warn', UnassignedClassMode::Warn])]
    #[TestWith(['ERROR', UnassignedClassMode::Error])]
    #[TestWith(['ignore', UnassignedClassMode::Ignore])]
    public function itParsesTheMode(string $raw, UnassignedClassMode $expected): void
    {
        self::assertSame($expected, UnassignedClassOptions::fromArray(['mode' => $raw])->mode);
    }

    /**
     * A written `~` is left to the reader's own default, the same as an
     * unwritten key — it is not a narrower declaration refusing a legal value.
     */
    #[Test]
    public function itLeavesTheDefaultWhenModeIsWrittenNull(): void
    {
        self::assertSame(UnassignedClassMode::Ignore, UnassignedClassOptions::fromArray(['mode' => null])->mode);
    }

    #[Test]
    public function itRejectsAnUnknownMode(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.unassigned-class');

        UnassignedClassOptions::fromArray(['mode' => 'fail']);
    }

    /**
     * The mode is the switch, not a setting beside one: a second `enabled`
     * would be a second answer to the same question, and the one that is off
     * by default would win over the one the author wrote.
     */
    #[Test]
    public function itDerivesEnablementFromTheModeAndAcceptsNoOtherKey(): void
    {
        self::assertFalse((new UnassignedClassOptions(UnassignedClassMode::Ignore))->isEnabled());
        self::assertTrue((new UnassignedClassOptions(UnassignedClassMode::Warn))->isEnabled());
        self::assertTrue((new UnassignedClassOptions(UnassignedClassMode::Error))->isEnabled());

        $constructor = (new ReflectionClass(UnassignedClassOptions::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(
            ['mode'],
            array_map(static fn($parameter): string => $parameter->getName(), $constructor->getParameters()),
        );
        // The declaration is now the whole of what the factory compares
        // against, so a second switch could only get in by being written into
        // it. `mode` is the only key anyone may write here; `enabled` is in
        // the answered-by-the-class half, which is where the refusal below
        // lives and is deliberately not a list anyone may write from.
        self::assertSame(['mode'], UnassignedClassOptions::acceptedOptionKeys()->acceptedForDisplay());
        self::assertTrue(UnassignedClassOptions::acceptedOptionKeys()->knows('enabled'));
        self::assertFalse(UnassignedClassOptions::acceptedOptionKeys()->accepts('enabled'));
    }

    /**
     * `rules: { architecture.unassigned-class: false }` is the idiom every rule
     * answers to, and it arrives here normalised as `enabled: false`. Refusing
     * it would be a hard error for asking that an off-by-default rule stay off.
     */
    #[Test]
    public function itAcceptsAnEnabledFalseThatAgreesWithTheDefaultMode(): void
    {
        self::assertSame(UnassignedClassMode::Ignore, UnassignedClassOptions::fromArray(['enabled' => false])->mode);
        self::assertFalse(UnassignedClassOptions::fromArray(['enabled' => false, 'mode' => 'ignore'])->isEnabled());
    }

    /**
     * The two spellings that would lie: one promises to turn the rule on
     * without doing so, the other contradicts the switch written beside it.
     */
    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[TestWith([['enabled' => true], 'promise to turn the rule on'])]
    #[TestWith([['enabled' => false, 'mode' => 'error'], 'contradict "mode: error"'])]
    #[TestWith([['enabled' => true, 'mode' => 'warn'], 'promise to turn the rule on'])]
    public function itRefusesAnEnabledThatWouldLie(array $config, string $expected): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage($expected);

        UnassignedClassOptions::fromArray($config);
    }

    #[Test]
    public function itReadsTheModeAsTheReportedSeverity(): void
    {
        self::assertNull((new UnassignedClassOptions(UnassignedClassMode::Ignore))->getSeverity(1));
        self::assertSame(Severity::Warning, (new UnassignedClassOptions(UnassignedClassMode::Warn))->getSeverity(1));
        self::assertSame(Severity::Error, (new UnassignedClassOptions(UnassignedClassMode::Error))->getSeverity(1));
    }

    #[Test]
    public function itIsTheOptionsClassOfItsOwnRule(): void
    {
        self::assertSame(UnassignedClassOptions::class, UnassignedClassRule::getOptionsClass());
    }
}
