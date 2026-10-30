<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use DirectoryIterator;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Analysis\Configuration\Loader\ImportChain;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use UnexpectedValueException;

/**
 * Loads configuration from config file (priority: 20).
 *
 * Searches for qmx.yaml or qmx.yml in working directory, then for the
 * committed qmx.dist.yaml or qmx.dist.yml. The files its `imports` list names
 * share this band, below the file itself.
 */
final class ConfigFileStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 20;

    /** @var array{string, string} */
    private const array CONFIG_FILE_NAMES = ['qmx.yaml', 'qmx.yml'];

    /**
     * A local file wins and does not merge it implicitly, so a git-ignored
     * qmx.yaml builds on the committed defaults only by importing them.
     *
     * @var array{string, string}
     */
    private const array DIST_FILE_NAMES = ['qmx.dist.yaml', 'qmx.dist.yml'];

    public function __construct(
        private readonly ConfigLoaderInterface $loader,
    ) {}

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'config_file';
    }

    public function apply(ConfigurationResolutionRequest $request): ?ConfigurationLayer
    {
        [$configPath, $diagnostics, $passedOver] = $this->resolveConfigPath($request);

        if ($configPath === null) {
            return $diagnostics === [] ? null : new ConfigurationLayer('config_file', [], diagnostics: $diagnostics);
        }

        $sourceName = $request->configFilePath ?? basename($configPath);

        $authored = (new ImportChain($this->loader))->expand($configPath, ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $sourceName));
        if ($passedOver !== null) {
            $diagnostics = [...$diagnostics, ...$this->passedOver($passedOver, $configPath, $sourceName, $authored)];
        }

        return new ConfigurationLayer($sourceName, [], authored: $authored, diagnostics: $diagnostics);
    }

    /**
     * What a run without the local file would read differently: a dist file
     * the local import chain does not read, or one that run would refuse. The
     * dist file is read through the chain that run would use, so its verdict
     * is that run's.
     *
     * @param list<AuthoredLayer> $authored
     *
     * @return list<ConfigurationDiagnostic>
     */
    private function passedOver(string $dist, string $configPath, string $sourceName, array $authored): array
    {
        $name = basename($dist);
        try {
            $read = (new ImportChain($this->loader))->expand($dist, ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $name));
        } catch (ConfigurationRefusal $refusal) {
            return [self::aboutDist($name, \sprintf('A run without "%s" refuses configuration file "%s": %s', $sourceName, $name, $refusal->summary()))];
        }

        // A file read without identity cannot be told apart from the files read, so it is not judged.
        $identity = $read[array_key_last($read)]->file;
        if ($identity === null) {
            return [];
        }
        foreach ($authored as $layer) {
            if ($layer->file !== null && $identity->sameAs($layer->file)) {
                return [];
            }
        }

        // Imports resolve against the real directory of a symlinked root.
        $entry = \dirname((string) realpath($configPath)) === realpath(\dirname($dist)) ? $name : $dist;

        return [self::aboutDist($name, \sprintf('Configuration file "%s" is not used: "%s" takes precedence and does not import it. Add "%s" to its "imports" to build on it.', $name, $sourceName, $entry))];
    }

    private static function aboutDist(string $name, string $message): ConfigurationDiagnostic
    {
        return new ConfigurationDiagnostic($message, [new Provenance(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $name), null, 0)]);
    }

    /**
     * Resolves the config file path.
     *
     * If an explicit path was provided via --config, uses that (throws on missing file).
     * Otherwise, auto-detects qmx.yaml or qmx.yml in the working directory,
     * falling back to qmx.dist.yaml or qmx.dist.yml. The third element is
     * the dist file a found local file passes over.
     *
     * @return array{?string, list<ConfigurationDiagnostic>, ?string}
     */
    private function resolveConfigPath(ConfigurationResolutionRequest $request): array
    {
        if ($request->configFilePath !== null) {
            if (!file_exists($request->configFilePath)) {
                throw ConfigurationRefusal::aboutConfigFileDocument(
                    $request->configFilePath,
                    \sprintf('Configuration file "%s" was not found or is not accessible.', $request->configFilePath),
                );
            }

            return [$request->configFilePath, [], null];
        }

        return $this->findConfigFile($request->workingDirectory->value(), PresetResolver::selectedPhysicalPaths($request->presetNames, $request->workingDirectory->value()));
    }

    /** @param list<string> $selectedPresetPaths
     * @return array{?string, list<ConfigurationDiagnostic>, ?string}
     */
    private function findConfigFile(string $dir, array $selectedPresetPaths): array
    {
        try {
            $entries = [];
            foreach (new DirectoryIterator($dir) as $entry) {
                $entries[] = $entry->getFilename();
            }
            sort($entries, \SORT_STRING);
        } catch (UnexpectedValueException) {
            throw ConfigurationRefusal::aboutInput(
                ConfigurationOrigin::of(ConfigurationSource::Resolved),
                \sprintf('Configuration directory cannot be listed: %s', $dir),
            );
        }

        $local = self::single($entries, self::CONFIG_FILE_NAMES, $dir);
        // Both dist names are refused beside a local file too: a run without it would refuse them.
        $dist = self::single($entries, self::DIST_FILE_NAMES, $dir);
        if ($dist !== null && self::isSelectedPreset($dist, $selectedPresetPaths)) {
            $dist = null;
        }
        if ($local !== null) {
            return [$local, [], $dist];
        }

        return [$dist, self::nearNames($entries, $dir, $selectedPresetPaths), null];
    }

    /**
     * Beside a dist file a near name is still reported: it is usually a misnamed local file.
     *
     * @param list<string> $entries
     * @param list<string> $selectedPresetPaths
     *
     * @return list<ConfigurationDiagnostic>
     */
    private static function nearNames(array $entries, string $dir, array $selectedPresetPaths): array
    {
        $diagnostics = [];
        foreach ($entries as $entry) {
            if (!self::isNearConfigName($entry) || \in_array($entry, self::DIST_FILE_NAMES, true)) {
                continue;
            }
            if (self::isSelectedPreset($dir . '/' . $entry, $selectedPresetPaths)) {
                continue;
            }

            $diagnostics[] = new ConfigurationDiagnostic(
                \sprintf(
                    'Ignored configuration-like filename "%s"; auto-discovery accepts only exact qmx.yaml, qmx.yml, qmx.dist.yaml or qmx.dist.yml.',
                    $entry,
                ),
                [new Provenance(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $entry), null, 0)],
            );
        }

        return $diagnostics;
    }

    /**
     * @param list<string> $entries
     * @param array{string, string} $names
     */
    private static function single(array $entries, array $names, string $dir): ?string
    {
        $found = array_values(array_intersect($entries, $names));
        if (\count($found) > 1) {
            throw ConfigurationRefusal::acrossLayers(
                [
                    ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $dir . '/' . $names[0]),
                    ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $dir . '/' . $names[1]),
                ],
                null,
                \sprintf('Both %s and %s exist; keep exactly one configuration file.', $names[0], $names[1]),
            );
        }

        return $found === [] ? null : $dir . '/' . $found[0];
    }

    /** @param list<string> $selectedPresetPaths */
    private static function isSelectedPreset(string $path, array $selectedPresetPaths): bool
    {
        $physical = realpath($path);

        return $physical !== false && \in_array($physical, $selectedPresetPaths, true);
    }

    private static function isNearConfigName(string $entry): bool
    {
        $name = strtolower($entry);
        if ($name === 'qmx') {
            return true;
        }

        return preg_match('/(?:^|[._-])qmx(?:[._-]|$)/', $name) === 1
            && (str_contains($name, '.yaml') || str_contains($name, '.yml'));
    }
}
