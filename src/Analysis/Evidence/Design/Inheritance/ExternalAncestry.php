<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ExternalParentSourceInterface;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;

/**
 * The part of an inheritance chain that leaves the analysed path.
 *
 * It is followed by reading the analysed project's sources, never by loading
 * them. `class_exists($fqcn, true)` is not a test: it includes the file and
 * runs its top-level code, and because this tool can run as a dependency of the
 * project it analyses, that meant executing an arbitrary repository's code in
 * this process, with no bound on time or memory and no catch that reaches an
 * `exit`.
 *
 * Reading also answers from the right tree. The autoloader consulted before was
 * this tool's own, so an analysed name resolved against this tool's
 * dependencies -- measured across ten benchmark projects, 170 of 172 resolved
 * answers came from there rather than from the project being measured.
 *
 * What counts as a depth stays here; placing a class and reading its
 * declaration is delivery and lives behind the port.
 */
final class ExternalAncestry
{
    private const int VISIT_CAP = 64;

    /** @var array<string, ExternalDepth> */
    private array $answers = [];

    public function __construct(private readonly ExternalParentSourceInterface $parents) {}

    public function depthOf(string $fqcn): ExternalDepth
    {
        $normalized = ltrim($fqcn, '\\');

        return $this->answers[$normalized] ??= $this->walk($normalized);
    }

    private function walk(string $fqcn): ExternalDepth
    {
        if (!$this->parents->isConfigured()) {
            return ExternalDepth::noMap();
        }

        $current = $fqcn;
        $depth = 0;
        $seen = [];

        for ($step = 0; $step < self::VISIT_CAP; ++$step) {
            if (isset($seen[$current])) {
                // A cycle is not a depth: reporting the steps walked would be
                // reporting the length of a loop.
                return ExternalDepth::brokeAt($depth, $current);
            }
            $seen[$current] = true;

            $lookup = $this->parents->parentOf($current);

            if (!$lookup->placed) {
                return ExternalDepth::brokeAt($depth, $current);
            }

            if ($lookup->parent === null) {
                return ExternalDepth::reachedRoot($depth);
            }

            ++$depth;

            // PHP's own classes are the floor: their depth is not the analysed
            // project's to report.
            if (PhpBuiltinClassRegistry::isBuiltin($lookup->parent)) {
                return ExternalDepth::reachedRoot($depth);
            }

            $current = $lookup->parent;
        }

        return ExternalDepth::brokeAt($depth, $current);
    }
}
