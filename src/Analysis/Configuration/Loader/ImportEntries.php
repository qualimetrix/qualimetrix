<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Closure;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;

/**
 * One `imports` list as the files it leads to. A string entry is a built-in
 * preset name when it has no directory part and no extension, otherwise a file
 * path; a mapping entry is a `subtree` / `regex` selector over the files below
 * the importing file's directory.
 *
 * Files are told apart by identity (device, inode, type), never by the path
 * written, so `./a.yaml`, `a.yaml` and a symlink to it are one file. A selector
 * never repeats a file the list already names, and never names the importing
 * file; two entries that name one file explicitly are refused.
 *
 * Entries are judged in written order, so the first refusal is the first
 * entry's. Only which files the list names explicitly is learned beforehand,
 * and that without refusing.
 */
final class ImportEntries
{
    public function __construct(
        private readonly PresetResolver $presets = new PresetResolver(),
    ) {}

    /**
     * @param list<ResolvedValueInterface> $entries
     *
     * @throws ConfigurationRefusal
     *
     * @return list<ResolvedImportEntry> in written order
     */
    public function resolve(array $entries, ImportTarget $from): array
    {
        $taken = $this->namedKeys($entries, $from);
        $placed = [$from->key => [-1, (string) $from->origin->locator()]];
        $resolved = [];
        foreach ($entries as $position => $entry) {
            $written = ImportListSchema::written($entry);
            $resolved[] = \is_string($written)
                ? $this->fileEntry($position, $entry, $written, $from, $placed)
                : $this->selectorEntry($position, $entry, $from, $taken);
        }

        return $resolved;
    }

    /**
     * The files the list names as strings, without judging them: what a
     * selector has to leave to them.
     *
     * @param list<ResolvedValueInterface> $entries
     *
     * @return array<string, true>
     */
    private function namedKeys(array $entries, ImportTarget $from): array
    {
        $keys = [$from->key => true];
        foreach ($entries as $entry) {
            $written = ImportListSchema::written($entry);
            if (!\is_string($written)) {
                continue;
            }
            $key = $this->keyNamed($written, $from);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    private function keyNamed(string $written, ImportTarget $from): ?string
    {
        if (!ImportListSchema::namesPreset($written)) {
            return ImportTarget::keyIfRegular(self::joined($from->realDirectory(), $written));
        }
        $path = $this->presets->builtInPath($written);

        return $path === null ? null : ImportTarget::presetKeyIfRegular($written, $path);
    }

    /** @param array<string, array{int, string}> $placed */
    private function fileEntry(int $position, ResolvedValueInterface $entry, string $written, ImportTarget $from, array &$placed): ResolvedImportEntry
    {
        $target = ImportListSchema::namesPreset($written)
            ? $this->presetTarget($written, $entry, $position, $from)
            : ImportTarget::ofFile(self::joined($from->realDirectory(), $written), self::originOf(self::normalize(self::joined($from->displayDirectory(), $written)), $from));
        $placed = self::place($placed, $position, $target->key, $entry, $from);

        return new ResolvedImportEntry($position, $entry, false, [$target]);
    }

    /** @param array<string, true> $taken */
    private function selectorEntry(int $position, ResolvedValueInterface $entry, ImportTarget $from, array &$taken): ResolvedImportEntry
    {
        $selector = ImportListSchema::selectorOf($entry);
        $targets = [];
        $matches = ImportSelection::matches($selector, $from->realDirectory(), $from->displayDirectory(), self::refusing($entry, $position));
        foreach ($matches as [$physical, $display]) {
            $target = ImportTarget::ofFile($physical, self::originOf($display, $from));
            if (!isset($taken[$target->key])) {
                $taken[$target->key] = true;
                $targets[] = $target;
            }
        }

        return new ResolvedImportEntry($position, $entry, true, $targets);
    }

    private function presetTarget(string $name, ResolvedValueInterface $entry, int $position, ImportTarget $from): ImportTarget
    {
        $path = $this->presets->builtInPath($name) ?? $entry->refuse(\sprintf(
            'Unknown preset "%s". Available presets: %s. A file needs a .yaml or .yml extension.',
            $name,
            implode(', ', PresetResolver::getAvailableNames()),
        ));

        return ImportTarget::ofPreset($name, $path, ConfigurationOrigin::of(ConfigurationSource::Preset, $name)->importedThrough($from->origin));
    }

    /**
     * @param array<string, array{int, string}> $placed key => position and the entry as written; the importing file sits at -1
     *
     * @throws ConfigurationRefusal
     *
     * @return array<string, array{int, string}>
     */
    private static function place(array $placed, int $position, string $key, ResolvedValueInterface $entry, ImportTarget $from): array
    {
        $written = (string) ImportListSchema::written($entry);
        if (isset($placed[$key])) {
            [$earlier, $earlierWritten] = $placed[$key];
            $entry->refuse($earlier === -1
                ? \sprintf('Entry imports[%d] ("%s") of %s names that file itself; a file cannot import itself.', $position, $written, $from->origin->describe())
                : \sprintf(
                    'Entries imports[%d] ("%s") and imports[%d] ("%s") of %s name the same file; list each file once.',
                    $earlier,
                    $earlierWritten,
                    $position,
                    $written,
                    $from->origin->describe(),
                ));
        }
        $placed[$key] = [$position, $written];

        return $placed;
    }

    /** @return Closure(string): never */
    private static function refusing(ResolvedValueInterface $entry, int $position): Closure
    {
        return static fn(string $problem): never => $entry->refuse(\sprintf('Import %s (imports[%d]) %s.', ImportListSchema::shown(ImportListSchema::written($entry)), $position, $problem));
    }

    private static function originOf(string $display, ImportTarget $from): ConfigurationOrigin
    {
        return ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $display)->importedThrough($from->origin);
    }

    private static function joined(string $directory, string $entry): string
    {
        return $directory === '.' || str_starts_with($entry, '/') ? $entry : rtrim($directory, '/') . '/' . $entry;
    }

    /**
     * Drops `.` and empty segments only. `x/..` is kept as written: through a
     * symlinked directory it leads elsewhere than collapsing it would say, so
     * a name always leads to the file read and two files never share one.
     */
    private static function normalize(string $path): string
    {
        $kept = array_diff(explode('/', $path), ['', '.']);

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $kept);
    }
}
