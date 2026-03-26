<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline\Stage;

use Qualimetrix\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Configuration\Pipeline\ConfigDataNormalizer;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Preset\PresetResolver;

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

    public function apply(ConfigurationContext $context): ?ConfigurationLayer
    {
        $presetNames = $this->extractPresetNames($context);

        if ($presetNames === []) {
            return null;
        }

        $merged = $this->loadAndMergePresets($presetNames, $context->workingDirectory);

        if ($merged === []) {
            return null;
        }

        return new ConfigurationLayer(
            'preset:' . implode(',', $presetNames),
            $merged,
        );
    }

    /**
     * Extracts and deduplicates preset names from --preset CLI option.
     *
     * Supports both repeated options (--preset=strict --preset=ci)
     * and comma-separated values (--preset=strict,ci).
     *
     * @return list<string>
     */
    private function extractPresetNames(ConfigurationContext $context): array
    {
        $input = $context->input;

        if (!$input->hasOption('preset')) {
            return [];
        }

        $rawValues = $input->getOption('preset');

        if (!\is_array($rawValues) || $rawValues === []) {
            return [];
        }

        // Split comma-separated values and flatten
        $names = [];
        foreach ($rawValues as $value) {
            if (!\is_string($value) || $value === '') {
                continue;
            }
            foreach (explode(',', $value) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $names[] = $trimmed;
                }
            }
        }

        // Deduplicate while preserving order
        return array_values(array_unique($names));
    }

    /**
     * Loads and merges multiple preset configs into a single flat config array.
     *
     * @param list<string> $presetNames
     *
     * @return array<string, mixed>
     */
    private function loadAndMergePresets(array $presetNames, string $workingDirectory): array
    {
        $merged = [];

        foreach ($presetNames as $name) {
            $path = $this->resolver->resolve($name, $workingDirectory);
            $data = $this->loader->load($path);
            $normalized = ConfigDataNormalizer::normalize($data);

            $merged = $this->mergeIntoLayer($merged, $normalized);
        }

        return $merged;
    }

    /**
     * Merges a preset's normalized config into the accumulated layer.
     *
     * - MERGEABLE_LIST_KEYS: union semantics (accumulate values)
     * - 'rules': deep merge (associative arrays merged recursively, lists replaced)
     * - Everything else: override
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     *
     * @return array<string, mixed>
     */
    private function mergeIntoLayer(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                if (\in_array($key, ConfigurationPipeline::MERGEABLE_LIST_KEYS, true)) {
                    $base[$key] = array_values(array_unique(array_merge($base[$key], $value)));
                    continue;
                }

                if ($key === 'rules') {
                    $base[$key] = ConfigurationPipeline::deepMergeAssociative($base[$key], $value);
                    continue;
                }
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
