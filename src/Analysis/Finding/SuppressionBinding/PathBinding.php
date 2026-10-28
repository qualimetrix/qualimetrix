<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorKind;

/** Whether a path-shaped suppression value matches the files a run analysed or left unanalysed. */
final class PathBinding
{
    /**
     * Whether an entry the run left unanalysed may be what an unbound path
     * value names, so that its absence from the analysed files is not
     * evidence of a wrong spelling: `exact:src/Broken.php` over a file that did
     * not parse, or `exact:src/Gen.php` over one excluded as generated, names
     * it correctly.
     *
     * A failed or generated-excluded file is one known path and holds only a
     * value matching it, a regex included. A failed directory — one the run
     * could not list, or a link it did not follow — hides every path beneath
     * it, so it holds a literal anchored there and, having no literal location
     * to rule out, every regex.
     *
     * @param array{analysed: list<RelativePath>, failedFiles: list<RelativePath>, failedDirectories: list<RelativePath>, generatedExcluded: list<RelativePath>} $files
     */
    public static function unanalysedMayHold(PathPattern $pattern, array $files): bool
    {
        if (self::binds($pattern, [...$files['failedFiles'], ...$files['failedDirectories'], ...$files['generatedExcluded']])) {
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
