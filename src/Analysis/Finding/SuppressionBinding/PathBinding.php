<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorKind;

/** Whether a path-shaped suppression value matches the files a run analysed or failed to analyse. */
final class PathBinding
{
    /**
     * Whether a failed entry may be what an unbound path value names, so that
     * its absence from the analysed files is not evidence of a wrong spelling:
     * `exact:src/Broken.php` over a file that did not parse names it correctly.
     *
     * A failed file is one known path and holds only a value matching it. A
     * failed directory — one the run could not list, or a link it did not
     * follow — hides every path beneath it, so it holds a literal anchored
     * there and, having no literal location to rule out, every regex.
     *
     * @param array{analysed: list<RelativePath>, failedFiles: list<RelativePath>, failedDirectories: list<RelativePath>} $files
     */
    public static function failureMayHold(PathPattern $pattern, array $files): bool
    {
        if (self::binds($pattern, [...$files['failedFiles'], ...$files['failedDirectories']])) {
            return true;
        }

        if ($pattern->definition->kind === SelectorKind::Regex) {
            return $files['failedDirectories'] !== [];
        }

        foreach ($files['failedDirectories'] as $directory) {
            if (str_starts_with($pattern->definition->value, $directory->value() . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<RelativePath> $paths */
    public static function binds(PathPattern $pattern, array $paths): bool
    {
        foreach ($paths as $path) {
            if ($pattern->matches($path)) {
                return true;
            }
        }

        return false;
    }
}
