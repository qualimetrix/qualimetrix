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
        if (str_starts_with($subject, 'file:')) {
            return Region::file(RelativePath::fromString(substr($subject, 5)));
        }
        if (str_starts_with($subject, 'declaration:')) {
            $separator = strpos($subject, '@', \strlen('declaration:'));
            if ($separator !== false) {
                $path = substr($subject, $separator + 1);
                $path = preg_replace('/#[1-9][0-9]*$/D', '', $path) ?? $path;

                return Region::file(RelativePath::fromString($path));
            }
        }
        if (!str_starts_with($subject, 'ns:')) {
            return Region::whole();
        }

        $namespace = substr($subject, 3);
        $roots = [];
        foreach ($psr4Roots as $prefix => $paths) {
            $stem = rtrim($prefix, '\\');
            if ($stem !== '' && $namespace !== $stem && !str_starts_with($namespace, $stem . '\\')) {
                continue;
            }
            $suffix = $stem === '' ? $namespace : ltrim(substr($namespace, \strlen($stem)), '\\');
            foreach ($paths as $path) {
                $relative = trim($path . '/' . str_replace('\\', '/', $suffix), '/');
                if ($relative !== '') {
                    $roots[$relative] = RelativePath::fromString($relative);
                }
            }
        }
        if ($roots === []) {
            return Region::whole();
        }

        $region = Region::namespace(array_values($roots));
        foreach ($observed as $finding) {
            if ($finding->location->file === null || !$region->contains($finding->location->file)) {
                return Region::whole();
            }
        }

        return $region;
    }
}
