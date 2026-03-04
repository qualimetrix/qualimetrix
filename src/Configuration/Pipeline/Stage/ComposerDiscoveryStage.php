<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline\Stage;

use AiMessDetector\Configuration\Discovery\ComposerReader;
use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationLayer;

/**
 * Auto-discovers paths from composer.json autoload (priority: 10).
 *
 * Extracts PSR-4 autoload paths and uses them as default analysis paths.
 */
final class ComposerDiscoveryStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 10;

    public function __construct(
        private readonly ComposerReader $composerReader,
    ) {}

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'composer';
    }

    public function apply(ConfigurationContext $context): ?ConfigurationLayer
    {
        $composerPath = $context->workingDirectory . '/composer.json';

        $paths = $this->composerReader->extractAutoloadPaths($composerPath);

        if ($paths === []) {
            return null;
        }

        return new ConfigurationLayer('composer.json', [
            'paths' => $paths,
        ]);
    }
}
