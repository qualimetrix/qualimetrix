<?php

declare(strict_types=1);

namespace QmxFindingGate;

use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A finite immutable invocation table, shared by capture and value comparison.
 *
 * File artifacts refer to their invocation, so a file cannot invent another
 * process exit code. Keys use the candidate's vocabulary on both sides.
 *
 * @phpstan-type Descriptor array{scope:string,surface:string,commandClass:string,outputFileKind:string|null,publicationKind:'finding-json'|'other',rankingSource:string|null}
 */
final class CapturePlan
{
    /**
     * @param array<string,Descriptor> $descriptors
     * @param array<string,string> $artifactInvocations
     * @param array<string,string> $surfaceChanges
     */
    private function __construct(
        private readonly array $descriptors,
        private readonly array $artifactInvocations,
        private readonly array $surfaceChanges,
    ) {}

    public static function forCorpus(Corpus $corpus, DeclaredSurfaces $surfaces): self
    {
        $descriptors = [];
        $artifacts = [];
        $append = static function (
            string $scope,
            string $surface,
            string $command,
            ?string $file = null,
            string $publicationKind = 'other',
            ?string $rankingSource = null,
        ) use (&$descriptors, &$artifacts): void {
            $key = Surfaces::key($scope, $surface);
            if (isset($descriptors[$key])) {
                throw new GateError('The capture plan declares an invocation twice: ' . $key);
            }
            if (!\in_array($publicationKind, ['finding-json', 'other'], true)) {
                throw new GateError('Unknown capture publication kind: ' . $publicationKind);
            }
            $descriptors[$key] = [
                'scope' => $scope,
                'surface' => $surface,
                'commandClass' => $command,
                'outputFileKind' => $file,
                'publicationKind' => $publicationKind,
                'rankingSource' => $rankingSource,
            ];
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
        $withdrawnFormats = [];
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
            $withdrawnFormats[$format] = $surface;
        }
        foreach ($corpus->cases as $case) {
            $scope = 'case:' . $case->id;
            $formats = Surfaces::FORMATS;
            foreach ($withdrawnFormats as $format => $surface) {
                if ($surfaces->changeFor($surface, $case->id) === null) {
                    continue;
                }
                if (CaseOutcome::of($case, 'reference') !== CaseOutcome::ANALYSIS) {
                    throw new GateError('Case ' . $case->id . ' cannot establish a withdrawal without a reference analysis.');
                }
                if (!\in_array($format, $formats, true)) {
                    $formats[] = $format;
                }
            }
            foreach ($formats as $format) {
                $append(
                    $scope,
                    'format:' . $format,
                    'check',
                    null,
                    $format === 'json' ? 'finding-json' : 'other',
                    $format === 'json' ? Surfaces::key($scope, 'format:json') : null,
                );
            }
            foreach (['show-suppressed' => 'check', 'directives' => 'directives', 'graph:export' => 'graph:export', 'rules' => 'rules'] as $surface => $command) {
                $append($scope, $surface, $command);
            }
            if ($case->baselineSource() !== null) {
                $append($scope, 'check:baseline-source', 'check', null, 'finding-json', Surfaces::key($scope, 'check:baseline-source'));
            }
            $append($scope, 'baseline-file', 'baseline:generate');
            $append($scope, 'check:output', 'check', 'check:output:file', 'finding-json', Surfaces::key($scope, 'format:json'));
            foreach ($case->explainSubjects as $subject) {
                $append($scope, 'explain:' . $subject, 'baseline:explain');
            }
            foreach ($case->layerAssignmentSubjects() as $subject) {
                $append($scope, 'debug:layer-assignment:' . $subject, 'debug:layer-assignment');
            }
            if ($case->baselineSource() !== null) {
                $append($scope, 'check:baseline', 'check', null, 'finding-json', Surfaces::key($scope, 'check:baseline'));
                foreach (['baseline:update', 'baseline:cleanup'] as $command) {
                    $append($scope, $command, $command, $command . ':file');
                }
                if ($case->renameChannelsMap() !== null) {
                    $append($scope, 'baseline:rename-channels', 'baseline:rename-channels', 'baseline:rename-channels:file');
                }
            }
            if (self::hasParallelInput($case)) {
                $append($scope, 'check:parallel', 'check', null, 'finding-json', Surfaces::key($scope, 'format:json'));
            }
        }
        foreach ($descriptors as $key => $descriptor) {
            $source = $descriptor['rankingSource'];
            if ($descriptor['publicationKind'] === 'other') {
                if ($source !== null) {
                    throw new GateError('A non-finding publication cannot borrow ranking evidence: ' . $key);
                }
                continue;
            }
            if ($source === null || !isset($descriptors[$source])
                || $descriptors[$source]['publicationKind'] !== 'finding-json'
                || $descriptors[$source]['scope'] !== $descriptor['scope']
                || $descriptors[$source]['rankingSource'] !== $source) {
                throw new GateError('A finding JSON invocation has no exact own ranking source: ' . $key);
            }
        }
        $changes = [];
        foreach ($descriptors as $key => $descriptor) {
            $case = str_starts_with($descriptor['scope'], 'case:') ? substr($descriptor['scope'], 5) : null;
            $change = $surfaces->changeFor($descriptor['surface'], $case);
            if ($change !== null) {
                $changes[$key] = $change;
            }
        }

        return new self($descriptors, $artifacts, $changes);
    }

    /** @param array<string,string> $artifacts */
    public static function partialViewRefusal(CaseDefinition $case, string $surface, array $artifacts): bool
    {
        if (!\in_array($surface, ['format:gitlab', 'format:checkstyle'], true)) {
            return false;
        }
        $selector = null;
        foreach ($case->args as $argument) {
            foreach (['--namespace', '--class'] as $option) {
                if (str_starts_with($argument, $option . '=') && $argument !== $option . '=') {
                    $selector = $option;
                }
            }
        }
        $key = 'case:' . $case->id . '|';
        if ($selector === null || ($artifacts[$key . 'exit:' . $surface] ?? '') !== '3'
            || !isset($artifacts[$key . $surface], $artifacts[$key . 'stderr:' . $surface])) {
            return false;
        }
        $message = \sprintf('Configuration error: Format "%s" has no place to say the report is a partial view: its consumer reads every entry as a finding. Drop %s, or use a format that says what the selection left out, such as json, sarif or github.', substr($surface, 7), $selector);
        $stdout = $artifacts[$key . $surface];
        $stderr = $artifacts[$key . 'stderr:' . $surface];
        if ($surface === 'format:checkstyle') {
            return $stdout === '' && str_starts_with($stderr, $message . "\nSource: option " . $selector . ".\nDocs: ");
        }
        try {
            $document = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        return $stderr === '' && $document === ['error' => $message, 'exit_code' => 3, 'position' => null, 'source' => [['kind' => 'cli', 'name' => $selector, 'imported_by' => null]]];
    }

    /** @return list<Descriptor> */
    public function invocations(): array
    {
        return array_values($this->descriptors);
    }

    /** @return list<Descriptor> */
    public function rankingInvocations(): array
    {
        return array_values(array_filter(
            $this->descriptors,
            static fn(array $descriptor): bool => $descriptor['publicationKind'] === 'finding-json'
                && $descriptor['rankingSource'] === Surfaces::key($descriptor['scope'], $descriptor['surface']),
        ));
    }

    public function rankingSourceOf(string $jsonArtifactKey): string
    {
        $descriptor = $this->descriptorOf($this->invocationOf($jsonArtifactKey));
        $publication = Surfaces::key($descriptor['scope'], $descriptor['outputFileKind'] ?? $descriptor['surface']);
        if ($jsonArtifactKey !== $publication || $descriptor['publicationKind'] !== 'finding-json'
            || $descriptor['rankingSource'] === null) {
            throw new GateError('No ranking source for this artifact: ' . $jsonArtifactKey);
        }
        return $descriptor['rankingSource'];
    }

    public function requiredOn(string $fullInvocationKey, string $side): bool
    {
        $this->descriptorOf($fullInvocationKey);
        if (!\in_array($side, ['candidate', 'reference'], true)) {
            throw new GateError('Unknown capture side: ' . $side);
        }
        return $side === 'candidate'
            || ($this->surfaceChanges[$fullInvocationKey] ?? null) !== DeclaredSurfaces::INTRODUCED;
    }

    public function changeOf(string $fullInvocationKey): ?string
    {
        $this->descriptorOf($fullInvocationKey);

        return $this->surfaceChanges[$fullInvocationKey] ?? null;
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
