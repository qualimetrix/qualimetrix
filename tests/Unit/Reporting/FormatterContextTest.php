<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(FormatterContext::class)]
final class FormatterContextTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $context = new FormatterContext();

        self::assertNull($context->namespace);
        self::assertNull($context->class);
    }

    public function testNamespaceFilter(): void
    {
        $context = new FormatterContext(namespace: 'App\Service');

        self::assertSame('App\Service', $context->namespace);
        self::assertNull($context->class);
    }

    public function testClassFilter(): void
    {
        $context = new FormatterContext(class: 'App\Service\UserService');

        self::assertNull($context->namespace);
        self::assertSame('App\Service\UserService', $context->class);
    }

    public function testRelativizePath(): void
    {
        $context = new FormatterContext(basePath: '/home/user/project');

        self::assertSame('src/Foo.php', $context->relativizePath('/home/user/project/src/Foo.php'));
        self::assertSame('/other/path/Foo.php', $context->relativizePath('/other/path/Foo.php'));
    }

    public function testRelativizePathEmptyBasePath(): void
    {
        $context = new FormatterContext(basePath: '');

        self::assertSame('/absolute/path.php', $context->relativizePath('/absolute/path.php'));
    }

    public function testDetailLimitNullMeansDetailDisabled(): void
    {
        $context = new FormatterContext();

        self::assertFalse($context->isDetailEnabled());
    }

    public function testDetailLimitZeroMeansUnlimited(): void
    {
        $context = new FormatterContext(detailLimit: 0);

        self::assertTrue($context->isDetailEnabled());
    }

    public function testDetailLimitPositiveMeansLimited(): void
    {
        $context = new FormatterContext(detailLimit: 200);

        self::assertTrue($context->isDetailEnabled());
    }

    public function testWithDetailTrueSetsLimitZero(): void
    {
        $context = new FormatterContext();
        $result = $context->withDetail(true);

        self::assertSame(0, $result->detailLimit);
    }

    public function testWithDetailFalseSetsLimitNull(): void
    {
        $context = new FormatterContext(detailLimit: 100);
        $result = $context->withDetail(false);

        self::assertNull($result->detailLimit);
    }

    public function testWithDetailLimitPreservesOtherFields(): void
    {
        $context = new FormatterContext(
            useColor: false,
            groupBy: \Qualimetrix\Reporting\GroupBy::File,
            basePath: '/project',
            scopedReporting: true,
            scopeFilePaths: ['/project/src/Foo.php'],
            namespace: 'App\\Service',
            class: 'App\\Service\\UserService',
            terminalWidth: 120,
            detailLimit: null,
            isGroupByExplicit: true,
        );

        $result = $context->withDetailLimit(50);

        self::assertSame(50, $result->detailLimit);
        self::assertFalse($result->useColor);
        self::assertSame(\Qualimetrix\Reporting\GroupBy::File, $result->groupBy);
        self::assertSame('/project', $result->basePath);
        self::assertTrue($result->scopedReporting);
        self::assertSame(['/project/src/Foo.php'], $result->scopeFilePaths);
        self::assertSame('App\\Service', $result->namespace);
        self::assertSame('App\\Service\\UserService', $result->class);
        self::assertSame(120, $result->terminalWidth);
        self::assertTrue($result->isGroupByExplicit);
    }
}
