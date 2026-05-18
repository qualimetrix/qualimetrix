<?php

declare(strict_types=1);

namespace Qualimetrix\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Counterpart to {@see BannedStringPathPropertyRule} that targets promoted
 * constructor properties (PHP 8.0+). Such properties appear in the AST as
 * `Node\Param` with `flags !== 0` (or non-empty `hooks` from PHP 8.4), so a
 * `Stmt\Property`-only rule misses them entirely. Both rules share
 * {@see PathPropertyMatcher} so the two AST shapes stay aligned.
 *
 * Skeleton commit per ADR 0015 Phase 0 — wired in Phase 6.
 *
 * @implements Rule<Param>
 *
 * @internal
 */
final class BannedStringPathPromotedPropertyRule implements Rule
{
    public function __construct(
        private readonly PathPropertyMatcher $matcher,
    ) {}

    public function getNodeType(): string
    {
        return Param::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->isPromoted()) {
            return [];
        }

        if (!$scope->isInClass()) {
            return [];
        }

        $classFqn = $scope->getClassReflection()->getName();

        if (!$this->matcher->isInScopedNamespace($classFqn)) {
            return [];
        }

        if (!$this->matcher->isForbiddenType($node->type)) {
            return [];
        }

        if (!$node->var instanceof Variable || !\is_string($node->var->name)) {
            return [];
        }

        $name = $node->var->name;

        if (!$this->matcher->isForbiddenName($name)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf(
                'Promoted property %s::$%s should use Qualimetrix\\Core\\Path\\RelativePath or AbsolutePath, not a string-typed path.',
                $classFqn,
                $name,
            ))
                ->identifier('qmx.bannedStringPathPromotedProperty')
                ->build(),
        ];
    }
}
