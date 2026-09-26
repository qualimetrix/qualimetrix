<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A finite immutable invocation table, shared by capture and value comparison.
 *
 * File artifacts refer to their invocation, so a file cannot invent another
 * process exit code. Keys use the candidate's vocabulary on both sides.
 *
 * @phpstan-type Descriptor array{scope:string,surface:string,commandClass:string,outputFileKind:string|null}
 */
final class CapturePlan
{
    /**
     * @param array<string,Descriptor> $descriptors
     * @param array<string,string> $artifactInvocations
     */
    private function __construct(
        private readonly array $descriptors,
        private readonly array $artifactInvocations,
    ) {}

    public static function forCorpus(Corpus $corpus, DeclaredSurfaces $surfaces): self
    {
        $descriptors = [];
        $artifacts = [];
        $append = static function (string $scope, string $surface, string $command, ?string $file = null) use (&$descriptors, &$artifacts): void {
            $key = Surfaces::key($scope, $surface);
            if (isset($descriptors[$key])) {
                throw new GateError('The capture plan declares an invocation twice: ' . $key);
            }
            $descriptors[$key] = ['scope' => $scope, 'surface' => $surface, 'commandClass' => $command, 'outputFileKind' => $file];
            $keys = [$key, Surfaces::key($scope, 'stderr:' . $surface),
                Surfaces::key($scope, 'exit:' . ($surface === 'baseline-file' ? 'baseline:generate' : $surface))];
            if ($file !== null) {
                $keys[] = Surfaces::key($scope, $file);
            }
            foreach ($keys as $artifact) {
                if (isset($artifacts[$artifact])) {
                    throw new GateError('The capture plan declares an artifact twice: ' . $artifact);
                }
                $artifacts[$artifact] = $key;
            }
        };
        $append('tree', 'rules', 'rules');
        $append('tree', 'graph:export', 'graph:export');
        $formats = Surfaces::FORMATS;
        foreach ($surfaces->changes() as $surface => $change) {
            if ($change !== DeclaredSurfaces::WITHDRAWN) {
                continue;
            }
            if (!str_starts_with($surface, 'format:')) {
                throw new GateError('A withdrawn surface has no known capture command: ' . $surface);
            }
            $format = substr($surface, 7);
            if ($format === '') {
                throw new GateError('A withdrawn format must have a name.');
            }
            if (!\in_array($format, $formats, true)) {
                $formats[] = $format;
            }
        }
        foreach ($corpus->cases as $case) {
            $scope = 'case:' . $case->id;
            foreach ($formats as $format) {
                $append($scope, 'format:' . $format, 'check');
            }
            foreach (['show-suppressed' => 'check', 'directives' => 'directives', 'graph:export' => 'graph:export', 'rules' => 'rules'] as $surface => $command) {
                $append($scope, $surface, $command);
            }
            $append($scope, 'baseline-file', 'baseline:generate');
            $append($scope, 'check:output', 'check', 'check:output:file');
            foreach ($case->explainSubjects as $subject) {
                $append($scope, 'explain:' . $subject, 'baseline:explain');
            }
            foreach ($case->layerAssignmentSubjects() as $subject) {
                $append($scope, 'debug:layer-assignment:' . $subject, 'debug:layer-assignment');
            }
            if ($case->baselineSource() !== null) {
                $append($scope, 'check:baseline', 'check');
                foreach (['baseline:update', 'baseline:cleanup'] as $command) {
                    $append($scope, $command, $command, $command . ':file');
                }
                if ($case->renameChannelsMap() !== null) {
                    $append($scope, 'baseline:rename-channels', 'baseline:rename-channels', 'baseline:rename-channels:file');
                }
            }
            if (self::hasParallelInput($case)) {
                $append($scope, 'check:parallel', 'check');
            }
        }
        return new self($descriptors, $artifacts);
    }

    /** @return list<Descriptor> */
    public function invocations(): array
    {
        return array_values($this->descriptors);
    }

    /** @return Descriptor */
    public function descriptorOf(string $fullInvocationKey): array
    {
        if (!isset($this->descriptors[$fullInvocationKey])) {
            throw new GateError('Unknown capture invocation: ' . $fullInvocationKey);
        }
        return $this->descriptors[$fullInvocationKey];
    }

    public function commandClassOf(string $fullInvocationKey): string
    {
        return $this->descriptorOf($fullInvocationKey)['commandClass'];
    }

    public function invocationOf(string $artifactKey): string
    {
        if (!isset($this->artifactInvocations[$artifactKey])) {
            throw new GateError('Unknown capture artifact: ' . $artifactKey);
        }
        return $this->artifactInvocations[$artifactKey];
    }

    /** @return list<string> */
    public function artifactsOf(string $fullInvocationKey): array
    {
        $this->descriptorOf($fullInvocationKey);
        return array_keys(array_filter($this->artifactInvocations, static fn(string $invocation): bool => $invocation === $fullInvocationKey));
    }

    private static function hasParallelInput(CaseDefinition $case): bool
    {
        $files = [];
        foreach ($case->paths as $path) {
            $absolute = $case->directory . '/' . $path;
            if (is_file($absolute)) {
                if (strtolower(pathinfo($absolute, \PATHINFO_EXTENSION)) === 'php') {
                    $files[realpath($absolute)] = true;
                }
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                    $files[$entry->getRealPath()] = true;
                }
            }
        }
        return \count($files) >= 100;
    }
}
