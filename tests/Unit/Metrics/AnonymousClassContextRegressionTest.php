<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics;

use AiMessDetector\Analysis\Collection\Dependency\DependencyResolver;
use AiMessDetector\Analysis\Collection\Dependency\DependencyVisitor;
use AiMessDetector\Metrics\Complexity\CognitiveComplexityVisitor;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityVisitor;
use AiMessDetector\Metrics\Complexity\NpathComplexityVisitor;
use AiMessDetector\Metrics\Design\TypeCoverageVisitor;
use AiMessDetector\Metrics\Halstead\HalsteadVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for anonymous class context handling in visitors.
 *
 * Bug: Several visitors use a scalar $currentClass property instead of a stack.
 * When an anonymous class appears inside a method of an outer class:
 * 1. $currentClass is set to outer class name
 * 2. Entering anonymous class — extractClassLikeName() returns null (skipped)
 * 3. Leaving anonymous class — leaveNode sets $currentClass = null (via isClassLikeNode())
 * 4. Methods AFTER the anonymous class get $currentClass = null → wrong FQN
 */
#[Group('regression')]
final class AnonymousClassContextRegressionTest extends TestCase
{
    private function getFixtureCode(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/AnonymousClassContext.php');
    }

    private function parseAndTraverse(NodeVisitorAbstract $visitor): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($this->getFixtureCode()) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    // ──────────────────────────────────────────────────────────────────
    // CyclomaticComplexityVisitor
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function cyclomatic_visitor_preserves_class_context_after_anonymous_class(): void
    {
        $visitor = new CyclomaticComplexityVisitor();
        $this->parseAndTraverse($visitor);

        $methods = $visitor->getMethodsWithMetrics();
        $afterAnonymous = $this->findMethodByName($methods, 'afterAnonymous');

        self::assertNotNull($afterAnonymous, 'afterAnonymous method should be found');
        self::assertSame(
            'OuterClass',
            $afterAnonymous->class,
            'afterAnonymous should belong to OuterClass, not null (anonymous class leaked scope)',
        );
    }

    #[Test]
    public function cyclomatic_visitor_correct_fqn_after_anonymous_class(): void
    {
        $visitor = new CyclomaticComplexityVisitor();
        $this->parseAndTraverse($visitor);

        $complexities = $visitor->getComplexities();

        // The FQN should include OuterClass, not be "::afterAnonymous"
        self::assertArrayHasKey(
            'App\Service\OuterClass::afterAnonymous',
            $complexities,
            'afterAnonymous should have FQN with OuterClass',
        );

        // CCN = 1 (base) + 1 (if) = 2
        self::assertSame(2, $complexities['App\Service\OuterClass::afterAnonymous']);
    }

    #[Test]
    public function cyclomatic_visitor_before_method_unaffected(): void
    {
        $visitor = new CyclomaticComplexityVisitor();
        $this->parseAndTraverse($visitor);

        $complexities = $visitor->getComplexities();

        self::assertArrayHasKey(
            'App\Service\OuterClass::beforeAnonymous',
            $complexities,
            'beforeAnonymous should have correct FQN',
        );
        self::assertSame(1, $complexities['App\Service\OuterClass::beforeAnonymous']);
    }

    // ──────────────────────────────────────────────────────────────────
    // NpathComplexityVisitor
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function npath_visitor_preserves_class_context_after_anonymous_class(): void
    {
        $visitor = new NpathComplexityVisitor();
        $this->parseAndTraverse($visitor);

        $methods = $visitor->getMethodsWithMetrics();
        $afterAnonymous = $this->findMethodByName($methods, 'afterAnonymous');

        self::assertNotNull($afterAnonymous, 'afterAnonymous method should be found');
        self::assertSame(
            'OuterClass',
            $afterAnonymous->class,
            'afterAnonymous should belong to OuterClass, not null (anonymous class leaked scope)',
        );
    }

    #[Test]
    public function npath_visitor_correct_fqn_after_anonymous_class(): void
    {
        $visitor = new NpathComplexityVisitor();
        $this->parseAndTraverse($visitor);

        $npath = $visitor->getNpath();

        self::assertArrayHasKey(
            'App\Service\OuterClass::afterAnonymous',
            $npath,
            'afterAnonymous should have FQN with OuterClass',
        );

        // NPath: NPath(cond: 0) + NPath(then: 1) + 1 (skip-path) = 2
        self::assertSame(2, $npath['App\Service\OuterClass::afterAnonymous']);
    }

    // ──────────────────────────────────────────────────────────────────
    // HalsteadVisitor
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function halstead_visitor_preserves_class_context_after_anonymous_class(): void
    {
        $visitor = new HalsteadVisitor();
        $this->parseAndTraverse($visitor);

        $methods = $visitor->getMethodsWithMetrics();
        $afterAnonymous = $this->findMethodByName($methods, 'afterAnonymous');

        self::assertNotNull($afterAnonymous, 'afterAnonymous method should be found');
        self::assertSame(
            'OuterClass',
            $afterAnonymous->class,
            'afterAnonymous should belong to OuterClass, not null (anonymous class leaked scope)',
        );
    }

    #[Test]
    public function halstead_visitor_correct_fqn_after_anonymous_class(): void
    {
        $visitor = new HalsteadVisitor();
        $this->parseAndTraverse($visitor);

        $metrics = $visitor->getMetrics();

        self::assertArrayHasKey(
            'App\Service\OuterClass::afterAnonymous',
            $metrics,
            'afterAnonymous should have FQN with OuterClass',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // CognitiveComplexityVisitor
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function cognitive_visitor_preserves_class_context_after_anonymous_class(): void
    {
        $visitor = new CognitiveComplexityVisitor();
        $this->parseAndTraverse($visitor);

        $methods = $visitor->getMethodsWithMetrics();
        $afterAnonymous = $this->findMethodByName($methods, 'afterAnonymous');

        self::assertNotNull($afterAnonymous, 'afterAnonymous method should be found');
        self::assertSame(
            'OuterClass',
            $afterAnonymous->class,
            'afterAnonymous should belong to OuterClass, not null (anonymous class leaked scope)',
        );
    }

    #[Test]
    public function cognitive_visitor_correct_fqn_after_anonymous_class(): void
    {
        $visitor = new CognitiveComplexityVisitor();
        $this->parseAndTraverse($visitor);

        $complexities = $visitor->getComplexities();

        self::assertArrayHasKey(
            'App\Service\OuterClass::afterAnonymous',
            $complexities,
            'afterAnonymous should have FQN with OuterClass',
        );

        // Cognitive: +1 for the if statement
        self::assertSame(1, $complexities['App\Service\OuterClass::afterAnonymous']);
    }

    // ──────────────────────────────────────────────────────────────────
    // TypeCoverageVisitor
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function type_coverage_visitor_preserves_outer_class_after_anonymous_class(): void
    {
        $visitor = new TypeCoverageVisitor();
        $this->parseAndTraverse($visitor);

        $classInfos = $visitor->getClassInfos();

        self::assertArrayHasKey(
            'App\Service\OuterClass',
            $classInfos,
            'OuterClass should be tracked by TypeCoverageVisitor',
        );

        $typeInfo = $visitor->getClassTypeInfo();
        self::assertArrayHasKey(
            'App\Service\OuterClass',
            $typeInfo,
            'OuterClass type info should be present',
        );

        // OuterClass has 3 named methods with return types:
        // beforeAnonymous(): void, methodWithAnonymous(): void, afterAnonymous(): int
        // All have return type declarations
        self::assertSame(3, $typeInfo['App\Service\OuterClass']['returnTotal']);
        self::assertSame(3, $typeInfo['App\Service\OuterClass']['returnTyped']);
    }

    // ──────────────────────────────────────────────────────────────────
    // DependencyVisitor
    // ──────────────────────────────────────────────────────────────────

    #[Test]
    public function dependency_visitor_preserves_class_context_after_anonymous_class(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Service;

use App\Repository\UserRepository;
use App\Entity\User;

class OuterClass
{
    public function beforeAnonymous(): void
    {
        $repo = new UserRepository();
    }

    public function methodWithAnonymous(): void
    {
        $handler = new class {
            public function innerMethod(): void
            {
                if (true) {
                    echo 'inner';
                }
            }
        };
    }

    public function afterAnonymous(): void
    {
        $user = new User();
    }
}
PHP;

        $resolver = new DependencyResolver();
        $visitor = new DependencyVisitor($resolver);
        $visitor->setFile('/test.php');

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $deps = $visitor->getDependencies();

        // Filter dependencies from OuterClass
        $outerDeps = array_filter(
            $deps,
            static fn($dep) => $dep->source->toString() === 'App\Service\OuterClass',
        );

        // Both UserRepository (beforeAnonymous) and User (afterAnonymous)
        // should be attributed to OuterClass
        $targetClasses = array_map(
            static fn($dep) => $dep->target->toString(),
            array_values($outerDeps),
        );

        self::assertContains(
            'App\Repository\UserRepository',
            $targetClasses,
            'UserRepository dependency should be attributed to OuterClass',
        );
        self::assertContains(
            'App\Entity\User',
            $targetClasses,
            'User dependency should be attributed to OuterClass (after anonymous class)',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param list<\AiMessDetector\Core\Metric\MethodWithMetrics> $methods
     */
    private function findMethodByName(array $methods, string $name): ?\AiMessDetector\Core\Metric\MethodWithMetrics
    {
        foreach ($methods as $method) {
            if ($method->method === $name) {
                return $method;
            }
        }

        return null;
    }
}
