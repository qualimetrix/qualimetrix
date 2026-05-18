<?php

declare(strict_types=1);

namespace Qualimetrix\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;

/**
 * Shared matcher used by the two `BannedStringPath…` PHPStan rules.
 *
 * Encapsulates the three decisions a banned-string-path check needs:
 * - is this name semantically a "path" we want typed (file / filePath / oldPath)?
 * - is the declared type a bare string (string | ?string | string|null)?
 * - is the enclosing class inside a namespace that should use the Path VOs?
 *
 * The rule pair (Property + PromotedProperty) shares this so the two AST shapes
 * stay in lock-step.
 *
 * Skeleton commit per ADR 0015 Phase 0 — not wired into phpstan.neon until Phase 6.
 *
 * @internal
 */
final class PathPropertyMatcher
{
    /** @var list<string> */
    private const FORBIDDEN_NAMES = ['file', 'filePath', 'oldPath'];

    /** @var list<string> */
    private const SCOPED_NAMESPACE_PREFIXES = [
        'Qualimetrix\\Core\\',
        'Qualimetrix\\Analysis\\',
        'Qualimetrix\\Reporting\\',
        'Qualimetrix\\Baseline\\',
        'Qualimetrix\\Infrastructure\\Git\\',
        'Qualimetrix\\Infrastructure\\Parallel\\',
        'Qualimetrix\\Infrastructure\\Cache\\',
    ];

    public function isForbiddenName(string $name): bool
    {
        return \in_array($name, self::FORBIDDEN_NAMES, true);
    }

    public function isInScopedNamespace(string $classFqn): bool
    {
        foreach (self::SCOPED_NAMESPACE_PREFIXES as $prefix) {
            if (str_starts_with($classFqn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the declared type is a bare `string`, `?string`, or `string|null`.
     * Other union/intersection types containing `string` are NOT flagged — they
     * already signal an intentional non-path use (e.g., `string|RelativePath`).
     */
    public function isForbiddenType(Identifier|Name|ComplexType|null $type): bool
    {
        if ($type === null) {
            return false;
        }

        if ($type instanceof NullableType) {
            return $this->isPlainString($type->type);
        }

        if ($type instanceof UnionType) {
            return $this->isStringOrNullUnion($type);
        }

        if ($type instanceof IntersectionType) {
            return false;
        }

        return $this->isPlainString($type);
    }

    private function isPlainString(Node $type): bool
    {
        return $type instanceof Identifier && $type->name === 'string';
    }

    private function isStringOrNullUnion(UnionType $type): bool
    {
        $hasString = false;

        foreach ($type->types as $member) {
            if ($member instanceof Identifier && $member->name === 'string') {
                $hasString = true;

                continue;
            }

            if ($member instanceof Identifier && $member->name === 'null') {
                continue;
            }

            return false;
        }

        return $hasString;
    }
}
