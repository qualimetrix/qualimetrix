<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ModularOwnership;

use PhpParser\Node;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `LayersValidator::buildMembershipDefinition()`'s `try`/`catch` is the one
 * named exception to normalization-in-place: its `catch` clause names
 * `InvalidArgumentException` alongside
 * `InvalidLayerDefinitionException`, so any product defect that throws a bare
 * `InvalidArgumentException` inside the `try` would also become a
 * configuration refusal with exit code 3 — masking a bug as user input.
 * The exception is only safe because the `try` body is measured to be a
 * single constructor call whose only `InvalidArgumentException` sources are
 * named VO invariants (`MembershipSpec`'s "no non-empty criterion" check and
 * `CriterionListValidator`'s two per-entry checks).
 *
 * This is a two-sided guard, not one: pinning the source text of the `try`
 * body alone would miss a new `InvalidArgumentException` throw introduced
 * deeper in the call tree (still caught by the same clause, still silently
 * widening what "input" means). So this class asserts, from the AST rather
 * than by reading the file, that the `try` is exactly one call and that the
 * three files in its call tree throw `InvalidArgumentException` exactly
 * 0 / 1 / 2 times. A fourth occurrence anywhere in the tree would need a
 * deliberate review because it would widen the set of defects caught as
 * invalid user input.
 */
final class LayersValidatorMembershipRefusalGuardTest extends TestCase
{
    #[Test]
    public function itKeepsTheMembershipTryBodyToOneCallAndTheCatchToTheTwoNamedTypes(): void
    {
        $method = $this->findBuildMembershipDefinition();
        $try = $this->findMembershipTryCatch($method);

        self::assertCount(
            1,
            $try->stmts,
            'The try body guarded by the InvalidArgumentException catch must stay a single statement — '
            . 'a second call would introduce an InvalidArgumentException source the plan never measured.',
        );

        $catchTypes = $this->catchTypeNames($try->catches[0]);
        self::assertSame(
            ['InvalidLayerDefinitionException', 'InvalidArgumentException'],
            $catchTypes,
            'The clause must name exactly these two types — widening it (e.g. to Throwable or LogicException) '
            . 'would let a product defect masquerade as a configuration refusal.',
        );
    }

    #[Test]
    public function itCountsInvalidArgumentExceptionThrowsInTheMembershipCallTreeAsZeroOneTwo(): void
    {
        $root = $this->repositoryRoot() . '/src/Analysis/Policy/Architecture/Layer';

        self::assertSame(
            0,
            $this->countInvalidArgumentExceptionThrows($root . '/LayerDefinition.php'),
            'LayerDefinition.php must throw no bare InvalidArgumentException — only InvalidLayerDefinitionException.',
        );
        self::assertSame(
            1,
            $this->countInvalidArgumentExceptionThrows($root . '/MembershipSpec.php'),
            'MembershipSpec.php must throw exactly one InvalidArgumentException — the "no non-empty criterion" invariant.',
        );
        self::assertSame(
            2,
            $this->countInvalidArgumentExceptionThrows($root . '/CriterionListValidator.php'),
            'CriterionListValidator.php must throw exactly two InvalidArgumentException — the non-string and empty-string checks.',
        );
    }

    private function findBuildMembershipDefinition(): ClassMethod
    {
        $path = $this->repositoryRoot() . '/src/Analysis/Policy/Architecture/Configuration/LayersValidator.php';
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse(
            (string) file_get_contents($path),
        );
        self::assertNotNull($nodes);

        $method = (new NodeFinder())->findFirst(
            $nodes,
            static fn(Node $node): bool => $node instanceof ClassMethod
                && $node->name->toString() === 'buildMembershipDefinition',
        );
        self::assertInstanceOf(ClassMethod::class, $method, 'LayersValidator::buildMembershipDefinition() must exist.');

        return $method;
    }

    private function findMembershipTryCatch(ClassMethod $method): TryCatch
    {
        $tryCatch = (new NodeFinder())->findFirst(
            (array) $method->stmts,
            static fn(Node $node): bool => $node instanceof TryCatch
                && $node->catches !== []
                && self::catchNamesInvalidArgumentException($node->catches[0]),
        );
        self::assertInstanceOf(
            TryCatch::class,
            $tryCatch,
            'buildMembershipDefinition() must contain a try/catch whose clause names InvalidArgumentException.',
        );

        return $tryCatch;
    }

    private static function catchNamesInvalidArgumentException(Catch_ $catch): bool
    {
        foreach ($catch->types as $type) {
            if ($type->toString() === 'InvalidArgumentException') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function catchTypeNames(Catch_ $catch): array
    {
        return array_values(array_map(static fn(Node\Name $type): string => $type->toString(), $catch->types));
    }

    private function countInvalidArgumentExceptionThrows(string $path): int
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse(
            (string) file_get_contents($path),
        );
        self::assertNotNull($nodes);

        $throws = (new NodeFinder())->find(
            $nodes,
            static fn(Node $node): bool => $node instanceof Node\Expr\Throw_
                && $node->expr instanceof Node\Expr\New_
                && $node->expr->class instanceof Node\Name
                && $node->expr->class->toString() === 'InvalidArgumentException',
        );

        return \count($throws);
    }

    private function repositoryRoot(): string
    {
        $root = realpath(__DIR__ . '/../../');
        self::assertIsString($root);

        return $root;
    }
}
