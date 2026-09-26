<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * What one side is handed of a case's inputs: the candidate's own, or — for
 * the reference — the same inputs in the vocabulary the reference knows.
 *
 * Every input the reference reads passes through here and through nothing
 * else, so a new kind of input, and a new way of translating one, is one
 * change to this class rather than a change to how a tree is run.
 */
final class CaseInputTranslation
{
    /** @var array<string,CaseDefinition> */
    private array $materialized = [];

    public function __construct(
        private readonly RenameMaps $maps,
        private readonly bool $reverseInput,
        private readonly string $temporaryDirectory,
        private readonly string $label,
        private readonly DeclaredStructuralMaps $structuralMaps,
    ) {}

    /** @return list<string> */
    public function arguments(CaseDefinition $case): array
    {
        return $this->reverseInput ? $this->maps->reverseArguments($case->args) : $case->args;
    }

    public function subject(string $subject): string
    {
        return $this->reverseInput ? $this->maps->reverseSymbol($subject) : $subject;
    }

    /** @return list<string> */
    public function layerAssignmentSubjects(CaseDefinition $case): array
    {
        return array_map($this->subject(...), $case->layerAssignmentSubjects());
    }

    public function renameChannelsMap(CaseDefinition $case): ?string
    {
        return $case->renameChannelsMap();
    }

    public function baselineSource(CaseDefinition $case): ?string
    {
        return $case->baselineSource();
    }

    public function configuration(CaseDefinition $case): string
    {
        return $case->config;
    }

    public function materialize(CaseDefinition $case, bool $baselineVariant = false): CaseDefinition
    {
        if ($this->reverseInput) {
            $this->maps->assertSymbolPathsUntouched($case->paths);
        }
        $key = $case->directory . ($baselineVariant ? ':baseline' : ':main');
        if (isset($this->materialized[$key])) {
            return $this->materialized[$key];
        }
        $directory = $this->temporaryDirectory . '/inputs-' . $this->label . '-' . \count($this->materialized) . '/' . $case->id;
        self::copy($case->directory, $directory);
        if ($baselineVariant) {
            $source = $case->baselineSource();
            if ($source === null) {
                throw new GateError('A baseline variant cannot be materialized without baseline-src/.');
            }
            foreach ($case->paths as $path) {
                Fs::removeRecursively($directory . '/' . $path);
            }
            if (file_exists($case->directory . '/' . $source . '/case.json')) {
                throw new GateError('A baseline variant may overlay inputs, not the authoritative case.json.');
            }
            self::copy($case->directory . '/' . $source, $directory);
        }
        $materialized = $case->withDirectory($directory);
        if ($this->reverseInput) {
            $translated = [];
            foreach ($materialized->inputFiles() as $input) {
                $path = realpath($directory . '/' . $input['path']);
                if ($path === false || !str_starts_with($path, $directory . '/')) {
                    throw new GateError('A materialized input escaped its case directory.');
                }
                $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
                $role = $input['option'] === 'baseline:rename-channels' ? 'channel-map'
                    : (\in_array($extension, ['yaml', 'yml'], true) ? ($input['option'] === '--preset' ? 'preset' : 'config') : $extension);
                if (isset($translated[$path])) {
                    if ($translated[$path] !== $role) {
                        throw new GateError('A physical input cannot have two different translation roles.');
                    }
                    continue;
                }
                $original = Fs::read($path);
                if ($input['option'] === 'baseline:rename-channels') {
                    $text = $this->maps->reverseChannelMap($original);
                } elseif (\in_array($extension, ['yaml', 'yml'], true)) {
                    $document = $input['option'] === '--preset' ? 'preset' : 'config';
                    $text = $this->structuralMaps->reverseDocument($document, $this->maps->reverseYaml($original));
                } elseif ($extension === 'php') {
                    $text = $this->maps->reversePhp($original);
                } elseif (basename($path) === 'composer.json') {
                    $text = $this->maps->reverseComposer($original);
                } else {
                    $this->maps->assertUnsupportedInputUntouched($original);
                    $text = $original;
                }
                if ($text !== $original) {
                    Fs::write($path, $text);
                }
                $translated[$path] = $role;
            }
        }
        return $this->materialized[$key] = $materialized;
    }

    private static function copy(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0o777, true)) {
            throw new GateError('Cannot create an independent case directory.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), \strlen($source) + 1);
            if ($relative === '.qmx-cache' || str_starts_with($relative, '.qmx-cache/')) {
                continue;
            }
            $destination = $target . '/' . $relative;
            if ($entry->isLink()) {
                $resolved = $entry->getRealPath();
                $root = realpath($source);
                if ($resolved === false || $root === false || !str_starts_with($resolved, $root . '/')) {
                    throw new GateError('A case copy cannot follow a link outside its input root: ' . $relative);
                }
                $link = readlink($entry->getPathname());
                if ($link === false) {
                    throw new GateError('A case input link could not be read.');
                }
                if (str_starts_with($link, '/')) {
                    $link = $target . '/' . substr($resolved, \strlen($root) + 1);
                }
                if (is_link($destination) || is_file($destination)) {
                    unlink($destination);
                }
                if (!symlink($link, $destination)) {
                    throw new GateError('A case input link could not be materialized.');
                }
            } elseif ($entry->isDir()) {
                if (!is_dir($destination) && !mkdir($destination, 0o777, true)) {
                    throw new GateError('A case input directory could not be materialized.');
                }
            } elseif ($entry->isFile()) {
                Fs::write($destination, Fs::read($entry->getPathname()));
            } else {
                throw new GateError('A case input is not a supported file, directory or contained link.');
            }
        }
    }
}
