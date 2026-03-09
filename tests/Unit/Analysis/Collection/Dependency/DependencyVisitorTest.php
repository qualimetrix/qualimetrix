<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Collection\Dependency;

use AiMessDetector\Analysis\Collection\Dependency\DependencyResolver;
use AiMessDetector\Analysis\Collection\Dependency\DependencyVisitor;
use AiMessDetector\Core\Dependency\DependencyType;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyVisitor::class)]
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
    public function detects_extends(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\BaseClass;

class MyClass extends BaseClass {}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('App\\MyClass', $deps[0]->source->toString());
        self::assertSame('Vendor\\BaseClass', $deps[0]->target->toString());
        self::assertSame(DependencyType::Extends, $deps[0]->type);
    }

    #[Test]
    public function detects_implements(): void
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
    public function detects_trait_use(): void
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
        self::assertSame('Vendor\\SomeTrait', $deps[0]->target->toString());
        self::assertSame(DependencyType::TraitUse, $deps[0]->type);
    }

    #[Test]
    public function detects_new_instantiation(): void
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
        self::assertSame('Vendor\\SomeClass', $deps[0]->target->toString());
        self::assertSame(DependencyType::New_, $deps[0]->type);
    }

    #[Test]
    public function detects_static_call(): void
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
        self::assertSame('Vendor\\Utils', $deps[0]->target->toString());
        self::assertSame(DependencyType::StaticCall, $deps[0]->type);
    }

    #[Test]
    public function detects_static_property_fetch(): void
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
        self::assertSame('Vendor\\Config', $deps[0]->target->toString());
        self::assertSame(DependencyType::StaticPropertyFetch, $deps[0]->type);
    }

    #[Test]
    public function detects_class_const_fetch(): void
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
        self::assertSame('Vendor\\Status', $deps[0]->target->toString());
        self::assertSame(DependencyType::ClassConstFetch, $deps[0]->type);
    }

    #[Test]
    public function detects_type_hint_parameter(): void
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
        self::assertSame('Vendor\\Request', $deps[0]->target->toString());
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
    }

    #[Test]
    public function detects_type_hint_return(): void
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
        self::assertSame('Vendor\\Response', $deps[0]->target->toString());
        self::assertSame(DependencyType::TypeHint, $deps[0]->type);
    }

    #[Test]
    public function detects_catch(): void
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
        self::assertSame('Vendor\\CustomException', $deps[0]->target->toString());
        self::assertSame(DependencyType::Catch_, $deps[0]->type);
    }

    #[Test]
    public function detects_instanceof(): void
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
        self::assertSame('Vendor\\SomeClass', $deps[0]->target->toString());
        self::assertSame(DependencyType::Instanceof_, $deps[0]->type);
    }

    #[Test]
    public function detects_attribute(): void
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
        self::assertSame('Vendor\\Route', $deps[0]->target->toString());
        self::assertSame(DependencyType::Attribute, $deps[0]->type);
    }

    #[Test]
    public function detects_property_type(): void
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
        self::assertSame('Vendor\\Logger', $deps[0]->target->toString());
        self::assertSame(DependencyType::PropertyType, $deps[0]->type);
    }

    #[Test]
    public function detects_union_type(): void
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
        self::assertSame(DependencyType::UnionType, $deps[0]->type);
        self::assertSame(DependencyType::UnionType, $deps[1]->type);
    }

    #[Test]
    public function detects_intersection_type(): void
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
        self::assertSame(DependencyType::IntersectionType, $deps[0]->type);
        self::assertSame(DependencyType::IntersectionType, $deps[1]->type);
    }

    #[Test]
    public function ignores_self_static_parent(): void
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
    public function ignores_builtin_types(): void
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
    public function ignores_self_references(): void
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
    public function handles_interface_extends(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\ParentInterface;

interface MyInterface extends ParentInterface {}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\ParentInterface', $deps[0]->target->toString());
        self::assertSame(DependencyType::Extends, $deps[0]->type);
    }

    #[Test]
    public function handles_enum_implements(): void
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

        self::assertCount(1, $deps);
        self::assertSame('Vendor\\SomeInterface', $deps[0]->target->toString());
        self::assertSame(DependencyType::Implements, $deps[0]->type);
    }

    #[Test]
    public function imports_do_not_leak_between_namespace_blocks(): void
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
            static fn($d) => $d->source->toString() === 'First\\ServiceA',
        );
        self::assertCount(1, $serviceADeps);
        self::assertSame('Vendor\\Logger', array_values($serviceADeps)[0]->target->toString());

        // ServiceB should depend on Second\Logger (resolved in current namespace,
        // NOT on Vendor\Logger which was imported only in the First namespace block)
        $serviceBDeps = array_filter(
            $deps,
            static fn($d) => $d->source->toString() === 'Second\\ServiceB',
        );
        self::assertCount(1, $serviceBDeps);
        self::assertSame('Second\\Logger', array_values($serviceBDeps)[0]->target->toString());
    }

    #[Test]
    public function anonymous_class_extends_implements_attributed_to_enclosing_class(): void
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

        // Should have Extends(Foo) and Implements(Bar) attributed to App\Outer
        $outerDeps = array_filter(
            $deps,
            static fn($d) => $d->source->toString() === 'App\\Outer',
        );
        $outerDeps = array_values($outerDeps);

        self::assertCount(2, $outerDeps);

        $targets = array_map(static fn($d) => $d->target->toString(), $outerDeps);
        $types = array_map(static fn($d) => $d->type, $outerDeps);

        self::assertContains('Vendor\\Foo', $targets);
        self::assertContains('Vendor\\Bar', $targets);
        self::assertContains(DependencyType::Extends, $types);
        self::assertContains(DependencyType::Implements, $types);
    }

    #[Test]
    public function anonymous_class_without_enclosing_class_is_ignored(): void
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

    /**
     * @return array<\AiMessDetector\Core\Dependency\Dependency>
     */
    private function analyze(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);

        if ($ast === null) {
            return [];
        }

        $this->visitor->setFile('/test.php');
        $this->traverser->traverse($ast);

        return $this->visitor->getDependencies();
    }
}
