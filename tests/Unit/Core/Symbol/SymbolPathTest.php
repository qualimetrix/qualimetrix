<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

#[CoversClass(SymbolPath::class)]
final class SymbolPathTest extends TestCase
{
    #[DataProvider('canonicalDataProvider')]
    #[Test]
    public function itToCanonical(SymbolPath $symbolPath, string $expected): void
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

    #[Test]
    public function itForMethodCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forMethod('App\Service', 'UserService', 'calculate');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertSame('calculate', $symbolPath->member);
    }

    #[Test]
    public function itForClassCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    #[Test]
    public function itForNamespaceCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forNamespace('App\Service');

        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    #[Test]
    public function itForFileCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forFile('src/test.php');

        self::assertNull($symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertNull($symbolPath->member);
    }

    #[Test]
    public function itForGlobalFunctionCreatesCorrectSymbolPath(): void
    {
        $symbolPath = SymbolPath::forGlobalFunction('', 'myFunction');

        self::assertSame('', $symbolPath->namespace);
        self::assertNull($symbolPath->type);
        self::assertSame('myFunction', $symbolPath->member);
    }

    #[Test]
    public function itForGlobalFunctionToStringOmitsNamespace(): void
    {
        $symbolPath = SymbolPath::forGlobalFunction('', 'myFunction');

        self::assertSame('myFunction', $symbolPath->toString());
    }

    #[DataProvider('typeDataProvider')]
    #[Test]
    public function itGetType(SymbolPath $symbolPath, SymbolType $expected): void
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

    #[Test]
    public function itGlobalNamespaceIsDistinctFromProject(): void
    {
        $globalNs = SymbolPath::forNamespace('');
        $project = SymbolPath::forProject();

        self::assertSame(SymbolType::Namespace_, $globalNs->getType());
        self::assertSame(SymbolType::Project, $project->getType());
        self::assertNotSame($globalNs->toCanonical(), $project->toCanonical());
        self::assertNotSame($globalNs->toString(), $project->toString());
    }

    #[Test]
    public function itForProjectToString(): void
    {
        self::assertSame('(project)', SymbolPath::forProject()->toString());
    }

    #[Test]
    public function itForGlobalNamespaceToString(): void
    {
        self::assertSame('(global)', SymbolPath::forNamespace('')->toString());
    }

    #[DataProvider('symbolNameDataProvider')]
    #[Test]
    public function itGetSymbolName(SymbolPath $symbolPath, ?string $expected): void
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
    #[Test]
    public function itFromClassFqn(string $fqn, ?string $expectedNamespace, string $expectedType): void
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

    #[Test]
    public function itFromNamespaceFqn(): void
    {
        $path = SymbolPath::fromNamespaceFqn('App\Service');

        self::assertSame('App\Service', $path->namespace);
        self::assertNull($path->type);
        self::assertNull($path->member);
        self::assertSame(SymbolType::Namespace_, $path->getType());
    }

    #[Test]
    public function itFromClassFqnProducesEquivalentToForClass(): void
    {
        $fromFqn = SymbolPath::fromClassFqn('App\Service\UserService');
        $forClass = SymbolPath::forClass('App\Service', 'UserService');

        self::assertSame($forClass->toCanonical(), $fromFqn->toCanonical());
        self::assertSame($forClass->toString(), $fromFqn->toString());
    }

    #[Test]
    public function itFromClassFqnNormalizesLeadingBackslash(): void
    {
        $path = SymbolPath::fromClassFqn('\\App\\Service\\Foo');
        self::assertSame('App\\Service', $path->namespace);
        self::assertSame('Foo', $path->type);
    }

    #[Test]
    public function itFromClassFqnLeadingBackslashEquivalentToForClass(): void
    {
        $fromFqn = SymbolPath::fromClassFqn('\\App\\Service\\UserService');
        $forClass = SymbolPath::forClass('App\\Service', 'UserService');

        self::assertSame($forClass->toCanonical(), $fromFqn->toCanonical());
        self::assertSame($forClass->toString(), $fromFqn->toString());
    }

    #[Test]
    public function itFromNamespaceFqnNormalizesLeadingBackslash(): void
    {
        $withBackslash = SymbolPath::fromNamespaceFqn('\\App\\Service');
        $withoutBackslash = SymbolPath::fromNamespaceFqn('App\\Service');

        self::assertSame($withoutBackslash->toCanonical(), $withBackslash->toCanonical());
        self::assertSame('App\\Service', $withBackslash->namespace);
    }

    #[Test]
    public function itFromClassFqnGlobalEquivalentToForClass(): void
    {
        $fromFqn = SymbolPath::fromClassFqn('GlobalClass');
        $forClass = SymbolPath::forClass('', 'GlobalClass');

        self::assertSame($forClass->toCanonical(), $fromFqn->toCanonical());
        self::assertSame($forClass->toString(), $fromFqn->toString());
    }
}
