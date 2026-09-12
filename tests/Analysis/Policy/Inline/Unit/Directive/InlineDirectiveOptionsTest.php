<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveOptions;

/**
 * The one configurable severity in this family, and the reason it is strict.
 *
 * A rule whose entire job is to report annotations that say one thing and do
 * another cannot itself accept `unused_directive_severity: Warning` and
 * quietly run at `info`. `Severity::tryFrom()` is case-sensitive, so the
 * previous `tryFrom($raw) ?? Info` did exactly that — and did it identically
 * for the typo `warnin`, which is the case that matters: the config file said
 * one thing, the run did another, and nothing anywhere said so.
 */
#[CoversClass(InlineDirectiveOptions::class)]
final class InlineDirectiveOptionsTest extends TestCase
{
    #[Test]
    public function itDefaultsToInfoWhenNoSeverityIsGiven(): void
    {
        self::assertSame(Severity::Info, InlineDirectiveOptions::fromArray([])->unusedDirectiveSeverity);
    }

    /**
     * A written `~` is left to the reader's own default, the same as an
     * unwritten key — it is not a narrower declaration refusing a legal value.
     */
    #[Test]
    public function itLeavesTheDefaultWhenSeverityIsWrittenNull(): void
    {
        self::assertSame(
            Severity::Info,
            InlineDirectiveOptions::fromArray(['unused_directive_severity' => null])->unusedDirectiveSeverity,
        );
    }

    #[Test]
    public function itAcceptsTheDocumentedSpelling(): void
    {
        self::assertSame(
            Severity::Warning,
            InlineDirectiveOptions::fromArray(['unused_directive_severity' => 'warning'])->unusedDirectiveSeverity,
        );
    }

    /**
     * The enum's own casing is an implementation detail, so a capitalised
     * value is honoured rather than refused.
     */
    #[Test]
    public function itAcceptsAValueWhoseCaseDiffersFromTheEnum(): void
    {
        self::assertSame(
            Severity::Warning,
            InlineDirectiveOptions::fromArray(['unused_directive_severity' => 'Warning'])->unusedDirectiveSeverity,
        );
    }

    #[Test]
    public function itRefusesAValueItCannotHonour(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('unknown value "warnin"');

        InlineDirectiveOptions::fromArray(['unused_directive_severity' => 'warnin']);
    }

    #[Test]
    public function itRefusesANonStringValue(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a string');

        InlineDirectiveOptions::fromArray(['unused_directive_severity' => 2]);
    }

    /**
     * The factory seeds constructor defaults when the user configured
     * nothing, so the already-resolved enum has to survive the same path.
     */
    #[Test]
    public function itAcceptsAnAlreadyResolvedSeverity(): void
    {
        self::assertSame(
            Severity::Error,
            InlineDirectiveOptions::fromArray(['unusedDirectiveSeverity' => Severity::Error])->unusedDirectiveSeverity,
        );
    }
}
