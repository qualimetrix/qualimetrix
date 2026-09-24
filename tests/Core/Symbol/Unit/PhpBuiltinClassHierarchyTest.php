<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Symbol\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;

#[CoversClass(PhpBuiltinClassHierarchy::class)]
final class PhpBuiltinClassHierarchyTest extends TestCase
{
    #[Test]
    public function itFollowsAClassToItsParent(): void
    {
        self::assertSame(['Exception'], PhpBuiltinClassHierarchy::extendsOf('RuntimeException'));
        self::assertSame(['Stringable', 'Throwable'], PhpBuiltinClassHierarchy::interfacesOf('RuntimeException'));
        self::assertSame([], PhpBuiltinClassHierarchy::extendsOf('Exception'));
    }

    #[Test]
    public function itFollowsAnInterfaceToTheInterfacesItExtends(): void
    {
        // Reflection never sets a parent class on an interface, which is how
        // `Traversable` went missing above `IteratorAggregate`.
        self::assertSame(['Traversable'], PhpBuiltinClassHierarchy::extendsOf('IteratorAggregate'));
        self::assertSame(['Traversable'], PhpBuiltinClassHierarchy::interfacesOf('IteratorAggregate'));
        self::assertSame(['UnitEnum'], PhpBuiltinClassHierarchy::extendsOf('BackedEnum'));
    }

    #[Test]
    public function itAnswersForANameWhoseExtensionThisRuntimeMayNotLoad(): void
    {
        self::assertSame(['PDO'], PhpBuiltinClassHierarchy::extendsOf('Pdo\\Firebird'));
        self::assertSame(['Uri\\UriException'], PhpBuiltinClassHierarchy::extendsOf('Uri\\InvalidUriException'));
        self::assertSame([], PhpBuiltinClassHierarchy::interfacesOf('EnchantBroker'));
    }

    #[Test]
    public function itCarriesTheAttributesOnPhpsOwnDeclaration(): void
    {
        self::assertSame(['AllowDynamicProperties'], PhpBuiltinClassHierarchy::attributesOf('stdClass'));
        self::assertSame([], PhpBuiltinClassHierarchy::attributesOf('DateTime'));
    }

    /**
     * A name the registry recognises in another case spelling must carry its
     * ancestry too; recognising it and answering with no parent would turn
     * `\runtimeexception` into a root.
     */
    #[Test]
    public function itAnswersForARegisteredNameInAnotherCaseSpelling(): void
    {
        self::assertSame(['Exception'], PhpBuiltinClassHierarchy::extendsOf('runtimeexception'));
        self::assertSame(['Stringable', 'Throwable'], PhpBuiltinClassHierarchy::interfacesOf('RUNTIMEEXCEPTION'));
        self::assertSame(['Traversable'], PhpBuiltinClassHierarchy::extendsOf('iteratoraggregate'));
        self::assertSame(['AllowDynamicProperties'], PhpBuiltinClassHierarchy::attributesOf('STDCLASS'));
    }

    #[Test]
    public function itDoesNotAnswerForANamePhpDoesNotDeclare(): void
    {
        self::assertNull(PhpBuiltinClassHierarchy::extendsOf('App\\Domain\\Order'));
        self::assertNull(PhpBuiltinClassHierarchy::interfacesOf('App\\Domain\\Order'));
        self::assertNull(PhpBuiltinClassHierarchy::attributesOf('App\\Domain\\Order'));
    }
}
