<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

use JsonException;
use Qualimetrix\Core\Path\AbsolutePath;
use stdClass;

/** Pure Composer document grammar. Filesystem expansion belongs to the reader adapter. */
final class ComposerManifestDecoder
{
    public function decode(AbsolutePath $root, string $bytes): ComposerManifestFacts
    {
        $source = rtrim($root->value(), '/') . '/composer.json';
        try {
            $document = json_decode($bytes, false, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->invalid($root, ManifestIssueKind::InvalidJson, $exception->getMessage());
        }

        if (!$document instanceof stdClass) {
            return $this->invalid($root, ManifestIssueKind::InvalidRoot, 'the top-level value is not a JSON object; received ' . get_debug_type($document) . '.');
        }

        $issues = [];
        $name = $document->name ?? null;
        if (property_exists($document, 'name') && (!\is_string($name) || $name === '')) {
            $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, ['name'], 'Expected a non-empty string; received ' . get_debug_type($name) . '.');
            $name = null;
        }

        $vendorDirectory = 'vendor';
        if (property_exists($document, 'config')) {
            if (!$document->config instanceof stdClass) {
                $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, ['config'], 'Expected an object; received ' . get_debug_type($document->config) . '.');
            } elseif (property_exists($document->config, 'vendor-dir')) {
                $vendor = $document->config->{'vendor-dir'};
                if (\is_string($vendor) && $vendor !== '') {
                    $vendorDirectory = $vendor;
                } else {
                    $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, ['config', 'vendor-dir'], 'Expected a non-empty string; received ' . get_debug_type($vendor) . '.');
                }
            }
        }

        [$production, $productionComplete] = $this->section($document, 'autoload', $source, $issues);
        [$development, $developmentComplete] = $this->section($document, 'autoload-dev', $source, $issues);

        return new ComposerManifestFacts($root, ManifestReadState::Read, $name, $vendorDirectory, $production, $development, $productionComplete, $developmentComplete, $issues);
    }

    private function invalid(AbsolutePath $root, ManifestIssueKind $kind, string $detail): ComposerManifestFacts
    {
        return new ComposerManifestFacts($root, ManifestReadState::Invalid, null, 'vendor', [], [], false, false, [
            new ManifestIssue($kind, rtrim($root->value(), '/') . '/composer.json', [], $detail),
        ]);
    }

    /**
     * @param list<ManifestIssue> $issues
     *
     * @return array{array{'psr-4'?: array<string, list<string>>, 'psr-0'?: array<string, list<string>>, classmap?: list<string>, files?: list<string>}, bool}
     */
    private function section(stdClass $document, string $name, string $source, array &$issues): array
    {
        if (!property_exists($document, $name)) {
            return [[], true];
        }

        $section = $document->{$name};
        if (!$section instanceof stdClass) {
            $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, [$name], 'Expected an object; received ' . get_debug_type($section) . '.');

            return [[], false];
        }

        $before = \count($issues);
        $accepted = [];
        foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $kind) {
            if (!property_exists($section, $kind)) {
                continue;
            }

            $value = $section->{$kind};
            if ($kind === 'psr-4' || $kind === 'psr-0') {
                if (!$value instanceof stdClass) {
                    $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, [$name, $kind], 'Expected a prefix object; received ' . get_debug_type($value) . '.');
                    continue;
                }

                $map = [];
                foreach (get_object_vars($value) as $prefix => $paths) {
                    $entries = $this->paths($paths, true, $source, [$name, $kind, (string) $prefix], $issues);
                    if ($entries !== []) {
                        $map[(string) $prefix] = $entries;
                    }
                }
                $accepted[$kind] = $map;
            } else {
                $accepted[$kind] = $this->paths($value, false, $source, [$name, $kind], $issues);
            }
        }

        return [$accepted, \count($issues) === $before];
    }

    /**
     * @param list<string> $location
     * @param list<ManifestIssue> $issues
     *
     * @return list<string>
     */
    private function paths(mixed $value, bool $allowsString, string $source, array $location, array &$issues): array
    {
        if ($allowsString && \is_string($value)) {
            return [self::normalize($value)];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, $location, 'Expected ' . ($allowsString ? 'a string or ' : '') . 'a list of strings; received ' . get_debug_type($value) . '.');

            return [];
        }

        $accepted = [];
        foreach ($value as $index => $path) {
            if (\is_string($path)) {
                $accepted[] = self::normalize($path);
            } else {
                $issues[] = new ManifestIssue(ManifestIssueKind::DroppedRecord, $source, [...$location, (string) $index], 'Expected a string; received ' . get_debug_type($path) . '.');
            }
        }

        return array_values(array_unique($accepted));
    }

    private static function normalize(string $path): string
    {
        $normalized = rtrim($path, '/');

        return $normalized === '' ? '.' : $normalized;
    }
}
