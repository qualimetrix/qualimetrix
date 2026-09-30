<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;

/** Refuses explicit command inputs the PHP discovery grammar cannot analyse. */
final class AnalysisInputPathValidator
{
    /** @param list<AbsolutePath> $paths */
    public function validate(array $paths, ConfigurationDocument $document): void
    {
        $errors = [];
        foreach ($paths as $path) {
            if (!$path->exists()) {
                $errors[] = \sprintf("Error: path '%s' does not exist", $path->value());
            } elseif (is_file($path->value()) && pathinfo($path->value(), \PATHINFO_EXTENSION) !== 'php') {
                $errors[] = \sprintf("Error: path '%s' is not a PHP file", $path->value());
            }
        }

        if ($errors !== []) {
            throw ConfigurationInputAdapter::pathsRefusal($document, implode("\n", $errors));
        }
    }
}
