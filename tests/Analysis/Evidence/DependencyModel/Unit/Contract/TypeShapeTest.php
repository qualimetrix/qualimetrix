<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\TypeShape;

#[CoversClass(TypeShape::class)]
final class TypeShapeTest extends TestCase
{
    #[Test]
    public function itPublishesTheCompleteShapeVocabulary(): void
    {
        self::assertSame(
            ['single', 'nullable', 'union', 'intersection', 'dnf'],
            array_column(TypeShape::cases(), 'value'),
        );
    }
}
