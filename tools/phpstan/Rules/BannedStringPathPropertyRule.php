<?php

declare(strict_types=1);

namespace Qualimetrix\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags `string`-typed property declarations named like a path
 * (`$file`, `$filePath`, `$oldPath`) in production namespaces that should be
 * carrying `Qualimetrix\Core\Path\RelativePath` or `AbsolutePath` instead.
 *
 * Companion rule {@see BannedStringPathPromotedPropertyRule} covers promoted
 * constructor properties, which appear as `Node\Param` and would otherwise
 * be invisible to a `Stmt\Property`-only rule.
 *
 * Skeleton commit per ADR 0015 Phase 0 — wiring into phpstan.neon is deferred
 * to Phase 6 after all migration steps land.
 *
 * @implements Rule<Property>
 *
 * @internal
 */
final class BannedStringPathPropertyRule implements Rule
{
    public function __construct(
        private readonly PathPropertyMatcher $matcher,
    ) {}

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
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

        $errors = [];

        foreach ($node->props as $prop) {
            $name = $prop->name->toString();

            if (!$this->matcher->isForbiddenName($name)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Property %s::$%s should use Qualimetrix\\Core\\Path\\RelativePath or AbsolutePath, not a string-typed path.',
                $classFqn,
                $name,
            ))
                ->identifier('qmx.bannedStringPathProperty')
                ->build();
        }

        return $errors;
    }
}
