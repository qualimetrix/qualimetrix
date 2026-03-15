<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting;

use AiMessDetector\Reporting\FormatterContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
}
