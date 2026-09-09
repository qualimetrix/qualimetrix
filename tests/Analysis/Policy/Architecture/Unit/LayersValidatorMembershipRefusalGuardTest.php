<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Validation;

use PhpParser\Node;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\LayersValidator;

/**
 * `LayersValidator::buildMembershipDefinition()`'s `try`/`catch` is the one
 * named exception to normalization-in-place (plan `03-normalization-verdicts.md`
 * §4.1): its `catch` clause names `InvalidArgumentException` alongside
 * `InvalidLayerDefinitionException`, so any product defect that throws a bare
 * `InvalidArgumentException` inside the `try` would also become a
 * {@see ConfigurationRefusal} with exit code 3 — masking a bug as user input.
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
 * 0 / 1 / 2 times — the count the plan measured. A fourth occurrence
 * anywhere in the tree is the return-to-orchestrator trigger named in the
 * plan, not something this guard could quietly wave through.
 *
 * A `LogicException` cannot be constructed for a behavioural test here:
 * `LayerDefinition` and `MembershipSpec` are `final` and never throw one.
 * The negative half of the guard is therefore structural: PHP dispatches a
 * `catch` by the listed types only, so proving the clause names exactly
 * `InvalidLayerDefinitionException` and `InvalidArgumentException` — no
 * broader family, no `Throwable`, no `LogicException` — is itself the proof
 * that a `LogicException` thrown in the same `try` would propagate past this
 * clause uncaught rather than surface as a refusal.
 */
final class LayersValidatorMembershipRefusalGuardTest extends TestCase
{
    #[Test]
    public function itLetsEmptyMembershipCriteriaReachTheConfigurationCarrier(): void
    {
        $validator = new LayersValidator();

        try {
            $validator->validate([
                ['name' => 'empty-layer'],
            ]);
            self::fail('Expected ConfigurationRefusal for a layer entry declaring no criterion.');
        } catch (ConfigurationRefusal $e) {
            self::assertStringContainsString(
                'must declare at least one of "patterns", "suffix", "attributes", "implements" or "extends"',
                $e->getMessage(),
            );
        }
    }

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
        $root = realpath(__DIR__ . '/../../../../../');
        self::assertIsString($root);

        return $root;
    }
}
