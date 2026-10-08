<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\ValueReach;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Core\Path\RelativePath;

/** Resolves the subject's smallest defensible file region. */
final readonly class SubjectRegion
{
    /**
     * @param array<string, list<string>> $psr4Roots
     * @param list<Finding> $observed
     */
    public static function forIdentity(BaselineIdentity $identity, ValueReach $reach, array $psr4Roots, array $observed = []): Region
    {
        if ($reach === ValueReach::Run) {
            return Region::whole();
        }

        $subject = $identity->subjectKey;
        $file = self::subjectFile($identity);
        if ($file !== null) {
            return Region::file($file);
        }
        if (!str_starts_with($subject, 'ns:')) {
            return Region::whole();
        }

        return self::namespaceRegion(substr($subject, 3), $psr4Roots, $observed);
    }

    public static function subjectFile(BaselineIdentity $identity): ?RelativePath
    {
        if (str_starts_with($identity->subjectKey, 'file:')) {
            return RelativePath::fromString(rawurldecode(substr($identity->subjectKey, 5)));
        }

        return str_starts_with($identity->subjectKey, 'declaration:')
            ? self::declarationFile($identity->subjectKey)
            : null;
    }

    private static function declarationFile(string $subject): ?RelativePath
    {
        $separator = strpos($subject, '@', \strlen('declaration:'));
        if ($separator === false) {
            return null;
        }
        $path = substr($subject, $separator + 1);
        $path = preg_replace('/#[1-9][0-9]*$/D', '', $path) ?? $path;

        return RelativePath::fromString(rawurldecode($path));
    }

    /**
     * @param array<string, list<string>> $psr4Roots
     * @param list<Finding> $observed
     */
    private static function namespaceRegion(string $namespace, array $psr4Roots, array $observed): Region
    {
        $roots = self::namespaceRoots($namespace, $psr4Roots);
        if ($roots === []) {
            return Region::whole();
        }
        $region = Region::namespace($roots);

        return self::containsObserved($region, $observed) ? $region : Region::whole();
    }

    /**
     * @param array<string, list<string>> $psr4Roots
     *
     * @return list<RelativePath>
     */
    private static function namespaceRoots(string $namespace, array $psr4Roots): array
    {
        $roots = [];
        foreach ($psr4Roots as $prefix => $paths) {
            $suffix = self::namespaceSuffix($namespace, $prefix);
            if ($suffix === null) {
                continue;
            }
            foreach ($paths as $path) {
                $relative = trim($path . '/' . str_replace('\\', '/', $suffix), '/');
                if ($relative !== '') {
                    $roots[$relative] = RelativePath::fromString($relative);
                }
            }
        }

        return array_values($roots);
    }

    private static function namespaceSuffix(string $namespace, string $prefix): ?string
    {
        $stem = rtrim($prefix, '\\');
        if ($stem !== '' && $namespace !== $stem && !str_starts_with($namespace, $stem . '\\')) {
            return null;
        }

        return $stem === '' ? $namespace : ltrim(substr($namespace, \strlen($stem)), '\\');
    }

    /** @param list<Finding> $observed */
    private static function containsObserved(Region $region, array $observed): bool
    {
        if ($observed === []) {
            return false;
        }
        foreach ($observed as $finding) {
            if ($finding->location->file === null || !$region->contains($finding->location->file)) {
                return false;
            }
        }

        return true;
    }
}
