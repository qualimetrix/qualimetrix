<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline\Stage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Configuration\KnownRuleNamesProviderInterface;
use Qualimetrix\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Configuration\Pipeline\ConfigDataNormalizer;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Configuration\Pipeline\ConfigurationMerger;
use Qualimetrix\Configuration\Pipeline\RuleNameValidator;
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
        private readonly ?KnownRuleNamesProviderInterface $knownRuleNamesProvider = null,
        private readonly LoggerInterface $logger = new NullLogger(),
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

            if ($this->knownRuleNamesProvider !== null) {
                RuleNameValidator::warnAboutUnknownRuleNames($data, "preset:{$name}", $this->knownRuleNamesProvider, $this->logger);
            }

            $normalized = ConfigDataNormalizer::normalize($data);

            $merged = ConfigurationMerger::merge($merged, $normalized);
        }

        return $merged;
    }
}
