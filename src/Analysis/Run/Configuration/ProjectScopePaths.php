<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReason;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeReasonKind;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use RuntimeException;

/** Captures the path facts used by initial project scope measurement. */
final class ProjectScopePaths
{
    /**
     * The declared targets a walk from the project root reaches, in their
     * declared order and spelling — what a run with no `paths` analyses and
     * what initial scope measurement uses as its denominator.
     *
     * @param list<string> $targets
     *
     * @return list<string>
     */
    public static function reachableTargets(AbsolutePath $projectRoot, array $targets): array
    {
        return self::partition($projectRoot, $targets)[0];
    }

    /**
     * @param list<string> $targets
     *
     * @return array{list<string>, list<array{target: string, directory: string}>}
     */
    public static function partition(AbsolutePath $projectRoot, array $targets): array
    {
        $reachable = [];
        $pruned = [];
        foreach ($targets as $target) {
            $directory = $target === '' ? null : self::builtInAncestor(PathFactory::fromCliArgument($target, $projectRoot), $projectRoot);

            if ($directory === null) {
                $reachable[] = $target;
            } else {
                $pruned[] = ['target' => $target, 'directory' => $directory];
            }
        }

        return [$reachable, $pruned];
    }

    /**
     * @param list<string> $reachable
     * @param list<ProjectScopeReason> $reasons
     *
     * @return list<array{target: string, path: AbsolutePath}>
     */
    public static function resolveDenominator(array $reachable, AbsolutePath $projectRoot, array &$reasons): array
    {
        $denominator = [];
        foreach ($reachable as $target) {
            $resolved = self::tryResolve(static fn(): AbsolutePath => PathFactory::fromCliArgument($target, $projectRoot)->canonicalize());
            if ($resolved === null) {
                $reasons[] = new ProjectScopeReason(ProjectScopeReasonKind::MissingTarget, ['target' => $target]);
            } else {
                $denominator[] = ['target' => $target, 'path' => $resolved];
            }
        }

        return $denominator;
    }

    public static function canonicalRoot(AbsolutePath $projectRoot): AbsolutePath
    {
        return self::tryResolve(static fn(): AbsolutePath => $projectRoot->canonicalize()) ?? $projectRoot;
    }

    /**
     * @param list<AbsolutePath> $paths
     *
     * @return list<array{written: AbsolutePath, path: AbsolutePath}>
     */
    public static function captureResolutions(AbsolutePath $writtenRoot, AbsolutePath $root, array $paths): array
    {
        $resolutions = [['written' => $writtenRoot, 'path' => $root]];
        foreach ($paths as $path) {
            if ($path->isDirectory()) {
                $resolved = self::tryResolve(static fn(): AbsolutePath => $path->canonicalize()) ?? $path;
            } else {
                $parent = self::tryResolve(static fn(): AbsolutePath => AbsolutePath::fromString(\dirname($path->value()))->canonicalize());
                $resolved = $parent === null ? $path : AbsolutePath::fromString($parent->value() . '/' . basename($path->value()));
            }
            $resolutions[] = ['written' => $path, 'path' => $resolved];
        }
        usort($resolutions, static fn(array $a, array $b): int => \strlen($b['written']->value()) <=> \strlen($a['written']->value()));

        return $resolutions;
    }

    private static function builtInAncestor(AbsolutePath $path, AbsolutePath $root): ?string
    {
        $walkedDirectory = $path->isDirectory()
            ? $path
            : AbsolutePath::fromString(\dirname($path->value()));
        $relative = $walkedDirectory->tryRelativizeTo($root);
        if ($relative === null) {
            return null;
        }
        $prefix = [];
        foreach ($relative->segments() as $segment) {
            $prefix[] = $segment;
            if (\in_array($segment, ['vendor', 'node_modules', '.git'], true)) {
                return implode('/', $prefix);
            }
        }

        return null;
    }

    /**
     * Runs a path-resolving closure, letting a genuine configuration refusal
     * propagate while treating any other {@see RuntimeException} (a path that
     * does not exist on disk) as "not resolvable" rather than fatal.
     *
     * @param callable(): AbsolutePath $resolve
     */
    private static function tryResolve(callable $resolve): ?AbsolutePath
    {
        try {
            return $resolve();
        } catch (ConfigurationRefusal $e) {
            throw $e;
        } catch (RuntimeException) {
            return null;
        }
    }
}
