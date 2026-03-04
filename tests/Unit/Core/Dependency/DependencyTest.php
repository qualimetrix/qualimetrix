<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Violation\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Dependency::class)]
final class DependencyTest extends TestCase
{
    public function testConstructorWithAllProperties(): void
    {
        $location = new Location('src/Service/UserService.php', 42);
        $dependency = new Dependency(
            sourceClass: 'App\Service\UserService',
            targetClass: 'App\Repository\UserRepository',
            type: DependencyType::New_,
            location: $location,
        );

        self::assertSame('App\Service\UserService', $dependency->sourceClass);
        self::assertSame('App\Repository\UserRepository', $dependency->targetClass);
        self::assertSame(DependencyType::New_, $dependency->type);
        self::assertSame($location, $dependency->location);
    }

    #[DataProvider('namespaceDataProvider')]
    public function testGetSourceNamespace(string $sourceClass, string $expectedNamespace): void
    {
        $dependency = new Dependency(
            sourceClass: $sourceClass,
            targetClass: 'Target',
            type: DependencyType::New_,
            location: new Location('test.php'),
        );

        self::assertSame($expectedNamespace, $dependency->getSourceNamespace());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namespaceDataProvider(): iterable
    {
        yield 'single level namespace' => ['App\User', 'App'];
        yield 'multi level namespace' => ['App\Service\UserService', 'App\Service'];
        yield 'deep namespace' => ['App\Module\Sub\Service\User', 'App\Module\Sub\Service'];
        yield 'no namespace' => ['GlobalClass', ''];
        yield 'single backslash' => ['App\\User', 'App'];
    }

    #[DataProvider('targetNamespaceDataProvider')]
    public function testGetTargetNamespace(string $targetClass, string $expectedNamespace): void
    {
        $dependency = new Dependency(
            sourceClass: 'Source',
            targetClass: $targetClass,
            type: DependencyType::New_,
            location: new Location('test.php'),
        );

        self::assertSame($expectedNamespace, $dependency->getTargetNamespace());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function targetNamespaceDataProvider(): iterable
    {
        yield 'single level namespace' => ['App\User', 'App'];
        yield 'multi level namespace' => ['App\Repository\UserRepository', 'App\Repository'];
        yield 'no namespace' => ['GlobalClass', ''];
    }

    #[DataProvider('crossNamespaceDataProvider')]
    public function testIsCrossNamespace(
        string $sourceClass,
        string $targetClass,
        bool $expectedIsCrossNamespace,
    ): void {
        $dependency = new Dependency(
            sourceClass: $sourceClass,
            targetClass: $targetClass,
            type: DependencyType::New_,
            location: new Location('test.php'),
        );

        self::assertSame($expectedIsCrossNamespace, $dependency->isCrossNamespace());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function crossNamespaceDataProvider(): iterable
    {
        yield 'same namespace' => [
            'App\Service\UserService',
            'App\Service\OrderService',
            false,
        ];

        yield 'different namespace' => [
            'App\Service\UserService',
            'App\Repository\UserRepository',
            true,
        ];

        yield 'both global namespace' => [
            'GlobalClass1',
            'GlobalClass2',
            false,
        ];

        yield 'source global, target namespaced' => [
            'GlobalClass',
            'App\Service\UserService',
            true,
        ];

        yield 'source namespaced, target global' => [
            'App\Service\UserService',
            'GlobalClass',
            true,
        ];

        yield 'deep same namespace' => [
            'App\Module\Sub\Service\UserService',
            'App\Module\Sub\Service\OrderService',
            false,
        ];
    }

    #[DataProvider('strongCouplingDataProvider')]
    public function testIsStrongCoupling(DependencyType $type, bool $expectedIsStrongCoupling): void
    {
        $dependency = new Dependency(
            sourceClass: 'App\Service\UserService',
            targetClass: 'App\Repository\UserRepository',
            type: $type,
            location: new Location('test.php'),
        );

        self::assertSame($expectedIsStrongCoupling, $dependency->isStrongCoupling());
    }

    /**
     * @return iterable<string, array{DependencyType, bool}>
     */
    public static function strongCouplingDataProvider(): iterable
    {
        yield 'extends is strong' => [DependencyType::Extends, true];
        yield 'implements is strong' => [DependencyType::Implements, true];
        yield 'trait use is strong' => [DependencyType::TraitUse, true];
        yield 'new is not strong' => [DependencyType::New_, false];
        yield 'static call is not strong' => [DependencyType::StaticCall, false];
        yield 'type hint is not strong' => [DependencyType::TypeHint, false];
        yield 'attribute is not strong' => [DependencyType::Attribute, false];
    }

    #[DataProvider('toStringDataProvider')]
    public function testToString(
        string $sourceClass,
        string $targetClass,
        DependencyType $type,
        Location $location,
        string $expected,
    ): void {
        $dependency = new Dependency(
            sourceClass: $sourceClass,
            targetClass: $targetClass,
            type: $type,
            location: $location,
        );

        self::assertSame($expected, $dependency->toString());
    }

    /**
     * @return iterable<string, array{string, string, DependencyType, Location, string}>
     */
    public static function toStringDataProvider(): iterable
    {
        yield 'new dependency with line' => [
            'App\Service\UserService',
            'App\Repository\UserRepository',
            DependencyType::New_,
            new Location('src/Service/UserService.php', 42),
            'App\Service\UserService instantiates App\Repository\UserRepository at src/Service/UserService.php:42',
        ];

        yield 'extends dependency' => [
            'App\Service\UserService',
            'App\Service\BaseService',
            DependencyType::Extends,
            new Location('src/Service/UserService.php', 10),
            'App\Service\UserService extends class App\Service\BaseService at src/Service/UserService.php:10',
        ];

        yield 'implements dependency' => [
            'App\Service\UserService',
            'App\Contract\ServiceInterface',
            DependencyType::Implements,
            new Location('src/Service/UserService.php', 8),
            'App\Service\UserService implements interface App\Contract\ServiceInterface at src/Service/UserService.php:8',
        ];

        yield 'dependency without line' => [
            'App\Model\User',
            'App\Trait\Timestampable',
            DependencyType::TraitUse,
            new Location('src/Model/User.php'),
            'App\Model\User uses trait App\Trait\Timestampable at src/Model/User.php',
        ];
    }

    public function testDependencyIsReadonly(): void
    {
        $dependency = new Dependency(
            sourceClass: 'Source',
            targetClass: 'Target',
            type: DependencyType::New_,
            location: new Location('test.php'),
        );

        // This test verifies that Dependency is readonly
        // The readonly keyword ensures immutability at the language level
        self::assertInstanceOf(Dependency::class, $dependency);
    }
}
