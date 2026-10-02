<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;
use Qualimetrix\Core\Path\AbsolutePath;

/** Authored or inferred analysis paths, resolved against the captured invocation. */
final class RunPathSelection
{
    /** @return array{list<AbsolutePath>, PathsAuthorship} */
    public static function select(ConfigurationDocument $document, AutoloadDevPolicy $autoloadDev): array
    {
        $writtenPaths = $document->resolved()->get(ConfigSchema::PATHS);
        if ($writtenPaths === null) {
            return [self::defaultPathList($document, $autoloadDev), PathsAuthorship::Inferred];
        }
        if (!$writtenPaths instanceof ResolvedListInterface) {
            throw new LogicException(\sprintf('The document declares a list here, but resolved a %s.', $writtenPaths::class));
        }

        return [self::writtenPathList($document->workingDirectory(), $writtenPaths), PathsAuthorship::Authored];
    }

    /**
     * When no layer wrote `paths`: the roots composer discovery found, or the
     * working directory when it found none.
     *
     * @param list<string> $discovered
     *
     * @return non-empty-list<string>
     */
    private static function defaultPaths(array $discovered): array
    {
        return $discovered !== [] ? $discovered : ['.'];
    }

    /** @return list<AbsolutePath> */
    private static function defaultPathList(ConfigurationDocument $document, AutoloadDevPolicy $autoloadDev): array
    {
        $root = $document->workingDirectory();
        try {
            return PathsNormalizer::normalize($root, self::defaultPaths(self::discoveredPaths($document, $autoloadDev)));
        } catch (InvalidArgumentException $error) {
            throw ConfigurationRefusal::aboutInput(
                ConfigurationOrigin::of(ConfigurationSource::ComposerJson, $root->value() . '/composer.json'),
                $error->getMessage(),
                $error,
            );
        }
    }

    /** @return list<AbsolutePath> */
    private static function writtenPathList(AbsolutePath $root, ResolvedListInterface $writtenPaths): array
    {
        PathsSection::read($writtenPaths);
        $paths = [];
        foreach ($writtenPaths->items() as $item) {
            try {
                $paths[] = PathsNormalizer::normalize($root, [$item->plain()])[0];
            } catch (InvalidArgumentException $error) {
                $item->refuse($error->getMessage());
            }
        }

        return $paths;
    }

    /**
     * The targets composer discovery contributed, taken under the run's
     * policy and kept to the ones a walk of the project reaches, through the
     * same two questions {@see ProjectScopePaths} asks of the
     * denominator. They are only the default — a `paths` any source wrote
     * replaces them, flag or no flag, and is never pruned here.
     *
     * @return list<string>
     */
    private static function discoveredPaths(ConfigurationDocument $document, AutoloadDevPolicy $autoloadDev): array
    {
        return ProjectScopePaths::reachableTargets(
            $document->workingDirectory(),
            $autoloadDev->projectTargets(
                $document->discoveredProductionAutoloadTargets(),
                $document->discoveredDevelopmentAutoloadTargets(),
            ) ?? [],
        );
    }

}
