<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit\Extraction;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;
use Qualimetrix\Core\Ast\NameResolution;

#[CoversClass(DependencyResolver::class)]
final class DependencyResolverTest extends TestCase
{
    #[Test]
    #[DataProvider('classNameCases')]
    public function itReadsTheClassNameResolvedByTheSharedAstPass(string $code, string $expected): void
    {
        $name = self::parameterType($code);

        self::assertSame($expected, (new DependencyResolver())->resolve($name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function classNameCases(): iterable
    {
        yield 'fully qualified' => [
            '<?php namespace App; final class Subject { public function f(\\Vendor\\Package\\Type $v): void {} }',
            'Vendor\\Package\\Type',
        ];
        yield 'relative' => [
            '<?php namespace App; final class Subject { public function f(namespace\\Package\\Type $v): void {} }',
            'App\\Package\\Type',
        ];
        yield 'unqualified import with another case' => [
            '<?php namespace App; use Vendor\\Package\\SomeClass; final class Subject { public function f(SOMECLASS $v): void {} }',
            'Vendor\\Package\\SomeClass',
        ];
        yield 'alias with another case' => [
            '<?php namespace App; use Vendor\\Package\\SomeClass as Alias; final class Subject { public function f(alias $v): void {} }',
            'Vendor\\Package\\SomeClass',
        ];
        yield 'qualified alias with another case' => [
            '<?php namespace App; use Vendor\\Package as P; final class Subject { public function f(p\\SubClass $v): void {} }',
            'Vendor\\Package\\SubClass',
        ];
        yield 'unimported name' => [
            '<?php namespace App\\Domain; final class Subject { public function f(MyClass $v): void {} }',
            'App\\Domain\\MyClass',
        ];
        yield 'global name' => [
            '<?php final class Subject { public function f(GlobalClass $v): void {} }',
            'GlobalClass',
        ];
        yield 'group import' => [
            '<?php namespace App; use Vendor\\Package\\{ClassA, ClassB as B}; final class Subject { public function f(b $v): void {} }',
            'Vendor\\Package\\ClassB',
        ];
        yield 'function import does not bind a class name' => [
            '<?php namespace App; use function Vendor\\Thing; final class Subject { public function f(Thing $v): void {} }',
            'App\\Thing',
        ];
        yield 'constant import does not bind a class name' => [
            '<?php namespace App; use const Vendor\\Thing; final class Subject { public function f(Thing $v): void {} }',
            'App\\Thing',
        ];
    }

    #[Test]
    public function itKeepsTheFirstImportWhenPhpReportsAnAliasConflict(): void
    {
        $name = self::parameterType(
            '<?php namespace App; use Vendor\\One as Alias; use Vendor\\Two as Alias; final class Subject { public function f(alias $v): void {} }',
        );

        self::assertSame('Vendor\\One', (new DependencyResolver())->resolve($name));
    }

    private static function parameterType(string $code): Name
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        NameResolution::resolve($ast);
        $parameter = (new NodeFinder())->findFirst(
            $ast,
            static fn(Node $node): bool => $node instanceof Param && $node->type instanceof Name,
        );
        self::assertInstanceOf(Param::class, $parameter);
        self::assertInstanceOf(Name::class, $parameter->type);

        return $parameter->type;
    }
}
