<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * One tree's answers over the corpus.
 *
 * The working directory is always the case directory, and the corpus always
 * belongs to the candidate: that is what keeps a checkout location out of every
 * artifact, and what keeps product code the only thing that differs between the
 * two sides.
 *
 * That shared working directory is also where the product keeps its AST cache,
 * and the cache key names nothing about the product — it is the file bytes, the
 * PHP version and the php-parser version, all three of which the gate asserts
 * are equal between the sides. `check` is run with `--no-cache`, but
 * `baseline:generate` and `baseline:explain` have no such option, so a warm
 * `.qmx-cache` left by whoever ran first would be read as authoritative by
 * whoever runs second: the reference's baseline surface would then be computed
 * from the candidate's parser output, which is the one thing this comparison must
 * never do. So every invocation in a case directory starts and ends with that
 * directory's cache removed. Shared state between the two trees is the same
 * defect class as a symlinked `vendor/`; see ReferenceTree.
 */
final class TreeRun
{
    private const CHECK_ARGUMENTS = ['--workers=0', '--no-cache', '--no-ansi', '--fail-on=error'];

    private const CACHE_DIRECTORY = '.qmx-cache';

    private int $sequence = 0;

    public function __construct(
        private readonly string $treeRoot,
        private readonly string $temporaryDirectory,
        private readonly string $label,
        private readonly RenameMaps $maps,
        private readonly bool $reverseInput,
    ) {}

    /** @return array<string, string> */
    public function forCase(CaseDefinition $case): array
    {
        $scope = 'case:' . $case->id;
        $config = $this->configurationArgument($case);
        $arguments = $this->reverseInput ? $this->maps->reverseArguments($case->args) : $case->args;
        $artifacts = [];

        foreach (Surfaces::FORMATS as $format) {
            $artifacts += $this->capture(
                $scope,
                'format:' . $format,
                ['check', ...$case->paths, ...self::CHECK_ARGUMENTS, '-c', $config, '-f', $format, ...$arguments],
                $case->directory,
            );
        }

        // Captured as text on purpose: with `--format=json` the suppression
        // report is prepended as plain text to the JSON document, so the
        // artifact would not parse. The JSON finding payload is identical with
        // and without the flag, so nothing is lost by not asking for it there.
        $artifacts += $this->capture(
            $scope,
            'show-suppressed',
            ['check', ...$case->paths, ...self::CHECK_ARGUMENTS, '-c', $config, '-f', 'text', '--show-suppressed', ...$arguments],
            $case->directory,
        );

        $artifacts += $this->baseline($scope, $case, $config, $arguments);

        foreach ($case->explainSubjects as $subject) {
            $artifacts += $this->capture(
                $scope,
                'explain:' . $subject,
                ['baseline:explain', $this->reverseInput ? $this->maps->reverse($subject) : $subject, ...$case->paths, '--no-ansi', '-c', $config, ...$arguments],
                $case->directory,
            );
        }

        return $artifacts;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        // The tree root, not a case directory: nothing is analysed here, so
        // there is no cache to isolate — and removing `.qmx-cache` at the root
        // of the candidate would throw away the developer's own cache.
        return $this->capture('tree', 'rules', ['rules', '--no-ansi'], $this->treeRoot, isolateCache: false);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<string, string>
     */
    private function baseline(string $scope, CaseDefinition $case, string $config, array $arguments): array
    {
        $file = \sprintf('%s/baseline-%s-%s-%d.json', $this->temporaryDirectory, $this->label, $case->id, ++$this->sequence);
        $result = $this->invoke(
            ['baseline:generate', $file, ...$case->paths, '--no-ansi', '-c', $config, ...$arguments],
            $case->directory,
            isolateCache: true,
            assertCacheWritten: true,
        );

        // The file this command writes is the surface. Its stdout is not: it
        // echoes the path the gate itself chose, which differs per run by
        // construction and belongs to no tree. Its absence is not silence
        // either: Gate::checkBaselineSurfaces fails on an empty one, so two
        // failed runs cannot agree by both producing nothing.
        $artifacts = [
            Surfaces::key($scope, 'baseline-file') => is_file($file) ? Fs::read($file) : '',
            Surfaces::key($scope, 'exit:baseline:generate') => (string) $result['exit'],
        ];

        if (trim($result['stderr']) !== '') {
            $artifacts[Surfaces::key($scope, 'stderr:baseline:generate')] = $result['stderr'];
        }

        return $artifacts;
    }

    /**
     * @param list<string> $command
     *
     * @return array<string, string>
     */
    private function capture(string $scope, string $surface, array $command, string $workingDirectory, bool $isolateCache = true): array
    {
        $result = $this->invoke($command, $workingDirectory, $isolateCache);
        $artifacts = [
            Surfaces::key($scope, $surface) => $result['stdout'],
            Surfaces::key($scope, 'exit:' . $surface) => (string) $result['exit'],
        ];

        if (trim($result['stderr']) !== '') {
            $artifacts[Surfaces::key($scope, 'stderr:' . $surface)] = $result['stderr'];
        }

        return $artifacts;
    }

    /**
     * @param list<string> $command
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function invoke(array $command, string $workingDirectory, bool $isolateCache, bool $assertCacheWritten = false): array
    {
        $cache = $workingDirectory . '/' . self::CACHE_DIRECTORY;

        if ($isolateCache) {
            Fs::removeRecursively($cache);
        }

        $result = Process::run([\PHP_BINARY, $this->treeRoot . '/bin/qmx', ...$command], $workingDirectory);

        if ($isolateCache) {
            // Asserted on the one command that runs with the cache enabled: if
            // it wrote no cache where we just cleared one, the product caches
            // somewhere else now and this isolation guards nothing. A silent
            // pass here would restore the exact defect it exists to prevent.
            $written = is_dir($cache);
            Fs::removeRecursively($cache);

            if ($assertCacheWritten && !$written && $result['exit'] === 0) {
                throw new GateError(\sprintf(
                    'A successful "%s" in %s wrote no %s, so the gate no longer knows where the two trees could share'
                    . ' parser output. Find the cache location and isolate that instead.',
                    $command[0],
                    $workingDirectory,
                    self::CACHE_DIRECTORY,
                ));
            }
        }

        return $result;
    }

    /**
     * The reference binary cannot be addressed in a vocabulary it does not know
     * yet, so its configuration is rewritten through the reverse map. When the
     * rewrite changes nothing the case's own file is used as is — and then no
     * artifact can name a temporary path, which is why this is not just an
     * optimisation.
     */
    private function configurationArgument(CaseDefinition $case): string
    {
        if (!$this->reverseInput || $this->maps->isIdentity()) {
            return $case->config;
        }

        $original = Fs::read($case->directory . '/' . $case->config);
        $reversed = $this->maps->reverse($original);

        if ($reversed === $original) {
            return $case->config;
        }

        $path = \sprintf('%s/config-%s-%s-%s', $this->temporaryDirectory, $this->label, $case->id, basename($case->config));
        Fs::write($path, $reversed);

        return $path;
    }
}
