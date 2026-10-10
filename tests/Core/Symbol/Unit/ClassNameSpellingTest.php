<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Symbol\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\ClassNameSpelling;

#[CoversClass(ClassNameSpelling::class)]
final class ClassNameSpellingTest extends TestCase
{
    #[Test]
    public function itFoldsOnlyAsciiCaseBytes(): void
    {
        self::assertSame("app\\domain\\order_\xC0", ClassNameSpelling::fold("App\\DOMAIN\\Order_\xC0"));
    }

    #[Test]
    public function itChoosesTheByteMinimumIndependentlyOfEncounterOrder(): void
    {
        self::assertSame('APP\\Order', ClassNameSpelling::canonical(['app\\order', 'APP\\Order', 'App\\ORDER']));
        self::assertSame('APP\\Order', ClassNameSpelling::canonical(['App\\ORDER', 'APP\\Order', 'app\\order']));
    }

    #[Test]
    public function itRefusesAnEmptySpellingGroup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one class-name spelling is required');

        ClassNameSpelling::canonical([]);
    }
}
