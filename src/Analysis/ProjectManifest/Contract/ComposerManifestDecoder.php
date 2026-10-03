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
        $name = $this->name($document, $source, $issues);
        $vendorDirectory = $this->vendorDirectory($document, $source, $issues);
        $production = $this->section($document, 'autoload', $source, $issues);
        $development = $this->section($document, 'autoload-dev', $source, $issues);

        return new ComposerManifestFacts($root, ManifestReadState::Read, $name, $vendorDirectory, $production, $development, $issues);
    }

    /** @param list<ManifestIssue> $issues */
    private function name(stdClass $document, string $source, array &$issues): ?string
    {
        if (!property_exists($document, 'name')) {
            return null;
        }
        if (\is_string($document->name) && $document->name !== '') {
            return $document->name;
        }
        $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, ['name'], 'Expected a non-empty string; received ' . get_debug_type($document->name) . '.');

        return null;
    }

    /** @param list<ManifestIssue> $issues */
    private function vendorDirectory(stdClass $document, string $source, array &$issues): string
    {
        if (!property_exists($document, 'config')) {
            return 'vendor';
        }
        if (!$document->config instanceof stdClass) {
            $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, ['config'], 'Expected an object; received ' . get_debug_type($document->config) . '.');

            return 'vendor';
        }
        if (!property_exists($document->config, 'vendor-dir')) {
            return 'vendor';
        }
        $vendor = $document->config->{'vendor-dir'};
        if (\is_string($vendor) && $vendor !== '') {
            return $vendor;
        }
        $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, ['config', 'vendor-dir'], 'Expected a non-empty string; received ' . get_debug_type($vendor) . '.');

        return 'vendor';
    }

    private function invalid(AbsolutePath $root, ManifestIssueKind $kind, string $detail): ComposerManifestFacts
    {
        return new ComposerManifestFacts($root, ManifestReadState::Invalid, null, 'vendor', new ComposerAutoloadSection([], false), new ComposerAutoloadSection([], false), [
            new ManifestIssue($kind, rtrim($root->value(), '/') . '/composer.json', [], $detail),
        ]);
    }

    /** @param list<ManifestIssue> $issues */
    private function section(stdClass $document, string $name, string $source, array &$issues): ComposerAutoloadSection
    {
        if (!property_exists($document, $name)) {
            return new ComposerAutoloadSection([], true);
        }
        $section = $document->{$name};
        if (!$section instanceof stdClass) {
            $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, [$name], 'Expected an object; received ' . get_debug_type($section) . '.');

            return new ComposerAutoloadSection([], false);
        }
        $before = \count($issues);
        $accepted = [];
        foreach (['psr-4', 'psr-0'] as $kind) {
            if (property_exists($section, $kind)) {
                $accepted[$kind] = $this->prefixMap($section->{$kind}, $source, [$name, $kind], $issues);
            }
        }
        foreach (['classmap', 'files'] as $kind) {
            if (property_exists($section, $kind)) {
                $accepted[$kind] = $this->listPaths($section->{$kind}, $source, [$name, $kind], $issues, 'a list of strings');
            }
        }

        return new ComposerAutoloadSection($accepted, \count($issues) === $before);
    }

    /**
     * @param list<string> $location
     * @param list<ManifestIssue> $issues
     *
     * @return array<string, list<string>>
     */
    private function prefixMap(mixed $value, string $source, array $location, array &$issues): array
    {
        if (!$value instanceof stdClass) {
            $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, $location, 'Expected a prefix object; received ' . get_debug_type($value) . '.');

            return [];
        }
        $map = [];
        foreach (get_object_vars($value) as $prefix => $paths) {
            $entries = $this->prefixPaths($paths, $source, [...$location, (string) $prefix], $issues);
            if ($entries !== []) {
                $map[(string) $prefix] = $entries;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $location
     * @param list<ManifestIssue> $issues
     *
     * @return list<string>
     */
    private function prefixPaths(mixed $value, string $source, array $location, array &$issues): array
    {
        if (\is_string($value)) {
            return [self::normalize($value)];
        }

        return $this->listPaths($value, $source, $location, $issues, 'a string or a list of strings');
    }

    /**
     * @param list<string> $location
     * @param list<ManifestIssue> $issues
     *
     * @return list<string>
     */
    private function listPaths(mixed $value, string $source, array $location, array &$issues, string $expected): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            $issues[] = new ManifestIssue(ManifestIssueKind::InvalidField, $source, $location, 'Expected ' . $expected . '; received ' . get_debug_type($value) . '.');

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
