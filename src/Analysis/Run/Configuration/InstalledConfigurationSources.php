<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaqueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;

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
     * configuration "installed", so such a directory establishes nothing.
     */
    private static function installDirectory(ComposerManifestFacts $facts): ?string
    {
        if ($facts->state !== ManifestReadState::Read) {
            return null;
        }
        $root = $facts->root->value();
        $vendor = $facts->vendorDirectory;
        $install = realpath(str_starts_with($vendor, '/') ? $vendor : rtrim($root, '/') . '/' . $vendor);
        $realRoot = realpath($root);
        if ($install === false || $realRoot === false || self::isWithin($realRoot, $install)) {
            return null;
        }

        return $install;
    }

    /** @return list<ConfigurationOrigin> every file or preset origin the resolved document names, once each */
    private static function fileOrigins(ConfigurationDocument $document): array
    {
        $found = [];
        foreach ($document->resolved()->roots() as $root) {
            self::collect($root, $found);
        }

        return array_values(array_filter(
            $found,
            static fn(ConfigurationOrigin $origin): bool => $origin->locator() !== null
                && ($origin->source() === ConfigurationSource::ConfigFile || $origin->source() === ConfigurationSource::Preset),
        ));
    }

    /** @param array<string, ConfigurationOrigin> $found */
    private static function collect(ResolvedValueInterface $node, array &$found): void
    {
        foreach ($node->contributors() as $provenance) {
            $found[serialize($provenance->origin)] = $provenance->origin;
        }
        if ($node instanceof ResolvedWriteHistoryInterface) {
            foreach ($node->writes() as $write) {
                $found[serialize($write['provenance']->origin)] = $write['provenance']->origin;
            }
        }
        if ($node instanceof ResolvedOpaqueInterface) {
            foreach ($node->contributions() as $contribution) {
                $found[serialize($contribution['provenance']->origin)] = $contribution['provenance']->origin;
            }
        }
        $children = match (true) {
            $node instanceof ResolvedMapInterface => $node->entries(),
            $node instanceof ResolvedListInterface => $node->items(),
            default => [],
        };
        foreach ($children as $child) {
            self::collect($child, $found);
        }
    }

    /**
     * The origin's name must lead to a regular file inside the install directory that the
     * configuration was read from under this very name. The lowest-precedence source with a
     * file's identity names it, so a different name is legitimate only for a file that also
     * sits in the preset band; any other mismatch means the locator reaches another file.
     */
    private static function isInstalled(ConfigurationOrigin $origin, ConfigurationDocument $document, string $install): bool
    {
        $locator = (string) $origin->locator();
        $path = str_starts_with($locator, '/') ? $locator : rtrim($document->workingDirectory()->value(), '/') . '/' . $locator;
        if (!self::leadsInto($path, $install)) {
            return false;
        }
        [$stat] = NativeCall::attempt(static fn(): array|false => stat($path));
        $name = $document->fileReadAs(FileIdentity::ofRegularFile($stat));

        return $name !== null
            && ($name === $origin->describe()
                || (str_starts_with($name, 'preset "') && $origin->source() !== ConfigurationSource::Preset));
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
