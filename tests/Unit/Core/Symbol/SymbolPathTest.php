<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\Symbol;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolPath::class)]
final class SymbolPathTest extends TestCase
{
    #[DataProvider('canonicalDataProvider')]
    public function testToCanonical(SymbolPath $symbolPath, string $expected): void
    {
        self::assertSame($expected, $symbolPath->toCanonical());
    }

    /**
     * @return iterable<string, array{SymbolPath, string}>
     */
    public static function canonicalDataProvider(): iterable
    {
        yield 'method with namespace' => [
            SymbolPath::forMethod('App\Service', 'UserService', 'calculateTotal'),
            'method:App\Service\UserService::calculateTotal',
        ];

        yield 'method without namespace' => [
            SymbolPath::forMethod('', 'UserService', 'calculate'),
            'method:UserService::calculate',
        ];

        yield 'class with namespace' => [
            SymbolPath::forClass('App\Service', 'UserService'),
            'class:App\Service\UserService',
        ];

        yield 'class without namespace' => [
            SymbolPath::forClass('', 'UserService'),
            'class:UserService',
        ];

        yield 'namespace' => [
            SymbolPath::forNamespace('App\Service'),
            'ns:App\Service',
        ];

        yield 'global namespace' => [
            SymbolPath::forNamespace(''),
            'ns:',
        ];

        yield 'project' => [
            SymbolPath::forProject(),
            'project:',
        ];

        yield 'file' => [
            SymbolPath::forFile('src/Service/UserService.php'),
            'file:src/Service/UserService.php',
        ];

        yield 'global function' => [
            SymbolPath::forGlobalFunction('', 'globalFunction'),
            'func::globalFunction',
        ];

        yield 'namespaced function' => [
            SymbolPath::forGlobalFunction('App\Utils', 'helper'),
            'func:App\Utils::helper',
        ];
    }

    public function testForMethodCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertSame('calculate', $symbolPath->member);
    }

    public function testForClassCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    public function testForNamespaceCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forNamespace('App\Service');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    public function testForFileCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forFile('src/test.php');

        self::assertNull($symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    public function testForGlobalFunctionCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forGlobalFunction('', 'myFunction');

        self::assertSame('', $symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertSame('myFunction', $symbolPath->member);
    }

    public function testForGlobalFunctionToStringOmitsNamespace(): void
    {
        $symbolPath = SymbolPath::forGlobalFunction('', 'myFunction');

        self::assertSame('myFunction', $symbolPath->toString());
    }

    #[DataProvider('typeDataProvider')]
    public function testGetType(SymbolPath $symbolPath, SymbolType $expected): void
    {
        self::assertSame($expected, $symbolPath->getType());
    }

    /**
     * @return iterable<string, array{SymbolPath, SymbolType}>
     */
    public static function typeDataProvider(): iterable
    {
        yield 'method' => [
            SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            SymbolType::Method,
        ];

        yield 'method without namespace' => [
            SymbolPath::forMethod('', 'UserService', 'calculate'),
            SymbolType::Method,
        ];

        yield 'class' => [
            SymbolPath::forClass('App\Service', 'UserService'),
            SymbolType::Class_,
        ];

        yield 'class without namespace' => [
            SymbolPath::forClass('', 'UserService'),
            SymbolType::Class_,
        ];

        yield 'namespace' => [
            SymbolPath::forNamespace('App\Service'),
            SymbolType::Namespace_,
        ];

        yield 'global namespace' => [
            SymbolPath::forNamespace(''),
            SymbolType::Namespace_,
        ];

        yield 'project' => [
            SymbolPath::forProject(),
            SymbolType::Project,
        ];

        yield 'file' => [
            SymbolPath::forFile('src/test.php'),
            SymbolType::File,
        ];

        yield 'global function' => [
            SymbolPath::forGlobalFunction('', 'strlen'),
            SymbolType::Function_,
        ];

        yield 'namespaced function' => [
            SymbolPath::forGlobalFunction('App\Utils', 'helper'),
            SymbolType::Function_,
        ];
    }

    public function testGlobalNamespaceIsDistinctFromProject(): void
    {
        $globalNs = SymbolPath::forNamespace('');
        $project = SymbolPath::forProject();

        self::assertSame(SymbolType::Namespace_, $globalNs->getType());
        self::assertSame(SymbolType::Project, $project->getType());
        self::assertNotSame($globalNs->toCanonical(), $project->toCanonical());
        self::assertNotSame($globalNs->toString(), $project->toString());
    }

    public function testForProjectToString(): void
    {
        self::assertSame('(project)', SymbolPath::forProject()->toString());
    }

    public function testForGlobalNamespaceToString(): void
    {
        self::assertSame('(global)', SymbolPath::forNamespace('')->toString());
    }

    #[DataProvider('symbolNameDataProvider')]
    public function testGetSymbolName(SymbolPath $symbolPath, ?string $expected): void
    {
        self::assertSame($expected, $symbolPath->getSymbolName());
    }

    /**
     * @return iterable<string, array{SymbolPath, ?string}>
     */
    public static function symbolNameDataProvider(): iterable
    {
        yield 'method with namespace' => [
            SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            'UserService::calculate',
        ];

        yield 'method without namespace' => [
            SymbolPath::forMethod('', 'UserService', 'calculate'),
            'UserService::calculate',
        ];

        yield 'class with namespace' => [
            SymbolPath::forClass('App\Service', 'UserService'),
            'UserService',
        ];

        yield 'class without namespace' => [
            SymbolPath::forClass('', 'UserService'),
            'UserService',
        ];

        yield 'namespace' => [
            SymbolPath::forNamespace('App\Service'),
            null,
        ];

        yield 'project' => [
            SymbolPath::forProject(),
            null,
        ];

        yield 'file' => [
            SymbolPath::forFile('src/test.php'),
            null,
        ];

        yield 'global function' => [
            SymbolPath::forGlobalFunction('', 'strlen'),
            'strlen',
        ];

        yield 'namespaced function' => [
            SymbolPath::forGlobalFunction('App\Utils', 'helper'),
            'helper',
        ];
    }

    #[DataProvider('fromClassFqnDataProvider')]
    public function testFromClassFqn(string $fqn, ?string $expectedNamespace, string $expectedType): void
    {
        $path = SymbolPath::fromClassFqn($fqn);

        self::assertSame($expectedNamespace, $path->namespace);
        self::assertSame($expectedType, $path->type);
        self::assertNull($path->member);
        self::assertSame(SymbolType::Class_, $path->getType());
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function fromClassFqnDataProvider(): iterable
    {
        yield 'namespaced class' => [
            'App\Service\UserService',
            'App\Service',
            'UserService',
        ];

        yield 'single namespace level' => [
            'App\User',
            'App',
            'User',
        ];

        yield 'deep namespace' => [
            'App\Module\Sub\Service\User',
            'App\Module\Sub\Service',
            'User',
        ];

        yield 'global class (no namespace)' => [
            'GlobalClass',
            '',
            'GlobalClass',
        ];
    }

    public function testFromNamespaceFqn(): void
    {
        $path = SymbolPath::fromNamespaceFqn('App\Service');

        self::assertSame('App\Service', $path->namespace);
        self::assertNull($path->type);
        self::assertNull($path->member);
        self::assertSame(SymbolType::Namespace_, $path->getType());
    }

    public function testFromClassFqnProducesEquivalentToForClass(): void
    {
        $fromFqn = SymbolPath::fromClassFqn('App\Service\UserService');
        $forClass = SymbolPath::forClass('App\Service', 'UserService');

        self::assertSame($forClass->toCanonical(), $fromFqn->toCanonical());
        self::assertSame($forClass->toString(), $fromFqn->toString());
    }

    public function testFromClassFqnNormalizesLeadingBackslash(): void
    {
        $path = SymbolPath::fromClassFqn('\\App\\Service\\Foo');
        self::assertSame('App\\Service', $path->namespace);
        self::assertSame('Foo', $path->type);
    }

    public function testFromClassFqnLeadingBackslashEquivalentToForClass(): void
    {
        $fromFqn = SymbolPath::fromClassFqn('\\App\\Service\\UserService');
        $forClass = SymbolPath::forClass('App\\Service', 'UserService');

        self::assertSame($forClass->toCanonical(), $fromFqn->toCanonical());
        self::assertSame($forClass->toString(), $fromFqn->toString());
    }

    public function testFromNamespaceFqnNormalizesLeadingBackslash(): void
    {
        $withBackslash = SymbolPath::fromNamespaceFqn('\\App\\Service');
        $withoutBackslash = SymbolPath::fromNamespaceFqn('App\\Service');

        self::assertSame($withoutBackslash->toCanonical(), $withBackslash->toCanonical());
        self::assertSame('App\\Service', $withBackslash->namespace);
    }

    public function testFromClassFqnGlobalEquivalentToForClass(): void
    {
        $fromFqn = SymbolPath::fromClassFqn('GlobalClass');
        $forClass = SymbolPath::forClass('', 'GlobalClass');

        self::assertSame($forClass->toCanonical(), $fromFqn->toCanonical());
        self::assertSame($forClass->toString(), $fromFqn->toString());
    }
}
