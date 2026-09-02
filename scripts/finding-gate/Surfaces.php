<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** The surfaces the gate compares, as a list. Never as a count. */
final class Surfaces
{
    /** @var list<string> */
    public const FORMATS = [
        'summary',
        'text',
        'text-verbose',
        'json',
        'checkstyle',
        'sarif',
        'gitlab',
        'github',
        'metrics',
        'health',
        'html',
        'suppressed',
    ];

    public static function key(string $scope, string $surface): string
    {
        return $scope . '|' . $surface;
    }

    /**
     * The normalization surface class of an artifact key: the same field of the
     * same format is one rule, whatever case or subject produced it.
     */
    public static function surfaceClass(string $artifactKey): string
    {
        $surface = substr($artifactKey, (int) strpos($artifactKey, '|') + 1);

        foreach (['explain:', 'stderr:'] as $prefix) {
            if (str_starts_with($surface, $prefix)) {
                return rtrim($prefix, ':');
            }
        }

        return $surface;
    }
}
