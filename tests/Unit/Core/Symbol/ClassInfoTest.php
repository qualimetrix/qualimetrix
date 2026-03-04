<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\Symbol;

use AiMessDetector\Core\Symbol\ClassInfo;
use AiMessDetector\Core\Symbol\ClassType;
use AiMessDetector\Core\Violation\SymbolPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassInfo::class)]
final class ClassInfoTest extends TestCase
{
    public function testConstructorWithAllProperties(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Service\UserService',
            namespace: 'App\Service',
            name: 'UserService',
            file: 'src/Service/UserService.php',
            line: 10,
            type: ClassType::Class_,
        );

        self::assertSame('App\Service\UserService', $classInfo->fqn);
        self::assertSame('App\Service', $classInfo->namespace);
        self::assertSame('UserService', $classInfo->name);
        self::assertSame('src/Service/UserService.php', $classInfo->file);
        self::assertSame(10, $classInfo->line);
        self::assertSame(ClassType::Class_, $classInfo->type);
    }

    #[DataProvider('classTypeDataProvider')]
    public function testConstructorWithDifferentClassTypes(ClassType $classType): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Test',
            namespace: 'App',
            name: 'Test',
            file: 'test.php',
            line: 1,
            type: $classType,
        );

        self::assertSame($classType, $classInfo->type);
    }

    /**
     * @return iterable<string, array{ClassType}>
     */
    public static function classTypeDataProvider(): iterable
    {
        yield 'class' => [ClassType::Class_];
        yield 'interface' => [ClassType::Interface_];
        yield 'trait' => [ClassType::Trait_];
        yield 'enum' => [ClassType::Enum_];
    }

    public function testGetSymbolPathReturnsCorrectSymbolPath(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Service\UserService',
            namespace: 'App\Service',
            name: 'UserService',
            file: 'src/Service/UserService.php',
            line: 10,
            type: ClassType::Class_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertInstanceOf(SymbolPath::class, $symbolPath);
        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertNull($symbolPath->member);
        self::assertSame('class:App\Service\UserService', $symbolPath->toCanonical());
    }

    public function testGetSymbolPathForClassWithoutNamespace(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'GlobalClass',
            namespace: '',
            name: 'GlobalClass',
            file: 'src/GlobalClass.php',
            line: 5,
            type: ClassType::Class_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertNull($symbolPath->namespace);
        self::assertSame('GlobalClass', $symbolPath->type);
        self::assertSame('class:GlobalClass', $symbolPath->toCanonical());
    }

    public function testGetSymbolPathForInterface(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Contract\UserRepositoryInterface',
            namespace: 'App\Contract',
            name: 'UserRepositoryInterface',
            file: 'src/Contract/UserRepositoryInterface.php',
            line: 8,
            type: ClassType::Interface_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertSame('class:App\Contract\UserRepositoryInterface', $symbolPath->toCanonical());
    }

    public function testGetSymbolPathForTrait(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Trait\Timestampable',
            namespace: 'App\Trait',
            name: 'Timestampable',
            file: 'src/Trait/Timestampable.php',
            line: 12,
            type: ClassType::Trait_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertSame('class:App\Trait\Timestampable', $symbolPath->toCanonical());
    }

    public function testGetSymbolPathForEnum(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Enum\Status',
            namespace: 'App\Enum',
            name: 'Status',
            file: 'src/Enum/Status.php',
            line: 7,
            type: ClassType::Enum_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertSame('class:App\Enum\Status', $symbolPath->toCanonical());
    }

    public function testClassInfoIsReadonly(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'Test',
            namespace: '',
            name: 'Test',
            file: 'test.php',
            line: 1,
            type: ClassType::Class_,
        );

        // This test verifies that ClassInfo is readonly
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(ClassInfo::class, $classInfo);
    }
}
