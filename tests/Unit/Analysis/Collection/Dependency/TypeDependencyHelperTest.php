<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Collection\Dependency;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\DependencyResolver;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Analysis\Collection\Dependency\Handler\TypeDependencyHelper;

#[CoversClass(TypeDependencyHelper::class)]
final class TypeDependencyHelperTest extends TestCase
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
    public function selfTypeHintDoesNotProduceDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public function create(): self {
        return new self();
    }
}
PHP;
        $deps = $this->analyze($code);

        // self should not produce a dependency like App\self
        self::assertCount(0, $deps);
    }

    #[Test]
    public function staticTypeHintDoesNotProduceDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public static function create(): static {
        return new static();
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(0, $deps);
    }

    #[Test]
    public function parentTypeHintDoesNotProduceDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\BaseClass;

class MyClass extends BaseClass {
    public function getParent(): parent {
        return parent::create();
    }
}
PHP;
        $deps = $this->analyze($code);

        // Should only have the Extends dependency, not a "parent" type hint dependency
        $targets = array_map(static fn($d) => $d->target->toString(), $deps);
        self::assertNotContains('App\\parent', $targets);
        self::assertNotContains('App\\static', $targets);
        self::assertNotContains('App\\self', $targets);
    }

    #[Test]
    public function selfInParameterTypeHintDoesNotProduceDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public function compare(self $other): bool {
        return true;
    }
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(0, $deps);
    }

    #[Test]
    public function selfInPropertyTypeHintDoesNotProduceDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    private ?self $instance = null;
}
PHP;
        $deps = $this->analyze($code);

        self::assertCount(0, $deps);
    }

    #[Test]
    public function selfInUnionTypeDoesNotProduceDependency(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Vendor\OtherClass;

class MyClass {
    public function test(): self|OtherClass {
        return $this;
    }
}
PHP;
        $deps = $this->analyze($code);

        // Should only have OtherClass dependency, not self
        $targets = array_map(static fn($d) => $d->target->toString(), $deps);
        self::assertContains('Vendor\\OtherClass', $targets);
        self::assertNotContains('App\\self', $targets);
    }

    /**
     * @return array<\Qualimetrix\Core\Dependency\Dependency>
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
