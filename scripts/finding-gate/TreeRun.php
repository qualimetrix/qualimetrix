<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** One tree's publications, with no cache state shared across invocations. */
final class TreeRun
{
    private const CHECK_ARGUMENTS = ['--workers=0', '--no-cache', '--no-ansi', '--fail-on=error'];

    private int $sequence = 0;

    private readonly CaseInputTranslation $inputs;

    public function __construct(
        private readonly string $treeRoot,
        private readonly string $temporaryDirectory,
        private readonly string $label,
        RenameMaps $maps,
        bool $reverseInput,
        private readonly CapturePlan $plan,
        DeclaredStructuralMaps $structuralMaps,
    ) {
        $this->inputs = new CaseInputTranslation($maps, $reverseInput, $temporaryDirectory, $label, $structuralMaps);
    }

    /** @return array<string,string> */
    public function forCase(CaseDefinition $case): array
    {
        $originalCase = $case;
        $case = $this->inputs->materialize($case);
        $plan = $this->plan;
        $config = $this->inputs->configuration($case);
        $config = str_starts_with($config, '/') ? $config : $case->directory . '/' . $config;
        $arguments = $this->inputs->arguments($case);
        $check = ['check', ...$case->paths, ...self::CHECK_ARGUMENTS, '-c', $config, ...$arguments];
        $measured = [...$case->paths, '--no-ansi', '-c', $config, ...$arguments];
        $baseline = $this->scratch('baseline-' . $case->id) . '.json';
        $artifacts = [];
        $baselineWorkingDirectory = $case->directory;
        $baselineMeasured = $measured;

        foreach ($plan->invocations() as $descriptor) {
            if ($descriptor['scope'] !== 'case:' . $case->id) {
                continue;
            }
            $surface = $descriptor['surface'];
            $key = Surfaces::key($descriptor['scope'], $surface);
            if (!$plan->requiredOn($key, $this->label === 'reference' ? 'reference' : 'candidate')) {
                continue;
            }
            $cwd = $case->directory;
            $file = null;
            $assertCache = false;
            if (str_starts_with($surface, 'format:')) {
                $command = [...$check, '-f', substr($surface, 7)];
            } elseif ($surface === 'show-suppressed') {
                $command = [...$check, '-f', 'text', '--show-suppressed'];
            } elseif ($surface === 'directives') {
                $command = ['directives', ...$measured, '--format=json'];
            } elseif ($surface === 'graph:export') {
                $command = ['graph:export', ...$case->paths, '--no-ansi'];
            } elseif ($surface === 'rules') {
                $command = ['rules', '--no-ansi'];
            } elseif (str_starts_with($surface, 'debug:layer-assignment:')) {
                $subjects = $case->layerAssignmentSubjects();
                $index = array_search(substr($surface, 23), $subjects, true);
                if ($index === false) {
                    throw new GateError('The capture plan names an unknown layer assignment subject: ' . $key);
                }
                $command = ['debug:layer-assignment', $this->inputs->layerAssignmentSubjects($case)[$index], '-c', $config, '--format=json', '--no-ansi'];
            } elseif ($surface === 'check:baseline-source') {
                $sourceCase = $this->inputs->materialize($originalCase, true);
                $baselineWorkingDirectory = $sourceCase->directory;
                $sourceConfig = $sourceCase->directory . '/' . $this->inputs->configuration($sourceCase);
                $sourceArguments = $this->inputs->arguments($sourceCase);
                $baselineMeasured = [...$sourceCase->paths, '--no-ansi', '-c', $sourceConfig, ...$sourceArguments];
                $cwd = $baselineWorkingDirectory;
                $command = ['check', ...$sourceCase->paths, ...self::CHECK_ARGUMENTS, '-c', $sourceConfig, ...$sourceArguments, '-f', 'json'];
            } elseif ($surface === 'baseline-file') {
                $cwd = $baselineWorkingDirectory;
                $file = $baseline;
                $command = ['baseline:generate', $file, ...$baselineMeasured];
                $assertCache = true;
            } elseif (str_starts_with($surface, 'explain:')) {
                $command = ['baseline:explain', $this->inputs->subject(substr($surface, 8)), ...$measured];
            } elseif ($surface === 'check:output') {
                $file = $this->scratch('output-' . $case->id) . '.json';
                $command = [...$check, '-f', 'json', '--output=' . $file];
            } elseif ($surface === 'check:parallel') {
                $command = [...array_values(array_diff($check, ['--workers=0'])), '--workers=2', '-f', 'json'];
            } elseif ($surface === 'check:baseline') {
                $command = [...$check, '-f', 'json', '--baseline=' . $baseline];
            } elseif (\in_array($surface, ['baseline:update', 'baseline:cleanup', 'baseline:rename-channels'], true)) {
                $file = $this->scratch($surface . '-' . $case->id) . '.json';
                Fs::write($file, Fs::read($baseline));
                if ($surface === 'baseline:rename-channels') {
                    $map = $this->inputs->renameChannelsMap($case);
                    if ($map === null) {
                        throw new GateError('The capture plan requires a channel rename input: ' . $key);
                    }
                    $command = [$surface, $file, str_starts_with($map, '/') ? $map : $case->directory . '/' . $map, '--format=json', '--no-ansi'];
                } else {
                    $command = [$surface, $file, ...$measured];
                    if ($surface === 'baseline:cleanup') {
                        // Listing is a separate measurement, never a silent substitute for removal.
                        $listed = $this->invoke($key, $command, $cwd);
                        preg_match_all('/^  ([0-9a-f]{12})  /m', $listed['stdout'], $matches);
                        foreach ($matches[1] as $selector) {
                            $command[] = '--remove=' . $selector;
                        }
                    }
                }
            } else {
                throw new GateError('No supported command for capture invocation: ' . $key);
            }
            $result = $this->invoke($key, $command, $cwd, $assertCache);
            $side = $this->label === 'reference' ? 'reference' : 'candidate';
            if ($surface === 'check:output' && ((\in_array($result['exit'], [0, 1, 2], true)
                && CaseOutcome::of($case, $side) === CaseOutcome::ANALYSIS) || is_file((string) $file))) {
                preg_match_all('/^Report written to (.+)$/m', $result['stderr'], $destinations);
                if (!is_file((string) $file) || Fs::read((string) $file) === '' || $destinations[1] !== [$file]) {
                    throw new GateError('The output publication is missing, empty, or does not name exactly the chosen file for ' . $key . '.');
                }
            }
            $publication = $surface === 'baseline-file' ? (is_file((string) $file) ? Fs::read((string) $file) : '') : $result['stdout'];
            $artifacts[$key] = $publication;
            $exit = $surface === 'baseline-file' ? 'baseline:generate' : $surface;
            $artifacts[Surfaces::key($descriptor['scope'], 'exit:' . $exit)] = (string) $result['exit'];
            $artifacts[Surfaces::key($descriptor['scope'], 'stderr:' . $surface)] = $result['stderr'];
            if ($descriptor['outputFileKind'] !== null) {
                $artifacts[Surfaces::key($descriptor['scope'], $descriptor['outputFileKind'])] = is_file((string) $file) ? Fs::read((string) $file) : '';
            }
            foreach ($plan->artifactsOf($key) as $artifact) {
                if (!\array_key_exists($artifact, $artifacts)) {
                    throw new GateError('Capture omitted a planned artifact: ' . $artifact);
                }
            }
        }

        return $artifacts;
    }

    /**
     * Tree measurements use a neutral working directory, never a developer's root cache.
     *
     * @return array<string,string>
     */
    public function rules(): array
    {
        $cwd = $this->scratch('tree');
        Fs::write($cwd . '/empty/.keep', '');
        $artifacts = [];
        foreach ($this->plan->invocations() as $descriptor) {
            if ($descriptor['scope'] !== 'tree') {
                continue;
            }
            $surface = $descriptor['surface'];
            $command = match ($descriptor['commandClass']) {
                'rules' => ['rules', '--no-ansi'],
                'graph:export' => ['graph:export', 'empty', '--no-ansi'],
                default => throw new GateError('No neutral command for planned invocation: ' . $surface),
            };
            $key = Surfaces::key('tree', $surface);
            $result = $this->invoke($key, $command, $cwd);
            $artifacts[$key] = $result['stdout'];
            $artifacts[Surfaces::key('tree', 'stderr:' . $surface)] = $result['stderr'];
            $artifacts[Surfaces::key('tree', 'exit:' . $surface)] = (string) $result['exit'];
        }

        return $artifacts;
    }

    private function scratch(string $purpose): string
    {
        return $this->temporaryDirectory . '/capture-' . $this->label . '-' . str_replace(':', '-', $purpose) . '-' . ++$this->sequence;
    }

    /**
     * @param list<string> $command
     *
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function invoke(string $key, array $command, string $cwd, bool $assertCacheWritten = false): array
    {
        $legacy = $cwd . '/.qmx-cache';
        $xdg = $this->scratch('cache');
        Fs::removeRecursively($legacy);
        Fs::removeRecursively($xdg);
        try {
            $result = Process::run([\PHP_BINARY, $this->treeRoot . '/bin/qmx', ...$command], $cwd, environmentAdditions: [
                'XDG_CACHE_HOME' => $xdg,
                'QMX_GATE_INVOCATION' => $key,
            ]);
            if ($assertCacheWritten && $result['exit'] === 0 && !self::hasCacheRecord($legacy) && !self::hasCacheRecord($xdg . '/qmx')) {
                throw new GateError('A successful ' . $key . ' wrote no cache record in the isolated legacy or XDG cache.');
            }

            return $result;
        } finally {
            Fs::removeRecursively($legacy);
            Fs::removeRecursively($xdg);
        }
    }

    private static function hasCacheRecord(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getFilename() !== '.serializer') {
                return true;
            }
        }

        return false;
    }

}
