<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;
use Qualimetrix\Core\Path\AbsolutePath;

/** Refuses explicit command inputs the PHP discovery grammar cannot analyse. */
final class AnalysisInputPathValidator
{
    /** @param list<AbsolutePath> $paths */
    public function validate(array $paths, ConfigurationDocument $document, PathsAuthorship $authorship): void
    {
        $errors = [];
        $invalid = [];
        foreach ($paths as $path) {
            if (!$path->exists()) {
                $errors[] = \sprintf("path '%s' does not exist", $path->value());
                $invalid[] = $path;
            } elseif (is_file($path->value()) && pathinfo($path->value(), \PATHINFO_EXTENSION) !== 'php') {
                $errors[] = \sprintf("path '%s' is not a PHP file", $path->value());
                $invalid[] = $path;
            }
        }

        if ($errors !== []) {
            if ($authorship === PathsAuthorship::Inferred) {
                $targets = array_map(static fn(AbsolutePath $path): string => $path->tryRelativizeTo($document->workingDirectory())?->value() ?? $path->value(), $invalid);
                throw ConfigurationRefusal::aboutInput(
                    ConfigurationOrigin::of(ConfigurationSource::ComposerJson, $document->workingDirectory()->value() . '/composer.json'),
                    \sprintf('composer.json autoload target %s: %s', implode(', ', $targets), implode("\n", $errors)),
                );
            }
            throw ConfigurationInputAdapter::pathsRefusal($document, implode("\n", $errors));
        }
    }
}
