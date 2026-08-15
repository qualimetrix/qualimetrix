<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use Qualimetrix\Analysis\Configuration\ConfigSchema;

use Qualimetrix\Analysis\Configuration\Contract\Discovery\ComposerAutoloadPathReaderInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;

/**
 * Auto-discovers paths from composer.json autoload (priority: 10).
 *
 * Extracts PSR-4 autoload paths and uses them as default analysis paths.
 */
final class ComposerDiscoveryStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 10;

    public function __construct(
        private readonly ComposerAutoloadPathReaderInterface $composerReader,
    ) {}

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'composer';
    }

    public function apply(ConfigurationResolutionRequest $request): ?ConfigurationLayer
    {
        $composerPath = $request->workingDirectory->value() . '/composer.json';

        $paths = $this->composerReader->extractAutoloadPaths($composerPath);

        if ($paths === []) {
            return null;
        }

        return new ConfigurationLayer('composer.json', [
            ConfigSchema::PATHS => $paths,
        ]);
    }
}
