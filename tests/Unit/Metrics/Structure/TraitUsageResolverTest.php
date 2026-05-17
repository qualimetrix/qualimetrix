<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Structure;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Structure\TraitUsageResolver;
use Qualimetrix\Metrics\Structure\UnusedPrivateClassData;

#[CoversClass(TraitUsageResolver::class)]
final class TraitUsageResolverTest extends TestCase
{
    #[Test]
    public function itRecordsMethodCallsFromSimpleTraitUsage(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    public function work(): void
    {
        $this->helper();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        self::assertCount(1, $traitDefs);
        self::assertArrayHasKey('App\MyTrait', $traitDefs);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);

        // Simulate: class MyClass { use MyTrait; }
        $classStmts = [new TraitUse([new Name('MyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('helper', $data->usedMethods);
    }

    #[Test]
    public function itRecordsPropertyAndConstantFromTraitUsage(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    public function work(): void
    {
        $x = $this->secret;
        $y = self::VALUE;
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);
        $data = new UnusedPrivateClassData('App', 'MyClass', 1);

        $classStmts = [new TraitUse([new Name('MyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('secret', $data->usedProperties);
        self::assertArrayHasKey('VALUE', $data->usedConstants);
    }

    #[Test]
    public function itResolvesNestedTraitUsage(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait TraitA
{
    use TraitB;

    public function workA(): void {}
}

trait TraitB
{
    public function workB(): void
    {
        $this->deepHelper();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        self::assertCount(2, $traitDefs);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('TraitA')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        // deepHelper is called from TraitB, which is used by TraitA
        self::assertArrayHasKey('deepHelper', $data->usedMethods);
    }

    #[Test]
    public function itDetectsCyclesAndPreventsInfiniteRecursion(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait TraitA
{
    use TraitB;

    public function workA(): void
    {
        $this->helperA();
    }
}

trait TraitB
{
    use TraitA;

    public function workB(): void
    {
        $this->helperB();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('TraitA')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');

        // Should not stack overflow — cycle detection kicks in
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('helperA', $data->usedMethods);
        self::assertArrayHasKey('helperB', $data->usedMethods);
    }

    #[Test]
    public function itResolvesMultipleTraitsOnSameClass(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait TraitA
{
    public function workA(): void
    {
        $this->fromA();
    }
}

trait TraitB
{
    public function workB(): void
    {
        $this->fromB();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('TraitA'), new Name('TraitB')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('fromA', $data->usedMethods);
        self::assertArrayHasKey('fromB', $data->usedMethods);
    }

    #[Test]
    public function itSkipsTraitNotFoundInClassMap(): void
    {
        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('NonExistent')])];

        $resolver = new TraitUsageResolver([], 'App');
        $resolver->resolve($classStmts, $data);

        self::assertSame([], $data->usedMethods);
        self::assertSame([], $data->usedProperties);
        self::assertSame([], $data->usedConstants);
    }

    #[Test]
    public function itDoesNothingForEmptyClassStatements(): void
    {
        $data = new UnusedPrivateClassData('App', 'MyClass', 1);

        $resolver = new TraitUsageResolver([], 'App');
        $resolver->resolve([], $data);

        self::assertSame([], $data->usedMethods);
    }

    #[Test]
    public function itHandlesEmptyTraitWithNoTraitUseStatements(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait EmptyTrait
{
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('EmptyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        // Empty trait has no methods to scan — no usages recorded
        self::assertSame([], $data->usedMethods);
    }

    #[Test]
    public function itResolvesThreeLevelsDeepTraitHierarchy(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait TraitA
{
    use TraitB;

    public function a(): void
    {
        $this->fromA();
    }
}

trait TraitB
{
    use TraitC;

    public function b(): void
    {
        $this->fromB();
    }
}

trait TraitC
{
    public function c(): void
    {
        $this->fromC();
        self::staticFromC();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        self::assertCount(3, $traitDefs);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('TraitA')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('fromA', $data->usedMethods);
        self::assertArrayHasKey('fromB', $data->usedMethods);
        self::assertArrayHasKey('fromC', $data->usedMethods);
        self::assertArrayHasKey('staticFromC', $data->usedMethods);
    }

    #[Test]
    public function itLooksUpTraitByFullyQualifiedName(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    public function work(): void
    {
        $this->found();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        // Use the fully qualified name directly
        $classStmts = [new TraitUse([new Name('App\MyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('found', $data->usedMethods);
    }

    #[Test]
    public function itLooksUpTraitByShortName(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Sub;

trait MyTrait
{
    public function work(): void
    {
        $this->found();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('Other', 'MyClass', 1);
        // Short name that doesn't match namespace but matches the last segment
        $classStmts = [new TraitUse([new Name('MyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'Other');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('found', $data->usedMethods);
    }

    #[Test]
    public function itHandlesTraitWithoutNamespace(): void
    {
        $code = <<<'PHP'
<?php

trait GlobalTrait
{
    public function work(): void
    {
        $this->helper();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        self::assertArrayHasKey('GlobalTrait', $traitDefs);

        $data = new UnusedPrivateClassData(null, 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('GlobalTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, null);
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('helper', $data->usedMethods);
    }

    #[Test]
    public function itSkipsAnonymousTraitNamesWhenCollecting(): void
    {
        // Trait_ with null name should be skipped
        $traitNode = new Trait_('TemporaryTrait');
        $traitNode->name = null;
        $definitions = TraitUsageResolver::collectTraitDefinitions([$traitNode]);

        self::assertSame([], $definitions);
    }

    #[Test]
    public function itReturnsEmptyDefinitionsForEmptyAst(): void
    {
        $definitions = TraitUsageResolver::collectTraitDefinitions([]);

        self::assertSame([], $definitions);
    }

    #[Test]
    public function itSkipsAbstractMethodsInTrait(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    abstract public function mustImplement(): void;

    public function work(): void
    {
        $this->helper();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('MyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        // Abstract method has no body (stmts === null), should be skipped gracefully
        // But concrete method's usages should still be recorded
        self::assertArrayHasKey('helper', $data->usedMethods);
    }

    #[Test]
    public function itRecordsStaticMethodPropertyAndConstantUsagesFromTrait(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    public function work(): void
    {
        self::staticMethod();
        $x = static::$staticProp;
        $y = self::MY_CONST;
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('MyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('staticMethod', $data->usedMethods);
        self::assertArrayHasKey('staticProp', $data->usedProperties);
        self::assertArrayHasKey('MY_CONST', $data->usedConstants);
    }

    #[Test]
    public function itIgnoresNonTraitUseStatements(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    public function work(): void
    {
        $this->helper();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);

        // Mix TraitUse with a non-TraitUse statement (e.g., a class method node)
        $nonTraitStmt = new \PhpParser\Node\Stmt\Nop();
        $classStmts = [$nonTraitStmt, new TraitUse([new Name('MyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('helper', $data->usedMethods);
    }

    #[Test]
    public function itDoesNotTrackSelfClassAsConstant(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait MyTrait
{
    public function work(): string
    {
        return self::class;
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        $classStmts = [new TraitUse([new Name('MyTrait')])];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        // self::class should NOT be treated as a constant reference
        self::assertSame([], $data->usedConstants);
    }

    #[Test]
    public function itHandlesMultipleSeparateTraitUseStatements(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

trait TraitA
{
    public function a(): void
    {
        $this->fromA();
    }
}

trait TraitB
{
    public function b(): void
    {
        $this->fromB();
    }
}
PHP;

        $ast = $this->parse($code);
        $traitDefs = TraitUsageResolver::collectTraitDefinitions($ast);

        $data = new UnusedPrivateClassData('App', 'MyClass', 1);
        // Two separate TraitUse statements (like: use TraitA; use TraitB;)
        $classStmts = [
            new TraitUse([new Name('TraitA')]),
            new TraitUse([new Name('TraitB')]),
        ];

        $resolver = new TraitUsageResolver($traitDefs, 'App');
        $resolver->resolve($classStmts, $data);

        self::assertArrayHasKey('fromA', $data->usedMethods);
        self::assertArrayHasKey('fromB', $data->usedMethods);
    }

    /**
     * @return \PhpParser\Node[]
     */
    private function parse(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();

        return $parser->parse($code) ?? [];
    }
}
