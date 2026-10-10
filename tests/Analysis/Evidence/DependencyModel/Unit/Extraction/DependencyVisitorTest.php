<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\DependencyModel\Unit\Extraction;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\TypeShape;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyLocation;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyRecorder;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Core\Ast\NameResolution;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;

#[CoversClass(DependencyVisitor::class)]
#[CoversClass(DependencyRecorder::class)]
final class DependencyVisitorTest extends TestCase
{
    private DependencyVisitor $visitor;
    private NodeTraverser $traverser;

    protected function setUp(): void
    {
        $resolver = new DependencyResolver();
        $this->visitor = new DependencyVisitor($resolver);
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor($this->visitor);
    }

    #[Test]
    public function itRecordsNamedClassSelfExtendsWithoutOrdinarySelfReferences(): void
    {
        foreach (['A', 'a'] as $parent) {
            $deps = $this->analyze('<?php namespace App; class A extends ' . $parent . ' { public function f(A $a): A { A::f($a); return $a; } }');
            $extends = array_values(array_filter($deps, static fn($edge): bool => $edge->type === DependencyType::Extends));
            self::assertCount(1, $extends);
            self::assertFalse($extends[0]->describesNestedAnonymousClass);
            self::assertFalse($extends[0]->interfaceExtends);
        }
        self::assertSame([], $this->analyze('<?php namespace App; interface A extends A {}'));
    }

    #[Test]
    public function itPreservesSelfExtendsSourcesAndOrdinalsAcrossDuplicateBodies(): void
    {
        $deps = $this->analyze("<?php\nnamespace App;\nclass A extends A {}\nif (false) { class A extends a {} }\n");
        self::assertCount(2, $deps);
        self::assertSame(0, $deps[0]->source->ordinal->value);
        self::assertSame(1, $deps[1]->source->ordinal->value);
        $graph = (new \Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder(new \Qualimetrix\Analysis\Evidence\DependencyModel\UnplacedExternalClassSpelling()))->build(array_values($deps), $this->visitor->classLikeDeclarations())->graph;
        $declarationEdges = $graph->getDeclarationDependencies();
        self::assertCount(2, $declarationEdges);
        foreach ($deps as $i => $dependency) {
            self::assertSame($dependency->source, $declarationEdges[$i]->source);
            self::assertSame($dependency->location, $declarationEdges[$i]->location);
            self::assertSame('App\\A', $declarationEdges[$i]->targetLogical()->toString());
        }
        self::assertSame([], $graph->getAllDependencies());
    }

    #[Test]
    public function itRecordsAnExtendsDependencyForAClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\BaseClass;

class MyClass extends BaseClass {}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('App\\MyClass', $deps[0]->sourceLogical()->toString());
        self::assertSame('Vendor\\BaseClass', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::Extends, $deps[0]->type);
        self::assertFalse($deps[0]->interfaceExtends, 'A parent class is not an interface the class has.');
    }

    #[Test]
    public function itRecordsADependencyForEachImplementedInterface(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\SomeInterface;
use Vendor\OtherInterface;

class MyClass implements SomeInterface, OtherInterface {}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(2, $deps);
        self::assertSame(DependencyType::Implements, $deps[0]->type);
        self::assertSame(DependencyType::Implements, $deps[1]->type);
    }

    #[Test]
    public function itRecordsATraitUseDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\SomeTrait;

class MyClass {
    use SomeTrait;
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\SomeTrait', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TraitUse, $deps[0]->type);
    }

    #[Test]
    public function itRecordsANewInstantiationDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\SomeClass;

class MyClass {
    public function test() {
        $x = new SomeClass();
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\SomeClass', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::New_, $deps[0]->type);
    }

    #[Test]
    public function itRecordsAStaticCallDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Utils;

class MyClass {
    public function test() {
        Utils::doSomething();
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\Utils', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::StaticCall, $deps[0]->type);
    }

    #[Test]
    public function itRecordsAStaticPropertyFetchDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Config;

class MyClass {
    public function test() {
        $x = Config::$value;
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\Config', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::StaticPropertyFetch, $deps[0]->type);
    }

    #[Test]
    public function itRecordsAClassConstFetchDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Status;

class MyClass {
    public function test() {
        return Status::ACTIVE;
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\Status', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::ClassConstFetch, $deps[0]->type);
    }

    #[Test]
    public function itRecordsATypeHintDependencyForAParameter(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Request;

class MyClass {
    public function handle(Request $request) {}
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\Request', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
    }

    #[Test]
    public function itRecordsATypeHintDependencyForAReturnType(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Response;

class MyClass {
    public function handle(): Response {}
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\Response', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
    }

    #[Test]
    public function itRecordsACatchDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\CustomException;

class MyClass {
    public function test() {
        try {
        } catch (CustomException $e) {}
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\CustomException', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::Catch_, $deps[0]->type);
    }

    #[Test]
    public function itRecordsAnInstanceofDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\SomeClass;

class MyClass {
    public function test($x) {
        if ($x instanceof SomeClass) {}
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\SomeClass', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::Instanceof_, $deps[0]->type);
    }

    #[Test]
    public function itRecordsAnAttributeDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Route;

#[Route('/test')]
class MyClass {}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\Route', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::Attribute, $deps[0]->type);
    }

    #[Test]
    public function itRecordsAPropertyTypeDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Logger;

class MyClass {
    private Logger $logger;
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\Logger', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::PropertyType, $deps[0]->type);
    }

    #[Test]
    public function itRecordsADependencyForEachUnionTypeMember(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\TypeA;
use Vendor\TypeB;

class MyClass {
    public function test(TypeA|TypeB $x) {}
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(2, $deps);
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
        self::assertSame(DependencyType::TypeHint, $deps[1]->type);
        self::assertSame(TypeShape::Union, $deps[0]->shape);
        self::assertSame(TypeShape::Union, $deps[1]->shape);
    }

    #[Test]
    public function itRecordsADependencyForEachIntersectionTypeMember(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\InterfaceA;
use Vendor\InterfaceB;

class MyClass {
    public function test(InterfaceA&InterfaceB $x) {}
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(2, $deps);
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
        self::assertSame(DependencyType::TypeHint, $deps[1]->type);
        self::assertSame(TypeShape::Intersection, $deps[0]->shape);
        self::assertSame(TypeShape::Intersection, $deps[1]->shape);
    }

    #[Test]
    public function itKeepsTypePositionSeparateFromUnionAndPromotedPropertyShape(): void
    {
        $deps = $this->analyze(<<<'PHP'
<?php
namespace App;
use Vendor\{First, Second, Promoted};
final class Subject {
    public function __construct(public Promoted $value) {}
    public function accept(First|Second $value): void {}
}
PHP);

        self::assertSame(
            [DependencyType::PropertyType, DependencyType::TypeHint, DependencyType::TypeHint],
            array_map(static fn($dependency): DependencyType => $dependency->type, $deps),
        );
        self::assertSame(
            [TypeShape::Single, TypeShape::Union, TypeShape::Union],
            array_map(static fn($dependency): ?TypeShape => $dependency->shape, $deps),
        );
    }

    #[Test]
    public function itDistinguishesNullableIntersectionAndDnfShapesWithoutFilteringLegalClassNames(): void
    {
        $deps = $this->analyze(<<<'PHP'
<?php
namespace App;
use Vendor\{First, Second, Third};
final class Subject {
    public function one(?First $value): void {}
    public function two(Second|null $value): void {}
    public function three(First&Second $value): void {}
    public function four((First&Second)|Third $value): void {}
    public function five(\Integer $value): void {}
}
PHP);

        self::assertSame(
            [
                TypeShape::Nullable,
                TypeShape::Nullable,
                TypeShape::Intersection,
                TypeShape::Intersection,
                TypeShape::Dnf,
                TypeShape::Dnf,
                TypeShape::Dnf,
                TypeShape::Single,
            ],
            array_map(static fn($dependency): ?TypeShape => $dependency->shape, $deps),
        );
        self::assertSame('Integer', $deps[7]->targetLogical()->toString());
    }

    #[Test]
    public function itRecordsConstantAndEnumCaseTypesAndAttributeSites(): void
    {
        $deps = $this->analyze(<<<'PHP'
<?php
namespace App;
use Vendor\{CaseAttribute, ConstantAttribute, ConstantValue, EnumValue};
final class Subject {
    #[ConstantAttribute]
    public const ConstantValue ITEM = new ConstantValue();
}
enum State {
    #[CaseAttribute]
    case Open;
    public const EnumValue VALUE = EnumValue::class;
}
PHP);

        $constantTypes = array_values(array_filter(
            $deps,
            static fn($dependency): bool => $dependency->type === DependencyType::ConstantType,
        ));
        self::assertSame(['Vendor\\ConstantValue', 'Vendor\\EnumValue'], array_map(
            static fn($dependency): string => $dependency->targetLogical()->toString(),
            $constantTypes,
        ));

        $attributes = array_values(array_filter(
            $deps,
            static fn($dependency): bool => $dependency->type === DependencyType::Attribute,
        ));
        self::assertSame(
            [AttributeSite::ClassConstant, AttributeSite::EnumCase],
            array_map(static fn($dependency): ?AttributeSite => $dependency->attributeSite, $attributes),
        );
    }

    #[Test]
    public function itRecordsNestedFunctionSignatureAndItsAttributeSites(): void
    {
        $deps = $this->analyze(<<<'PHP'
<?php
namespace App;
use Vendor\{FunctionAttribute, ParameterAttribute, ParamType, ReturnType};
final class Subject {
    public function outer(): void {
        #[FunctionAttribute]
        function inner(#[ParameterAttribute] ParamType $value): ReturnType {}
    }
}
PHP);

        self::assertSame(
            ['Vendor\\FunctionAttribute', 'Vendor\\ParamType', 'Vendor\\ParameterAttribute', 'Vendor\\ReturnType'],
            array_map(static fn($dependency): string => $dependency->targetLogical()->toString(), $deps),
        );
        self::assertSame(AttributeSite::NestedFunction, $deps[0]->attributeSite);
        self::assertSame(AttributeSite::NestedFunction, $deps[2]->attributeSite);
        self::assertFalse($deps[2]->attributeSite->isDeclaredMember());
    }

    #[Test]
    public function itRecordsPropertyHookAndHookParameterFacts(): void
    {
        $deps = $this->analyze(<<<'PHP'
<?php
namespace App;
use Vendor\{HookAttribute, HookParameterAttribute, HookValue};
final class Subject {
    public string $value {
        #[HookAttribute]
        set(#[HookParameterAttribute] HookValue $value) {}
    }
}
PHP);

        self::assertSame(
            ['Vendor\\HookAttribute', 'Vendor\\HookValue', 'Vendor\\HookParameterAttribute'],
            array_map(static fn($dependency): string => $dependency->targetLogical()->toString(), $deps),
        );
        self::assertSame(AttributeSite::PropertyHook, $deps[0]->attributeSite);
        self::assertSame(DependencyType::TypeHint, $deps[1]->type);
        self::assertSame(TypeShape::Single, $deps[1]->shape);
        self::assertSame(AttributeSite::HookParameter, $deps[2]->attributeSite);
    }

    #[Test]
    public function itPublishesNamedClassLikeFactsWithoutSyntheticStringableEdges(): void
    {
        $this->analyze(<<<'PHP'
<?php
namespace App;
trait Formatting { use Helper { format as __TOSTRING; } }
final class Label { public function __toString(): string { return ''; } }
interface Named { public function __toString(): string; }
enum State { case Open; }
PHP);

        $facts = $this->visitor->classLikeDeclarations();
        self::assertSame(
            [ClassType::Trait_, ClassType::Class_, ClassType::Interface_, ClassType::Enum_],
            array_map(static fn($fact): ClassType => $fact->type, $facts),
        );
        self::assertTrue($facts[0]->aliasesTraitMethodAsToString);
        self::assertFalse($facts[0]->declaresToString);
        self::assertTrue($facts[1]->declaresToString);
        self::assertTrue($facts[2]->declaresToString);
        self::assertFalse($facts[3]->declaresToString);
        self::assertSame([], array_values(array_filter(
            $this->visitor->dependencies(),
            static fn($dependency): bool => $dependency->targetLogical()->toString() === 'Stringable',
        )));
    }

    #[Test]
    public function itDoesNotAttributeAnAnonymousClassToStringMethodToItsNamedOwner(): void
    {
        $this->analyze(<<<'PHP'
<?php
namespace App;
final class Host {
    public function make(): object {
        return new class {
            public function __toString(): string { return ''; }
        };
    }
}
PHP);

        $facts = $this->visitor->classLikeDeclarations();
        self::assertCount(1, $facts);
        self::assertSame('App\\Host', $facts[0]->declaration->logical->toString());
        self::assertFalse($facts[0]->declaresToString);
        self::assertFalse($facts[0]->aliasesTraitMethodAsToString);
    }

    #[Test]
    public function itMarksAnAttributeOnANestedNamedClassAsNested(): void
    {
        $deps = $this->analyze(<<<'PHP'
<?php
namespace App;
use Vendor\NestedAttribute;
final class Host {
    public function define(): void {
        #[NestedAttribute]
        class Nested {}
    }
}
PHP);

        self::assertCount(1, $deps);
        self::assertSame(AttributeSite::NestedClass, $deps[0]->attributeSite);
        self::assertSame('App\\Nested', $deps[0]->sourceLogical()->toString());
    }

    #[Test]
    public function itIgnoresSelfStaticAndParentReferences(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public function test() {
        self::foo();
        static::bar();
        parent::baz();
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(0, $deps);
    }

    #[Test]
    public function itIgnoresBuiltinScalarAndArrayTypes(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public function test(int $a, string $b, array $c): void {}
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(0, $deps);
    }

    #[Test]
    public function itIgnoresAReturnTypeReferencingTheEnclosingClassItself(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public function test(): MyClass {}
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(0, $deps);
    }

    #[Test]
    public function itRecordsAnExtendsDependencyForAnInterface(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\ParentInterface;

interface MyInterface extends ParentInterface {}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\ParentInterface', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::Extends, $deps[0]->type);
        self::assertTrue($deps[0]->interfaceExtends);
    }

    #[Test]
    public function itRecordsAnImplementsDependencyForAnEnum(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\SomeInterface;

enum Status: string implements SomeInterface {
    case Active = 'active';
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(3, $deps);
        self::assertSame('Vendor\\SomeInterface', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::Implements, $deps[0]->type);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function provideClassLikesPhpGivesAnInterfaceUnwritten(): iterable
    {
        yield 'enum' => ['enum Phase { case Open; }', ['UnitEnum']];
        yield 'backed enum' => ["enum Phase: string { case Open = 'open'; }", ['UnitEnum', 'BackedEnum']];
        yield 'class declaring __toString' => ["class Label { public function __toString(): string { return ''; } }", []];
        yield 'method name in another case' => ["class Label { public function __TOSTRING(): string { return ''; } }", []];
        yield 'interface declaring __toString' => ['interface Named { public function __toString(): string; }', []];
        yield 'trait declaring __toString' => ["trait Printable { public function __toString(): string { return ''; } }", []];
        yield 'class without __toString' => ['class Silent {}', []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideClassLikesPhpGivesAnInterfaceUnwritten')]
    public function itRecordsTheInterfacesPhpGivesAClassLikeUnwritten(string $declaration, array $expected): void
    {
        $deps = $this->analyze("<?php\nnamespace App;\n" . $declaration . "\n");

        self::assertSame($expected, array_map(
            static fn($dependency): string => $dependency->targetLogical()->toString(),
            $deps,
        ));
        foreach ($deps as $dependency) {
            self::assertSame(DependencyType::Implements, $dependency->type);
        }
    }

    #[Test]
    public function itDoesNotInventAStringableEdgeForAnAnonymousClass(): void
    {
        $deps = $this->analyze(<<<'PHP'
<?php
namespace App;
final class Host
{
    public function make(): object
    {
        return new class {
            public function __toString(): string { return ''; }
        };
    }
}
PHP);

        $stringable = array_values(array_filter(
            $deps,
            static fn($dependency): bool => $dependency->targetLogical()->toString() === 'Stringable',
        ));
        self::assertSame([], $stringable);
    }

    #[Test]
    public function itPreservesExactDeclarationSourcesForEveryNamedClassLike(): void
    {
        $deps = $this->analyze(<<<'PHP'
<?php
namespace App;
use Vendor\Base;
use Vendor\Contract;
use Vendor\SharedTrait;
class Service extends Base {}
interface Port extends Contract {}
trait Shared { use SharedTrait; }
enum State implements Contract {}
PHP);

        $sources = array_values(array_unique(array_map(
            static fn($dependency): string => $dependency->sourceLogical()->toString(),
            $deps,
        )));
        sort($sources);
        self::assertSame(['App\Port', 'App\Service', 'App\Shared', 'App\State'], $sources);
        foreach ($deps as $dependency) {
            self::assertStringContainsString('@test.php', $dependency->source->toCanonical());
        }
    }

    #[Test]
    public function itResetsDependenciesAndImportsWhenReusedForAnotherFile(): void
    {
        $this->analyze('<?php namespace First; use Vendor\One; class A extends One {}', 'first.php');
        $second = $this->analyze('<?php namespace Second; class B extends Two {}', 'second.php');

        self::assertCount(1, $second);
        self::assertSame('Second\B', $second[0]->sourceLogical()->toString());
        self::assertSame('Second\Two', $second[0]->targetLogical()->toString());
        self::assertStringContainsString('@second.php', $second[0]->source->toCanonical());
    }

    #[Test]
    public function itKeepsImportsScopedToTheirOwnNamespaceBlock(): void
    {
        $code = <<<'PHP'
<?php
namespace First {
    use Vendor\Logger;

    class ServiceA {
        private Logger $logger;
    }
}

namespace Second {
    class ServiceB {
        private Logger $logger;
    }
}
PHP;
        $deps = $this->analyze($code);

        // ServiceA should depend on Vendor\Logger (imported)
        $serviceADeps = array_filter(
            $deps,
            static fn($d) => $d->sourceLogical()->toString() === 'First\\ServiceA',
        );
        self::assertCount(1, $serviceADeps);
        self::assertSame('Vendor\\Logger', array_values($serviceADeps)[0]->targetLogical()->toString());

        // ServiceB should depend on Second\Logger (resolved in current namespace,
        // NOT on Vendor\Logger which was imported only in the First namespace block)
        $serviceBDeps = array_filter(
            $deps,
            static fn($d) => $d->sourceLogical()->toString() === 'Second\\ServiceB',
        );
        self::assertCount(1, $serviceBDeps);
        self::assertSame('Second\\Logger', array_values($serviceBDeps)[0]->targetLogical()->toString());
    }

    #[Test]
    public function itFlagsAnonymousClassExtendsAndImplementsAsDeclarationFactsOfTheAnonymousClassNotTheEnclosingClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Foo;
use Vendor\Bar;

class Outer {
    public function factory() {
        return new class extends Foo implements Bar {
            public function inner() {}
        };
    }
}
PHP;
        $deps = $this->analyze($code);

        // The edges still exist, attributed to App\Outer — new class extends L1
        // produces no separate New_ edge (New_ only fires for a Name target),
        // so this is the sole record that Outer references Foo and Bar at all.
        $outerDeps = array_values(array_filter(
            $deps,
            static fn($d) => $d->sourceLogical()->toString() === 'App\\Outer',
        ));

        self::assertCount(2, $outerDeps);

        $targets = array_map(static fn($d) => $d->targetLogical()->toString(), $outerDeps);
        $types = array_map(static fn($d) => $d->type, $outerDeps);

        self::assertContains('Vendor\\Foo', $targets);
        self::assertContains('Vendor\\Bar', $targets);
        self::assertContains(DependencyType::Extends, $types);
        self::assertContains(DependencyType::Implements, $types);

        // But neither is a declaration fact of Outer itself — both describe
        // the anonymous class nested inside it (Outer has no name it could
        // hand to `extends`/`implements`, since it does not declare them).
        foreach ($outerDeps as $dependency) {
            self::assertTrue(
                $dependency->describesNestedAnonymousClass,
                \sprintf('Expected the %s edge to Vendor\\%s to be flagged as a nested anonymous declaration', $dependency->type->value, $dependency->targetLogical()->toString()),
            );
        }
    }

    #[Test]
    public function itFlagsAnAttributeOnAnAnonymousClassHeaderAsADeclarationFactOfTheAnonymousClassNotTheEnclosingClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Mark;

class Outer {
    public function factory() {
        return new #[Mark] class {};
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('App\\Outer', $deps[0]->sourceLogical()->toString());
        self::assertSame('Vendor\\Mark', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::Attribute, $deps[0]->type);
        self::assertTrue($deps[0]->describesNestedAnonymousClass);
    }

    #[Test]
    #[DataProvider('provideAnonymousAttributeSites')]
    public function itPreservesAnonymousProvenanceForEveryAttributeSite(string $member, AttributeSite $site): void
    {
        $deps = $this->analyze(<<<PHP
<?php
namespace App;
class Host {
    #[OwnBefore]
    public const BEFORE = 1;
    public function factory(): object {
        return new class {
            $member
            public function nested(): object {
                return new class { $member };
            }
        };
    }
    #[OwnAfter]
    public const AFTER = 2;
}
PHP);

        self::assertCount(4, $deps);
        self::assertSame(
            ['App\\OwnBefore', 'App\\NestedMark', 'App\\NestedMark', 'App\\OwnAfter'],
            array_map(static fn($dependency): string => $dependency->targetLogical()->toString(), $deps),
        );
        self::assertSame(
            [AttributeSite::ClassConstant, $site, $site, AttributeSite::ClassConstant],
            array_map(static fn($dependency): ?AttributeSite => $dependency->attributeSite, $deps),
        );
        self::assertSame(
            [false, true, true, false],
            array_map(static fn($dependency): bool => $dependency->describesNestedAnonymousClass, $deps),
        );
        foreach ($deps as $dependency) {
            self::assertSame('App\\Host', $dependency->sourceLogical()->toString());
            self::assertSame(DependencyType::Attribute, $dependency->type);
        }
    }

    /** @return iterable<string, array{string, AttributeSite}> */
    public static function provideAnonymousAttributeSites(): iterable
    {
        yield 'method' => ['#[NestedMark] public function marked(): void {}', AttributeSite::Method];
        yield 'property' => ['#[NestedMark] public string $marked;', AttributeSite::Property];
        yield 'parameter' => ['public function marked(#[NestedMark] string $value): void {}', AttributeSite::Parameter];
        yield 'promoted parameter' => ['public function __construct(#[NestedMark] public string $value) {}', AttributeSite::PromotedParameter];
        yield 'constant' => ['#[NestedMark] public const VALUE = 1;', AttributeSite::ClassConstant];
        yield 'property hook' => ['public string $marked { #[NestedMark] set(string $value) {} }', AttributeSite::PropertyHook];
        yield 'hook parameter' => ['public string $marked { set(#[NestedMark] string $value) {} }', AttributeSite::HookParameter];
        yield 'nested callable' => ['public function marked(): void { $callable = #[NestedMark] function (): void {}; }', AttributeSite::NestedCallable];
        yield 'nested function' => ['public function marked(): void { #[NestedMark] function inner(): void {} }', AttributeSite::NestedFunction];
    }

    #[Test]
    public function itFlagsATraitUseInsideAnAnonymousClassBodyAsADeclarationFactOfTheAnonymousClassNotTheEnclosingClass(): void
    {
        // The trait_use edge reaches TraitUseHandler through a different
        // path than extends/implements/attributes (dispatchInCurrentContext,
        // not ClassLikeHandler) — a separate fixture guards it.
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\SomeTrait;

class Outer {
    public function factory() {
        return new class {
            use SomeTrait;
        };
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('App\\Outer', $deps[0]->sourceLogical()->toString());
        self::assertSame('Vendor\\SomeTrait', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TraitUse, $deps[0]->type);
        self::assertTrue($deps[0]->describesNestedAnonymousClass);
    }

    #[Test]
    public function itFlagsATraitUseInAnAnonymousClassNestedTwoLevelsDeepInsideAnotherAnonymousClass(): void
    {
        // An anonymous class nested inside another anonymous class must
        // still be recognised as anonymous at depth 2, not just depth 1.
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\SomeTrait;

class Outer {
    public function factory() {
        return new class {
            public function inner() {
                return new class {
                    use SomeTrait;
                };
            }
        };
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('App\\Outer', $deps[0]->sourceLogical()->toString());
        self::assertSame('Vendor\\SomeTrait', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TraitUse, $deps[0]->type);
        self::assertTrue($deps[0]->describesNestedAnonymousClass);
    }

    #[Test]
    public function itLeavesUsageDependenciesFromInsideAnAnonymousClassBodyUnflagged(): void
    {
        // Usage edges (new, type hints, ...) reached while walking an
        // anonymous class's own body are not declaration facts of anything —
        // they must stay unflagged even though the depth counter is > 0.
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Collaborator;

class Outer {
    public function factory() {
        return new class {
            public function inner(): void {
                new Collaborator();
            }
        };
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('App\\Outer', $deps[0]->sourceLogical()->toString());
        self::assertSame('Vendor\\Collaborator', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::New_, $deps[0]->type);
        self::assertFalse($deps[0]->describesNestedAnonymousClass);
    }

    #[Test]
    public function itFlagsAnAnonymousClassExtendingAPhpBuiltinClassAsANestedAnonymousDeclaration(): void
    {
        // DependencyGraphBuilder retains a builtin-parent edge only when its
        // type is Extends — this is the sole record that the enclosing class
        // references the builtin at all, so the flag must not change the type.
        $code = <<<'PHP'
<?php
namespace App;

class Outer {
    public function factory() {
        return new class extends \stdClass {};
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('App\\Outer', $deps[0]->sourceLogical()->toString());
        self::assertSame('stdClass', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::Extends, $deps[0]->type);
        self::assertTrue($deps[0]->describesNestedAnonymousClass);
    }

    #[Test]
    public function itIgnoresAnAnonymousClassWithoutAnEnclosingNamedClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Foo;

// Anonymous class outside any named class
$obj = new class extends Foo {};
PHP;
        $deps = $this->analyze($code);

        // No enclosing class context, so dependencies are not tracked
        self::assertCount(0, $deps);
    }

    #[Test]
    public function itDetectsTypeHintOnClosureParameter(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\TargetA;

class MyClass {
    public function test() {
        $fn = function (TargetA $a): void {};
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\TargetA', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
    }

    #[Test]
    public function itDetectsTypeHintOnClosureReturnType(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\TargetB;

class MyClass {
    public function test() {
        $fn = function (): TargetB {};
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\TargetB', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
    }

    #[Test]
    public function itDetectsTypeHintOnArrowFunctionParameter(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\TargetC;

class MyClass {
    public function test() {
        $fn = fn(TargetC $c): int => 1;
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\TargetC', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
    }

    #[Test]
    public function itDetectsTypeHintOnArrowFunctionReturnType(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\TargetD;

class MyClass {
    public function test() {
        $fn = fn(): TargetD => null;
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\TargetD', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
    }

    #[Test]
    public function itDetectsAttributesOnClosureParameters(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\{ClosureParamAttribute, ArrowParamAttribute};

class MyClass {
    public function test() {
        $closure = function (#[ClosureParamAttribute] $a) {};
        $arrow = fn(#[ArrowParamAttribute] $a) => $a;
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertSame(
            ['Vendor\\ClosureParamAttribute', 'Vendor\\ArrowParamAttribute'],
            array_map(static fn($dependency): string => $dependency->targetLogical()->toString(), $deps),
        );
        self::assertSame(
            [DependencyType::Attribute, DependencyType::Attribute],
            array_map(static fn($dependency): DependencyType => $dependency->type, $deps),
        );
        self::assertSame(
            [AttributeSite::NestedCallable, AttributeSite::NestedCallable],
            array_map(static fn($dependency): ?AttributeSite => $dependency->attributeSite, $deps),
        );
        self::assertFalse($deps[0]->attributeSite?->isDeclaredMember());
        self::assertFalse($deps[1]->attributeSite?->isDeclaredMember());
    }

    #[Test]
    public function itStillDetectsInstantiationInsideClosureBody(): void
    {
        // Regression guard: closure bodies were already traversed correctly
        // before this fix — only param/return signatures were missed.
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\TargetC;

class MyClass {
    public function test() {
        $fn = function (): void { new TargetC(); };
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\TargetC', $deps[0]->targetLogical()->toString());
        self::assertSame(DependencyType::New_, $deps[0]->type);
    }

    #[Test]
    public function itIgnoresClosureSignatureOutsideAnyEnclosingClass(): void
    {
        // Documents current behavior for closures at file top level (no
        // enclosing class): out of scope per task — global-function-style
        // ownerless dependencies are a separate design problem. This test
        // pins the existing (no crash, no orphan dependency) behavior.
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\Foo;

$fn = function (Foo $f): void {};
PHP;
        $deps = $this->analyze($code);

        self::assertCount(0, $deps);
    }

    #[Test]
    public function itReturnsDependencyModelOwnedLocationsForExtractedDependencies(): void
    {
        $dependencies = $this->analyze(<<<'PHP'
<?php
namespace App;

use Vendor\Base;

final class Subject extends Base {}
PHP, 'src/Subject.php');

        self::assertCount(1, $dependencies);
        self::assertInstanceOf(DependencyLocation::class, $dependencies[0]->location);
        self::assertSame('src/Subject.php:6', $dependencies[0]->location->toString());
    }

    /**
     * @return array<\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency>
     */
    private function analyze(string $code, string $file = 'test.php'): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);

        if ($ast === null) {
            return [];
        }

        NameResolution::resolve($ast);

        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor($registrar);
        $this->traverser->addVisitor($this->visitor);
        $this->visitor->beginFile(RelativePath::fromString($file), $registrar->index());
        $this->traverser->traverse($ast);

        return $this->visitor->dependencies();
    }
}
