<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;

/**
 * Which file sources of a configuration document Composer installed into the
 * analysed project. Only positive evidence counts: whatever cannot be shown to
 * lie in the install directory is project configuration.
 */
final class InstalledConfigurationSources
{
    private function __construct() {}

    /** @return list<string> `describe()` of each source established as installed */
    public static function of(ConfigurationDocument $document, ComposerManifestFacts $facts): array
    {
        $install = self::installDirectory($facts);
        if ($install === null) {
            return [];
        }

        $installed = [];
        foreach (self::fileOrigins($document) as $origin) {
            if (self::isInstalled($origin, $document, $install)) {
                $installed[$origin->describe()] = true;
            }
        }

        return array_keys($installed);
    }

    /**
     * A project whose install directory is its root or above it would make its own
     * configuration "installed", so such a directory establishes nothing. Nor does one
     * that is not a directory, or the decoder's `vendor` fallback after it refused the
     * `config` section: that name is a default, not what the manifest says.
     */
    private static function installDirectory(ComposerManifestFacts $facts): ?string
    {
        if ($facts->state !== ManifestReadState::Read || self::configRefused($facts)) {
            return null;
        }
        $root = $facts->root->value();
        $vendor = $facts->vendorDirectory;
        $install = realpath(str_starts_with($vendor, '/') ? $vendor : rtrim($root, '/') . '/' . $vendor);
        $realRoot = realpath($root);
        if ($install === false || $realRoot === false || !is_dir($install) || self::isWithin($realRoot, $install)) {
            return null;
        }

        return $install;
    }

    /** A whole-manifest issue counts: it may hide what `config` said. */
    private static function configRefused(ComposerManifestFacts $facts): bool
    {
        foreach ($facts->issues as $issue) {
            if ($issue->location === [] || $issue->location[0] === 'config') {
                return true;
            }
        }

        return false;
    }

    /** @return list<ConfigurationOrigin> every file or preset origin the resolved document names, once each */
    private static function fileOrigins(ConfigurationDocument $document): array
    {
        return array_values(array_filter(
            $document->resolved()->origins(),
            static fn(ConfigurationOrigin $origin): bool => $origin->locator() !== null
                && ($origin->source() === ConfigurationSource::ConfigFile || $origin->source() === ConfigurationSource::Preset),
        ));
    }

    /**
     * The origin's name must lead to a regular file inside the install directory that the
     * configuration was read from under this very name. The lowest-precedence source with a
     * file's identity names it, so a different name is legitimate only when that source is a
     * preset — the band below every file — whatever this origin is: one file given as two
     * preset spellings, or as a preset and an import, is read under the first preset's name.
     * Any other mismatch means the locator reaches another file.
     */
    private static function isInstalled(ConfigurationOrigin $origin, ConfigurationDocument $document, string $install): bool
    {
        $locator = (string) $origin->locator();
        $path = str_starts_with($locator, '/') ? $locator : rtrim($document->workingDirectory()->value(), '/') . '/' . $locator;
        if (!self::leadsInto($path, $install)) {
            return false;
        }
        $name = $document->regularFileReadAs($path);

        return $name !== null && ($name === $origin->describe() || str_starts_with($name, 'preset "'));
    }

    /**
     * Whether the path, as spelled, enters the install directory: the components above it are
     * resolved through their links, those below are not, so a symlinked path-repository package
     * and its mirrored copy answer alike. After a link `..` leads elsewhere than its spelling says.
     */
    private static function leadsInto(string $path, string $install): bool
    {
        $prefix = '';
        $entered = false;
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($entered && $component === '..') {
                return false;
            }
            $prefix .= '/' . $component;
            if ($entered) {
                continue;
            }
            $resolved = realpath($prefix);
            if ($resolved === false) {
                return false;
            }
            $entered = self::isWithin($resolved, $install);
        }

        return $entered;
    }

    private static function isWithin(string $path, string $directory): bool
    {
        return $path === $directory || $directory === '/' || str_starts_with($path, $directory . '/');
    }
}
