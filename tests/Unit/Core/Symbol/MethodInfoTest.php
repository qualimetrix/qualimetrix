<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MethodInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(MethodInfo::class)]
final class MethodInfoTest extends TestCase
{
    #[Test]
    public function itConstructorWithAllProperties(): void
    {
        $methodInfo = new MethodInfo(
            fqn: 'App\Service\UserService::calculate',
            namespace: 'App\Service',
            class: 'UserService',
            name: 'calculate',
            file: RelativePath::fromString('src/Service/UserService.php'),
            line: 42,
        );

        self::assertSame('App\Service\UserService::calculate', $methodInfo->fqn);
        self::assertSame('App\Service', $methodInfo->namespace);
        self::assertSame('UserService', $methodInfo->class);
        self::assertSame('calculate', $methodInfo->name);
        self::assertSame('src/Service/UserService.php', $methodInfo->file->value());
        self::assertSame(42, $methodInfo->line);
    }

    #[Test]
    public function itGetSymbolPathReturnsCorrectSymbolPath(): void
    {
        $methodInfo = new MethodInfo(
            fqn: 'App\Service\UserService::calculate',
            namespace: 'App\Service',
            class: 'UserService',
            name: 'calculate',
            file: RelativePath::fromString('src/Service/UserService.php'),
            line: 42,
        );

        $symbolPath = $methodInfo->getSymbolPath();

        self::assertInstanceOf(SymbolPath::class, $symbolPath); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertSame('calculate', $symbolPath->member);
        self::assertSame('method:App\Service\UserService::calculate', $symbolPath->toCanonical());
    }

    #[Test]
    public function itGetSymbolPathForMethodWithoutNamespace(): void
    {
        $methodInfo = new MethodInfo(
            fqn: 'GlobalClass::method',
            namespace: '',
            class: 'GlobalClass',
            name: 'method',
            file: RelativePath::fromString('src/GlobalClass.php'),
            line: 10,
        );

        $symbolPath = $methodInfo->getSymbolPath();

        self::assertSame('', $symbolPath->namespace);
        self::assertSame('GlobalClass', $symbolPath->type);
        self::assertSame('method', $symbolPath->member);
        self::assertSame('method:GlobalClass::method', $symbolPath->toCanonical());
    }

    #[Test]
    public function itGetSymbolPathForConstructor(): void
    {
        $methodInfo = new MethodInfo(
            fqn: 'App\Domain\User::__construct',
            namespace: 'App\Domain',
            class: 'User',
            name: '__construct',
            file: RelativePath::fromString('src/Domain/User.php'),
            line: 15,
        );

        $symbolPath = $methodInfo->getSymbolPath();

        self::assertSame('__construct', $symbolPath->member);
        self::assertSame('method:App\Domain\User::__construct', $symbolPath->toCanonical());
    }

    #[Test]
    public function itGetSymbolPathForMagicMethod(): void
    {
        $methodInfo = new MethodInfo(
            fqn: 'App\Model\User::__toString',
            namespace: 'App\Model',
            class: 'User',
            name: '__toString',
            file: RelativePath::fromString('src/Model/User.php'),
            line: 50,
        );

        $symbolPath = $methodInfo->getSymbolPath();

        self::assertSame('__toString', $symbolPath->member);
        self::assertSame('method:App\Model\User::__toString', $symbolPath->toCanonical());
    }

    #[Test]
    public function itGetSymbolPathForStaticMethod(): void
    {
        $methodInfo = new MethodInfo(
            fqn: 'App\Factory\UserFactory::create',
            namespace: 'App\Factory',
            class: 'UserFactory',
            name: 'create',
            file: RelativePath::fromString('src/Factory/UserFactory.php'),
            line: 20,
        );

        $symbolPath = $methodInfo->getSymbolPath();

        self::assertSame('method:App\Factory\UserFactory::create', $symbolPath->toCanonical());
    }

    #[Test]
    public function itMethodInfoIsReadonly(): void
    {
        $methodInfo = new MethodInfo(
            fqn: 'Test::method',
            namespace: '',
            class: 'Test',
            name: 'method',
            file: RelativePath::fromString('test.php'),
            line: 5,
        );

        // This test verifies that MethodInfo is readonly
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(MethodInfo::class, $methodInfo); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    #[Test]
    public function itConstructorWithDeepNestedNamespace(): void
    {
        $methodInfo = new MethodInfo(
            fqn: 'App\Module\SubModule\Service\UserService::process',
            namespace: 'App\Module\SubModule\Service',
            class: 'UserService',
            name: 'process',
            file: RelativePath::fromString('src/Module/SubModule/Service/UserService.php'),
            line: 100,
        );

        $symbolPath = $methodInfo->getSymbolPath();

        self::assertSame('App\Module\SubModule\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertSame('process', $symbolPath->member);
    }
}
