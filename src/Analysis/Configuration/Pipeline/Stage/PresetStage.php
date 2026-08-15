<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface;

use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigDataNormalizer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use Qualimetrix\Analysis\Configuration\Pipeline\RuleNameValidator;
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
        private readonly ?KnownRuleNamesProviderInterface $knownRuleNamesProvider = null,
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

        $documents = $this->loadPresets($presetNames, $request->workingDirectory->value());
        if ($documents === []) {
            return null;
        }

        return new ConfigurationLayer(
            'preset:' . implode(',', $presetNames),
            [],
            $documents,
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
                if ($trimmed !== '') {
                    $names[] = $trimmed;
                }
            }
        }

        // Deduplicate while preserving order
        return array_values(array_unique($names));
    }

    /**
     * Loads normalized preset source documents in precedence order.
     *
     * @param list<string> $presetNames
     *
     * @return list<array<string, mixed>>
     */
    private function loadPresets(array $presetNames, string $workingDirectory): array
    {
        $documents = [];

        foreach ($presetNames as $name) {
            $path = $this->resolver->resolve($name, $workingDirectory);
            $data = $this->loader->load($path);

            if ($this->knownRuleNamesProvider !== null) {
                RuleNameValidator::validateRuleNames($data, "preset:{$name}", $this->knownRuleNamesProvider, $path);
            }

            $normalized = ConfigDataNormalizer::normalize($data);

            $documents[] = $normalized;
        }

        return $documents;
    }
}
