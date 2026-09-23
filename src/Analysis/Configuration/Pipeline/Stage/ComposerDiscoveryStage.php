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
 * Contributes the production and the `autoload-dev` PSR-4 roots as two
 * lists, not as `paths`: whether test code is part of the project is
 * `include_autoload_dev`, which a source after this one may write, so the run
 * configuration picks the default analysis paths from both lists and judges
 * scope against the same choice
 * ({@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage}).
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

        $production = $this->composerReader->extractAutoloadPaths($composerPath);
        $development = $this->composerReader->extractAutoloadDevPaths($composerPath);

        if ($production === [] && $development === []) {
            return null;
        }

        return new ConfigurationLayer('composer.json', [
            ConfigSchema::DISCOVERED_AUTOLOAD_PATHS => $production,
            ConfigSchema::DISCOVERED_AUTOLOAD_DEV_PATHS => $development,
        ]);
    }
}
