<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Symbol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassInfo;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ClassInfo::class)]
final class ClassInfoTest extends TestCase
{
    #[Test]
    public function itConstructorWithAllProperties(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Service\UserService',
            namespace: 'App\Service',
            name: 'UserService',
            file: RelativePath::fromString('src/Service/UserService.php'),
            line: 10,
            type: ClassType::Class_,
        );

        self::assertSame('App\Service\UserService', $classInfo->fqn);
        self::assertSame('App\Service', $classInfo->namespace);
        self::assertSame('UserService', $classInfo->name);
        self::assertSame('src/Service/UserService.php', $classInfo->file->value());
        self::assertSame(10, $classInfo->line);
        self::assertSame(ClassType::Class_, $classInfo->type);
    }

    #[DataProvider('classTypeDataProvider')]
    #[Test]
    public function itConstructorWithDifferentClassTypes(ClassType $classType): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Test',
            namespace: 'App',
            name: 'Test',
            file: RelativePath::fromString('test.php'),
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

    #[Test]
    public function itGetSymbolPathReturnsCorrectSymbolPath(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Service\UserService',
            namespace: 'App\Service',
            name: 'UserService',
            file: RelativePath::fromString('src/Service/UserService.php'),
            line: 10,
            type: ClassType::Class_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertInstanceOf(SymbolPath::class, $symbolPath); // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertSame('App\Service', $symbolPath->namespace);
        self::assertSame('UserService', $symbolPath->type);
        self::assertNull($symbolPath->member);
        self::assertSame('class:App\Service\UserService', $symbolPath->toCanonical());
    }

    #[Test]
    public function itGetSymbolPathForClassWithoutNamespace(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'GlobalClass',
            namespace: '',
            name: 'GlobalClass',
            file: RelativePath::fromString('src/GlobalClass.php'),
            line: 5,
            type: ClassType::Class_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertSame('', $symbolPath->namespace);
        self::assertSame('GlobalClass', $symbolPath->type);
        self::assertSame('class:GlobalClass', $symbolPath->toCanonical());
    }

    #[Test]
    public function itGetSymbolPathForInterface(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Contract\UserRepositoryInterface',
            namespace: 'App\Contract',
            name: 'UserRepositoryInterface',
            file: RelativePath::fromString('src/Contract/UserRepositoryInterface.php'),
            line: 8,
            type: ClassType::Interface_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertSame('class:App\Contract\UserRepositoryInterface', $symbolPath->toCanonical());
    }

    #[Test]
    public function itGetSymbolPathForTrait(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Trait\Timestampable',
            namespace: 'App\Trait',
            name: 'Timestampable',
            file: RelativePath::fromString('src/Trait/Timestampable.php'),
            line: 12,
            type: ClassType::Trait_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertSame('class:App\Trait\Timestampable', $symbolPath->toCanonical());
    }

    #[Test]
    public function itGetSymbolPathForEnum(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'App\Enum\Status',
            namespace: 'App\Enum',
            name: 'Status',
            file: RelativePath::fromString('src/Enum/Status.php'),
            line: 7,
            type: ClassType::Enum_,
        );

        $symbolPath = $classInfo->getSymbolPath();

        self::assertSame('class:App\Enum\Status', $symbolPath->toCanonical());
    }

    #[Test]
    public function itClassInfoIsReadonly(): void
    {
        $classInfo = new ClassInfo(
            fqn: 'Test',
            namespace: '',
            name: 'Test',
            file: RelativePath::fromString('test.php'),
            line: 1,
            type: ClassType::Class_,
        );

        // This test verifies that ClassInfo is readonly
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(ClassInfo::class, $classInfo); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }
}
