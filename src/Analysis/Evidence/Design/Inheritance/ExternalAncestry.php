<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ExternalParentSourceInterface;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;

/** Follows external ancestry by reading sources and static PHP facts, never by loading analysed classes. */
final class ExternalAncestry
{
    private const int VISIT_CAP = 64;

    public function __construct(private readonly ExternalParentSourceInterface $parents) {}

    public function depthOf(string $fqcn): ExternalDepth
    {
        $current = ltrim($fqcn, '\\');
        $depth = 0;
        $seen = [];
        $throwable = null;

        for ($step = 0; $step < self::VISIT_CAP; ++$step) {
            $identity = ClassNameSpelling::fold($current);
            if (isset($seen[$identity])) {
                return ExternalDepth::loop($current, $throwable);
            }
            $seen[$identity] = true;

            $builtin = PhpBuiltinClassRegistry::canonicalName($current);
            if ($builtin !== null) {
                $throwable = $throwable === true || $this->builtinReachesThrowable($builtin);
                $parents = PhpBuiltinClassHierarchy::extendsOf($builtin) ?? [];
                if ($parents === []) {
                    return ExternalDepth::reachedRoot($depth, $throwable);
                }
                $current = $parents[0];
            } else {
                $next = $this->sourceStep($current, $depth);
                if ($next instanceof ExternalDepth) {
                    return $next;
                }
                $current = $next;
            }
            ++$depth;
        }

        return ExternalDepth::brokeAt($depth, $current, $throwable);
    }

    private function builtinReachesThrowable(string $builtin): bool
    {
        return $builtin === 'Throwable'
            || \in_array('Throwable', PhpBuiltinClassHierarchy::interfacesOf($builtin) ?? [], true);
    }

    private function sourceStep(string $current, int $depth): ExternalDepth|string
    {
        if (!$this->parents->isConfigured()) {
            return ExternalDepth::noMap($depth);
        }
        $lookup = $this->parents->parentOf($current);
        if (!$lookup->placed) {
            return ExternalDepth::brokeAt($depth, $current);
        }
        if ($lookup->parent === null) {
            return ExternalDepth::reachedRoot($depth);
        }

        return ltrim($lookup->parent, '\\');
    }
}
