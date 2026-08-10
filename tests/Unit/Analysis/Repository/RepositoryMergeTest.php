<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Repository;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Repository\RepositoryMerge;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(RepositoryMerge::class)]
final class RepositoryMergeTest extends TestCase
{
    #[Test]
    public function itMergesScalarOverridesAndStructuredPayloads(): void
    {
        $left = (new MetricBag())->with('loc', 10)->withEntry('dependency', ['name' => 'left']);
        $right = (new MetricBag())->with('loc', 20)->withEntry('dependency', ['name' => 'right']);

        $merged = RepositoryMerge::metrics($left, $right);

        self::assertSame(20, $merged->get('loc'));
        self::assertSame([
            ['name' => 'left'],
            ['name' => 'right'],
        ], $merged->entries('dependency'));
    }

    #[Test]
    public function itPromotesPlainSubjectMetadataInBothOrders(): void
    {
        $declaration = new DeclarationPath(
            SymbolPath::forMethod('App', 'Service', 'run'),
            RelativePath::fromString('src/Service.php'),
            100,
        );
        $subject = MetricSubject::declaration($declaration);
        $plain = new SymbolInfo($subject, $declaration->file, null);
        $typed = new SymbolInfo(
            $subject,
            $declaration->file,
            10,
            CallableKind::Method,
            new LogicalClassPath(SymbolPath::forClass('App', 'Service')),
        );

        foreach ([RepositoryMerge::subjectInfo($plain, $typed), RepositoryMerge::subjectInfo($typed, $plain)] as $info) {
            self::assertSame(CallableKind::Method, $info->callableKind);
            self::assertSame(10, $info->line);
        }
    }

    #[Test]
    public function itFailsFastForConflictingTypedMetadata(): void
    {
        $declaration = new DeclarationPath(
            SymbolPath::forMethod('App', 'Service', 'run'),
            RelativePath::fromString('src/Service.php'),
            100,
        );
        $subject = MetricSubject::declaration($declaration);
        $left = new SymbolInfo($subject, $declaration->file, 10, CallableKind::Method);
        $right = new SymbolInfo($subject, $declaration->file, 20, CallableKind::Method);

        $this->expectException(InvalidArgumentException::class);
        RepositoryMerge::subjectInfo($left, $right);
    }
}
