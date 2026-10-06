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
            $failure = self::failureFor($path);
            if ($failure !== null) {
                $errors[] = $failure;
                $invalid[] = $path;
            }
        }

        if ($errors !== []) {
            self::refuse($document, $authorship, $invalid, $errors);
        }
    }

    private static function failureFor(AbsolutePath $path): ?string
    {
        if (!$path->exists()) {
            return \sprintf("path '%s' does not exist", $path->value());
        }
        if (is_file($path->value()) && pathinfo($path->value(), \PATHINFO_EXTENSION) !== 'php') {
            return \sprintf("path '%s' is not a PHP file", $path->value());
        }

        return null;
    }

    /**
     * @param list<AbsolutePath> $invalid
     * @param list<string> $errors
     */
    private static function refuse(ConfigurationDocument $document, PathsAuthorship $authorship, array $invalid, array $errors): never
    {
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
