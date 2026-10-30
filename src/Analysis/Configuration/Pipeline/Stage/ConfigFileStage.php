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
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;
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
        foreach ($passedOver as $dist) {
            if (!self::readsFile($authored, $dist)) {
                $diagnostics[] = new ConfigurationDiagnostic(
                    \sprintf(
                        'Configuration file "%1$s" is not used: "%2$s" takes precedence and does not import it. Add "%1$s" to the "imports" of "%2$s" to build on it.',
                        basename($dist),
                        $sourceName,
                    ),
                    [new Provenance(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, basename($dist)), null, 0)],
                );
            }
        }

        return new ConfigurationLayer($sourceName, [], authored: $authored, diagnostics: $diagnostics);
    }

    /** @param list<AuthoredLayer> $authored */
    private static function readsFile(array $authored, string $path): bool
    {
        [$stat] = NativeCall::attempt(static fn(): array|false => stat($path));
        $identity = FileIdentity::ofRegularFile($stat);
        if ($identity === null) {
            return true;
        }
        foreach ($authored as $layer) {
            if ($layer->file?->sameAs($identity) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the config file path.
     *
     * If an explicit path was provided via --config, uses that (throws on missing file).
     * Otherwise, auto-detects qmx.yaml or qmx.yml in the working directory,
     * falling back to qmx.dist.yaml or qmx.dist.yml. The third element lists
     * the dist files a found local file passes over.
     *
     * @return array{?string, list<ConfigurationDiagnostic>, list<string>}
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

            return [$request->configFilePath, [], []];
        }

        return $this->findConfigFile($request->workingDirectory->value(), PresetResolver::selectedPhysicalPaths($request->presetNames, $request->workingDirectory->value()));
    }

    /** @param list<string> $selectedPresetPaths
     * @return array{?string, list<ConfigurationDiagnostic>, list<string>}
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

        // A dist file named as a preset is already read in that band.
        $dist = array_values(array_filter(
            array_intersect($entries, self::DIST_FILE_NAMES),
            static fn(string $entry): bool => !self::isSelectedPreset($dir . '/' . $entry, $selectedPresetPaths),
        ));
        $local = self::single($entries, self::CONFIG_FILE_NAMES, $dir);
        if ($local !== null) {
            return [$local, [], array_map(static fn(string $entry): string => $dir . '/' . $entry, $dist)];
        }

        $found = self::single($dist, self::DIST_FILE_NAMES, $dir);
        if ($found !== null) {
            return [$found, [], []];
        }

        $diagnostics = [];
        foreach ($entries as $entry) {
            if (!self::isNearConfigName($entry)) {
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

        return [null, $diagnostics, []];
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
