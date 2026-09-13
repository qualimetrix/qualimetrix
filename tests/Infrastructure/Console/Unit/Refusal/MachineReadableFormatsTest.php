<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Refusal\MachineReadableFormats;

/**
 * Six of the registered formats carry a JSON document on stdout, and only
 * those formats get a refusal envelope.
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
