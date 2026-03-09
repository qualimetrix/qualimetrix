<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Namespace_;

use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Namespace_\NamespaceDetectorInterface;

/**
 * Factory for creating namespace detectors based on runtime configuration.
 *
 * Reads `namespace.composer_json` from AnalysisConfiguration to determine
 * the composer.json path for PSR-4 namespace detection.
 *
 * Note: `namespace.strategy` config is accepted by AnalysisConfiguration but
 * currently only 'chain' strategy is implemented. To support strategy selection
 * (e.g., 'psr4' only or 'tokenizer' only), extend this factory.
 */
final class NamespaceDetectorFactory
{
    private const string DEFAULT_COMPOSER_JSON = 'composer.json';

    public function __construct(
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Create namespace detector based on runtime configuration.
     */
    public function create(): NamespaceDetectorInterface
    {
        $config = $this->configurationProvider->getConfiguration();
        $composerJsonPath = $config->composerJsonPath ?? self::DEFAULT_COMPOSER_JSON;

        $psr4Detector = new Psr4NamespaceDetector($composerJsonPath);
        $tokenizerDetector = new TokenizerNamespaceDetector();

        return new ChainNamespaceDetector([
            $psr4Detector,
            $tokenizerDetector,
        ]);
    }
}
