<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;

/**
 * Applies named presets (priority: 15).
 *
 * Sits between ComposerDiscovery (10) and ConfigFile (20), so presets
 * provide sensible defaults that the user's qmx.yaml can still override.
 *
 * Multiple presets can be specified and are merged in order.
 */
final class PresetStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 15;

    public function __construct(
        private readonly ConfigLoaderInterface $loader,
        private readonly PresetResolver $resolver,
    ) {}

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'preset';
    }

    public function apply(ConfigurationResolutionRequest $request): ?ConfigurationLayer
    {
        $presetNames = $this->extractPresetNames($request);

        if ($presetNames === []) {
            return null;
        }

        return new ConfigurationLayer(
            'preset:' . implode(',', $presetNames),
            authored: $this->loadPresets($presetNames, $request->workingDirectory->value()),
        );
    }

    /**
     * Extracts and deduplicates preset names from --preset CLI option.
     *
     * Supports both repeated options (--preset=strict --preset=ci)
     * and comma-separated values (--preset=strict,ci).
     *
     * An empty name between commas (`--preset=strict,`, `--preset=,ci`) is
     * refused rather than skipped: it is what a list assembled from an unset
     * variable looks like, and skipping it runs fewer presets than written.
     *
     * @return list<string>
     */
    private function extractPresetNames(ConfigurationResolutionRequest $request): array
    {
        if ($request->presetNames === []) {
            return [];
        }

        // Split comma-separated values and flatten
        $names = [];
        foreach ($request->presetNames as $value) {
            foreach (explode(',', $value) as $part) {
                $trimmed = trim($part);
                if ($trimmed === '') {
                    throw ConfigurationRefusal::aboutCommandLineInput(
                        '--preset',
                        \sprintf(
                            'Option --preset was written with an empty preset name ("--preset=%s"). '
                            . 'Name a preset between every pair of commas, or omit --preset entirely.',
                            $value,
                        ),
                    );
                }

                $names[] = $trimmed;
            }
        }

        // Deduplicate while preserving order
        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $presetNames
     *
     * @return list<AuthoredLayer>
     */
    private function loadPresets(array $presetNames, string $workingDirectory): array
    {
        $authored = [];
        foreach ($presetNames as $name) {
            $path = $this->resolver->resolve($name, $workingDirectory);
            $loaded = $this->loader->read($path, $path);
            $authored[] = new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, $name), $loaded->authored);
        }
        return $authored;
    }
}
