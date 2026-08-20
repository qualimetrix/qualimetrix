<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(MetricSubject::class)]
final class MetricSubjectTest extends TestCase
{
    #[Test]
    public function itKeepsTheThreeIdentityVariantsSeparate(): void
    {
        $declaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Service', 'handle'), RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0));
        $logicalClass = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));
        $aggregate = SymbolPath::forNamespace('App');

        self::assertSame($declaration, MetricSubject::declaration($declaration)->declarationPath());
        self::assertSame($logicalClass, MetricSubject::logicalClass($logicalClass)->logicalClassPath());
        self::assertSame($aggregate, MetricSubject::aggregate($aggregate)->aggregatePath());
    }

    #[Test]
    public function itRejectsACallableAsAnAggregateSubject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MetricSubject::aggregate(SymbolPath::forMethod('App', 'Service', 'handle'));
    }
}
