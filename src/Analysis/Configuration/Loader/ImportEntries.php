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
 * One `imports` list as the files it leads to. An entry is a glob when it
 * holds `*`, `?` or `[`; otherwise a built-in preset name when it has no
 * directory part and no extension; otherwise a file path.
 *
 * Files are told apart by identity (device, inode, type), never by the path
 * written, so `./a.yaml`, `a.yaml` and a symlink to it are one file. A glob
 * never repeats a file the list already names, and never names the importing
 * file; two entries that name one file explicitly are refused.
 */
final class ImportEntries
{
    public function __construct(
        private readonly PresetResolver $presets = new PresetResolver(),
    ) {}

    /** Whether the entry, taken as written, names a built-in preset rather than a file. */
    public static function namesPreset(string $entry): bool
    {
        return !str_contains($entry, '/') && !str_contains($entry, '\\') && pathinfo($entry, \PATHINFO_EXTENSION) === '';
    }

    /**
     * @param list<ResolvedValueInterface> $entries
     *
     * @throws ConfigurationRefusal
     *
     * @return list<ResolvedImportEntry> in written order
     */
    public function resolve(array $entries, ImportTarget $from): array
    {
        $real = $from->realDirectory();
        $shown = $from->displayDirectory();
        $resolved = [];
        $placed = [$from->key => [-1, (string) $from->origin->locator()]];
        foreach ($entries as $position => $entry) {
            $written = (string) $entry->plain();
            if (ImportGlob::isPattern($written)) {
                continue;
            }
            $target = self::namesPreset($written)
                ? $this->presetTarget($written, $entry, $position, $from)
                : ImportTarget::ofFile(self::joined($real, $written), self::originOf(self::normalize(self::joined($shown, $written)), $from));
            $placed = self::place($placed, $position, $target->key, $entry, $from);
            $resolved[$position] = new ResolvedImportEntry($position, $entry, false, [$target]);
        }

        $taken = array_fill_keys(array_keys($placed), true);
        foreach ($entries as $position => $entry) {
            $written = (string) $entry->plain();
            if (!ImportGlob::isPattern($written)) {
                continue;
            }
            $targets = [];
            foreach (ImportGlob::matches($written, $real, $shown, self::refusing($entry, $position, $written)) as [$physical, $display]) {
                $target = ImportTarget::ofFile($physical, self::originOf($display, $from));
                if (!isset($taken[$target->key])) {
                    $taken[$target->key] = true;
                    $targets[] = $target;
                }
            }
            $resolved[$position] = new ResolvedImportEntry($position, $entry, true, $targets);
        }
        ksort($resolved);

        return array_values($resolved);
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
        $written = (string) $entry->plain();
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
    private static function refusing(ResolvedValueInterface $entry, int $position, string $written): Closure
    {
        return static fn(string $problem): never => $entry->refuse(\sprintf('Import "%s" (imports[%d]) %s.', $written, $position, $problem));
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
