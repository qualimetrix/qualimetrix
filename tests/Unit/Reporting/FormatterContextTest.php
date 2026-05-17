<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(FormatterContext::class)]
final class FormatterContextTest extends TestCase
{
    #[Test]
    public function itHasNullDefaultFilterValues(): void
    {
        $context = new FormatterContext();

        self::assertNull($context->namespace);
        self::assertNull($context->class);
    }

    #[Test]
    public function itStoresNamespaceFilter(): void
    {
        $context = new FormatterContext(namespace: 'App\Service');

        self::assertSame('App\Service', $context->namespace);
        self::assertNull($context->class);
    }

    #[Test]
    public function itStoresClassFilter(): void
    {
        $context = new FormatterContext(class: 'App\Service\UserService');

        self::assertNull($context->namespace);
        self::assertSame('App\Service\UserService', $context->class);
    }

    #[Test]
    public function itRelativizesPathAgainstBasePath(): void
    {
        $context = new FormatterContext(basePath: '/home/user/project');

        self::assertSame('src/Foo.php', $context->relativizePath('/home/user/project/src/Foo.php'));
        self::assertSame('/other/path/Foo.php', $context->relativizePath('/other/path/Foo.php'));
    }

    #[Test]
    public function itReturnsAbsolutePathWhenBasePathEmpty(): void
    {
        $context = new FormatterContext(basePath: '');

        self::assertSame('/absolute/path.php', $context->relativizePath('/absolute/path.php'));
    }

    #[Test]
    public function itDisablesDetailWhenLimitIsNull(): void
    {
        $context = new FormatterContext();

        self::assertFalse($context->isDetailEnabled());
    }

    #[Test]
    public function itEnablesDetailWhenLimitIsZero(): void
    {
        $context = new FormatterContext(detailLimit: 0);

        self::assertTrue($context->isDetailEnabled());
    }

    #[Test]
    public function itEnablesDetailWhenLimitIsPositive(): void
    {
        $context = new FormatterContext(detailLimit: 200);

        self::assertTrue($context->isDetailEnabled());
    }

    #[Test]
    public function itSetsLimitToZeroWhenDetailIsTrue(): void
    {
        $context = new FormatterContext();
        $result = $context->withDetail(true);

        self::assertSame(0, $result->detailLimit);
    }

    #[Test]
    public function itSetsLimitToNullWhenDetailIsFalse(): void
    {
        $context = new FormatterContext(detailLimit: 100);
        $result = $context->withDetail(false);

        self::assertNull($result->detailLimit);
    }

    #[Test]
    public function itPreservesOtherFieldsWhenChangingDetailLimit(): void
    {
        $context = new FormatterContext(
            useColor: false,
            groupBy: \Qualimetrix\Reporting\GroupBy::File,
            basePath: '/project',
            scopedReporting: true,
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
        self::assertSame('App\\Service', $result->namespace);
        self::assertSame('App\\Service\\UserService', $result->class);
        self::assertSame(120, $result->terminalWidth);
        self::assertTrue($result->isGroupByExplicit);
    }
}
