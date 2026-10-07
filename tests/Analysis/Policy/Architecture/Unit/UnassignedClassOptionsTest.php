<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassMode;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassOptions;
use Qualimetrix\Analysis\Policy\Architecture\UnassignedClass\UnassignedClassRule;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use ReflectionClass;

#[CoversClass(UnassignedClassOptions::class)]
final class UnassignedClassOptionsTest extends TestCase
{
    #[Test]
    public function itLeavesTheGateOffByDefault(): void
    {
        self::assertSame(UnassignedClassMode::Ignore, (new UnassignedClassOptions())->mode);
        self::assertSame(UnassignedClassMode::Ignore, UnassignedClassOptions::fromResolved(ResolvedOptionsFixture::values(UnassignedClassOptions::class, []))->mode);
        self::assertFalse(UnassignedClassOptions::fromResolved(ResolvedOptionsFixture::values(UnassignedClassOptions::class, []))->isEnabled());
    }

    #[Test]
    #[TestWith(['warn', UnassignedClassMode::Warn])]
    #[TestWith(['ERROR', UnassignedClassMode::Error])]
    #[TestWith(['ignore', UnassignedClassMode::Ignore])]
    public function itParsesTheMode(string $raw, UnassignedClassMode $expected): void
    {
        self::assertSame($expected, UnassignedClassOptions::fromResolved(ResolvedOptionsFixture::values(UnassignedClassOptions::class, ['mode' => $raw]))->mode);
    }

    /**
     * A written `~` is left to the reader's own default, the same as an
     * unwritten key — it is not a narrower declaration refusing a legal value.
     */
    #[Test]
    public function itLeavesTheDefaultWhenModeIsWrittenNull(): void
    {
        self::assertSame(UnassignedClassMode::Ignore, UnassignedClassOptions::fromResolved(ResolvedOptionsFixture::values(UnassignedClassOptions::class, ['mode' => null]))->mode);
    }

    #[Test]
    public function itRejectsAnUnknownMode(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('"rules.fixture.mode" in configuration file "/project/qmx.yaml" must be one of ignore, warn, error, got "fail".');

        UnassignedClassOptions::fromResolved(ResolvedOptionsFixture::values(UnassignedClassOptions::class, ['mode' => 'fail']));
    }

    #[Test]
    public function itOwnsOnlyTheModeWhileTheFrameworkOwnsEnablement(): void
    {
        self::assertFalse((new UnassignedClassOptions(UnassignedClassMode::Ignore))->isEnabled());
        self::assertTrue((new UnassignedClassOptions(UnassignedClassMode::Warn))->isEnabled());
        self::assertTrue((new UnassignedClassOptions(UnassignedClassMode::Error))->isEnabled());

        $constructor = (new ReflectionClass(UnassignedClassOptions::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(
            ['mode', 'enabled'],
            array_map(static fn($parameter): string => $parameter->getName(), $constructor->getParameters()),
        );
        self::assertSame(['mode'], UnassignedClassOptions::acceptedOptionKeys()->acceptedForDisplay());
        self::assertFalse(UnassignedClassOptions::acceptedOptionKeys()->knows('enabled'));
        self::assertContains('enabled', \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface::of(UnassignedClassOptions::class)->writableAt(null));
        self::assertTrue(\Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys::declared()->accepts('enabled'));
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
        self::assertSame(UnassignedClassMode::Ignore, UnassignedClassOptions::fromResolved(ResolvedOptionsFixture::values(UnassignedClassOptions::class, ['enabled' => false]))->mode);
        self::assertFalse(UnassignedClassOptions::fromResolved(ResolvedOptionsFixture::values(UnassignedClassOptions::class, ['enabled' => false, 'mode' => 'ignore']))->isEnabled());
    }

    /** @param array<string, mixed> $config */
    #[Test]
    #[TestWith([['enabled' => true], UnassignedClassMode::Ignore, null])]
    #[TestWith([['enabled' => false, 'mode' => 'error'], UnassignedClassMode::Error, Severity::Error])]
    #[TestWith([['enabled' => true, 'mode' => 'warn'], UnassignedClassMode::Warn, Severity::Warning])]
    public function itReadsTheTypedModeBesideTheFrameworkEnablementKey(array $config, UnassignedClassMode $mode, ?Severity $severity): void
    {
        $options = UnassignedClassOptions::fromResolved(ResolvedOptionsFixture::values(UnassignedClassOptions::class, $config));
        self::assertSame($mode, $options->mode);
        self::assertSame($severity, $options->getSeverity(1));
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
