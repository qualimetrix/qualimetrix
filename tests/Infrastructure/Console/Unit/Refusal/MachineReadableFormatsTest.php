<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Refusal\MachineReadableFormats;

/**
 * The closed set measured against `FormatterRegistry` on `1513bf67`
 * (`01-refusal-envelope.md` §2.1): six of the twelve registered formats carry
 * a JSON document on stdout, and only those six get the envelope.
 */
#[CoversClass(MachineReadableFormats::class)]
final class MachineReadableFormatsTest extends TestCase
{
    #[Test]
    #[TestWith(['json'])]
    #[TestWith(['sarif'])]
    #[TestWith(['gitlab'])]
    #[TestWith(['metrics'])]
    #[TestWith(['health'])]
    #[TestWith(['suppressed'])]
    public function itRecognisesEveryJsonDocumentFormat(string $format): void
    {
        self::assertTrue(MachineReadableFormats::carriesJson($format));
    }

    #[Test]
    #[TestWith(['text'])]
    #[TestWith(['text-verbose'])]
    #[TestWith(['summary'])]
    #[TestWith(['checkstyle'])]
    #[TestWith(['github'])]
    #[TestWith(['html'])]
    #[TestWith(['nonsense'])]
    public function itRejectsEveryNonJsonFormat(string $format): void
    {
        self::assertFalse(MachineReadableFormats::carriesJson($format));
    }

    #[Test]
    public function itRejectsAnUnknownFormat(): void
    {
        self::assertFalse(MachineReadableFormats::carriesJson(null));
    }
}
