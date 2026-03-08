<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\CodeSmell;

use AiMessDetector\Metrics\CodeSmell\CodeSmellLocation;
use AiMessDetector\Metrics\CodeSmell\CodeSmellVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeSmellVisitor::class)]
#[CoversClass(CodeSmellLocation::class)]
final class CodeSmellVisitorBooleanArgumentTest extends TestCase
{
    #[DataProvider('provideBooleanArgumentCases')]
    public function testBooleanArgumentDetection(string $code, int $expectedCount, string $description): void
    {
        $visitor = new CodeSmellVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $locations = $visitor->getLocationsByType('boolean_argument');

        self::assertCount($expectedCount, $locations, $description);
    }

    /**
     * @return iterable<string, array{code: string, expectedCount: int, description: string}>
     */
    public static function provideBooleanArgumentCases(): iterable
    {
        yield 'simple bool parameter' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething(bool $flag): void {}
}
PHP,
            'expectedCount' => 1,
            'description' => 'Simple bool should be detected',
        ];

        yield 'nullable bool parameter' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething(?bool $flag): void {}
}
PHP,
            'expectedCount' => 1,
            'description' => '?bool should be detected',
        ];

        yield 'union type with bool and null' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething(bool|null $flag): void {}
}
PHP,
            'expectedCount' => 1,
            'description' => 'bool|null should be detected',
        ];

        yield 'union type with bool and string' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething(bool|string $flag): void {}
}
PHP,
            'expectedCount' => 1,
            'description' => 'bool|string should be detected',
        ];

        yield 'union type without bool' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething(string|int $value): void {}
}
PHP,
            'expectedCount' => 0,
            'description' => 'string|int should NOT be detected',
        ];

        yield 'no type hint' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething($value): void {}
}
PHP,
            'expectedCount' => 0,
            'description' => 'No type hint should NOT be detected',
        ];

        yield 'string parameter' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething(string $value): void {}
}
PHP,
            'expectedCount' => 0,
            'description' => 'string should NOT be detected',
        ];

        yield 'nullable string parameter' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething(?string $value): void {}
}
PHP,
            'expectedCount' => 0,
            'description' => '?string should NOT be detected',
        ];

        yield 'function with bool' => [
            'code' => <<<'PHP'
<?php
function doSomething(bool $flag): void {}
PHP,
            'expectedCount' => 1,
            'description' => 'Function with bool should be detected',
        ];

        yield 'function with nullable bool' => [
            'code' => <<<'PHP'
<?php
function doSomething(?bool $flag): void {}
PHP,
            'expectedCount' => 1,
            'description' => 'Function with ?bool should be detected',
        ];

        yield 'multiple bool params in one method' => [
            'code' => <<<'PHP'
<?php
class Foo {
    public function doSomething(bool $a, ?bool $b, bool|null $c): void {}
}
PHP,
            'expectedCount' => 3,
            'description' => 'All three bool variants should be detected',
        ];
    }
}
