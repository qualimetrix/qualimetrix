<?php

declare(strict_types=1);

/**
 * Taking the four sides of a probe against one product tree.
 *
 * Two things here are load-bearing and neither is obvious. First, the fixture
 * is copied into a scratch directory per run: a probe that writes (`-o`,
 * `--cache-dir`, `baseline:generate`) would otherwise mutate the tracked
 * fixture, and the fixture hash is part of the snapshot fingerprint, so the
 * next run would report "taken under another declaration". Second, the product
 * root is a parameter: the frozen "before" side runs a deployed archive of the
 * commit the round starts from, and a stand that hardcoded `bin/qmx` relative
 * to itself would silently measure the cured tree twice.
 */

namespace Qualimetrix\InputDoors;

use FilesystemIterator;
use Qualimetrix\Subprocess\ChildProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

require_once \dirname(__DIR__) . '/subprocess/ChildProcess.php';

final class Runner
{
    /** @var array<string, Observation> */
    private array $memo = [];

    private int $runs = 0;

    /** @param array<string, list<string>> $commandDoors CLI doors each command declares */
    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $productRoot,
        private readonly string $scratchRoot,
        private readonly array $commandDoors,
    ) {}

    public function runs(): int
    {
        return $this->runs;
    }

    /**
     * @param array<string, CommandProfile> $commands
     *
     * @return array<string, Observation> keyed M, H, A and, when declared, H0
     */
    public function observe(GridRow $row, Probe $probe, array $commands, string $observable): array
    {
        $profile = $commands[$row->command] ?? null;

        if ($profile === null) {
            throw new DeclarationError('no command profile for ' . $row->command);
        }

        $sides = [
            'M' => $probe->miss,
            'H' => $probe->hit,
            'A' => null,
        ];

        if ($probe->hasHitEmpty()) {
            $sides['H0'] = $probe->hitEmpty;
        }

        $observations = [];

        foreach ($sides as $side => $value) {
            $observations[$side] = $this->side($row, $probe, $profile, $observable, $value);
        }

        return $observations;
    }

    /** Repeats the M side, for the surface-stability mode. */
    public function repeatMiss(GridRow $row, Probe $probe, CommandProfile $profile, string $observable): Observation
    {
        return $this->side($row, $probe, $profile, $observable, $probe->miss, true);
    }

    private function side(GridRow $row, Probe $probe, CommandProfile $profile, string $observable, ?string $value, bool $fresh = false): Observation
    {
        $fixture = $probe->fixture !== '' ? $probe->fixture : $profile->fixture;
        $plan = $this->plan($row, $profile, $observable, $value, $fixture);
        $prepare = self::prepareSteps($this->repositoryRoot . '/input-doors/fixtures/' . $fixture);
        $memoKey = $fixture . "\0" . $prepare . "\0" . $observable . "\0" . implode("\0", $plan['argv']) . "\0" . $plan['config'];

        if (!$fresh && isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        // The run directory is keyed by the invocation, not by the grid row:
        // the A side of every door on one command is the very same run, and
        // ~200 rows would otherwise pay for it ~200 times.
        $runDir = $this->scratchRoot . '/run/' . md5($memoKey) . ($fresh ? '-repeat' : '');

        $this->materialize($fixture, $runDir);
        $this->prepare($prepare, $runDir);

        if ($plan['config'] !== '') {
            file_put_contents($runDir . '/qmx.probe.yaml', $plan['config']);
        }

        $observation = $this->execute($plan['argv'], $runDir, $observable);
        ++$this->runs;
        $this->memo[$memoKey] = $observation;

        return $observation;
    }

    /**
     * @return array{argv: list<string>, config: string}
     */
    private function plan(GridRow $row, CommandProfile $profile, string $observable, ?string $value, string $fixture): array
    {
        $argv = [$this->productRoot . '/bin/qmx'];

        if ($row->command !== '(global)') {
            $argv[] = $row->command;
        }

        $configDocument = '';
        $positional = [];

        foreach ($profile->positional as [$name, $default]) {
            $slot = $default;

            // The fixed run target shadows both the `paths` argument and the
            // `paths` configuration key; a probe on either is taken with the
            // target withdrawn, or it would measure the invariant.
            if ($row->shadowedBy === 'invariant:target' && $name === 'paths' && $row->door !== $name) {
                continue;
            }

            if ($row->surface === 'cli' && $row->door === $name) {
                if ($value === null) {
                    // Withdrawing a positional door must withdraw every
                    // positional after it, or the next argument slides into the
                    // vacated slot and the A side measures a different question.
                    break;
                }

                $slot = $value;
            }

            if ($slot !== '') {
                $positional[] = $slot;
            }
        }

        if ($row->surface === 'config') {
            $configDocument = $this->configDocument($row->door, $value, $fixture);
        }

        $invariants = $this->invariants($row, $profile, $observable, $configDocument !== '');

        foreach ($positional as $slot) {
            $argv[] = $slot;
        }

        foreach ($invariants as $flag) {
            $argv[] = $flag;
        }

        if ($row->surface === 'cli' && str_starts_with($row->door, '--') && $value !== null) {
            $argv[] = $row->door . '=' . $value;
        }

        return ['argv' => $argv, 'config' => $configDocument];
    }

    /**
     * A configuration probe writes the fixture's own document with one snippet
     * merged in at the top level. Shallow replacement is the only semantics a
     * YAML document has, so a snippet touching a key the base already carries
     * must restate the base's value — otherwise H differs from A because the
     * base setting vanished, not because of the door.
     */
    private function configDocument(string $door, ?string $value, string $fixture): string
    {
        $base = $this->repositoryRoot . '/input-doors/fixtures/' . $fixture . '/qmx.yaml';
        /** @var mixed $parsed */
        $parsed = is_file($base) ? Yaml::parse((string) file_get_contents($base)) : [];
        $document = \is_array($parsed) ? $parsed : [];

        if ($value !== null) {
            /** @var mixed $snippet */
            $snippet = Yaml::parse($value);

            if (!\is_array($snippet)) {
                throw new DeclarationError(\sprintf('configuration probe for %s: "%s" is not a YAML mapping', $door, $value));
            }

            foreach ($snippet as $key => $subtree) {
                $document[$key] = $subtree;
            }
        }

        return Yaml::dump($document, 6, 2);
    }

    /** @return list<string> */
    private function invariants(GridRow $row, CommandProfile $profile, string $observable, bool $hasProbeConfig): array
    {
        $declared = $this->commandDoors[$row->command] ?? [];
        $flags = [];
        $wanted = [
            'invariant:fail-on' => ['--fail-on', '--fail-on=none'],
            'invariant:workers' => ['--workers', '--workers=0'],
            'invariant:no-cache' => ['--no-cache', '--no-cache'],
        ];

        foreach ($wanted as $name => [$option, $flag]) {
            if ($row->shadowedBy === $name || !\in_array($option, $declared, true)) {
                continue;
            }

            $flags[] = $flag;
        }

        if ($row->shadowedBy !== 'invariant:format' && \in_array('--format', $declared, true) && str_starts_with($observable, 'format:')) {
            $flags[] = '--format=' . substr($observable, 7);
        }

        if ($row->shadowedBy !== 'invariant:config' && \in_array('--config', $declared, true)) {
            $flags[] = '--config=' . ($hasProbeConfig ? 'qmx.probe.yaml' : 'qmx.yaml');
        }

        return $flags;
    }

    private function materialize(string $fixture, string $runDir): void
    {
        if (is_dir($runDir)) {
            self::removeTree($runDir);
        }

        if (!mkdir($runDir, 0o775, true) && !is_dir($runDir)) {
            throw new DeclarationError('cannot create run directory ' . $runDir);
        }

        $source = $this->repositoryRoot . '/input-doors/fixtures/' . $fixture;

        if (!is_dir($source)) {
            throw new DeclarationError('missing fixture ' . $fixture);
        }

        self::copyTree($source, $runDir);
    }

    /**
     * Preconditions a command cannot run without. The git repository is built
     * here rather than tracked: a nested `.git` cannot live inside the
     * repository, and fixed author dates keep both the SHAs and the fixture
     * hash stable across machines.
     */
    public static function prepareSteps(string $fixtureDirectory): string
    {
        $declared = $fixtureDirectory . '/.prepare';

        return is_file($declared) ? trim((string) file_get_contents($declared)) : 'none';
    }

    private function prepare(string $prepare, string $runDir): void
    {
        foreach (explode('+', $prepare) as $step) {
            match ($step) {
                'git' => $this->prepareGit($runDir),
                default => null,
            };
        }
    }

    private function prepareGit(string $runDir): void
    {
        $env = [
            'GIT_AUTHOR_DATE' => '2020-01-01T00:00:00+00:00',
            'GIT_COMMITTER_DATE' => '2020-01-01T00:00:00+00:00',
        ];
        $identity = ['-c', 'user.name=stand', '-c', 'user.email=stand@example.invalid', '-c', 'init.defaultBranch=main', '-c', 'commit.gpgsign=false'];
        $this->shell(array_merge(['git', '-c', 'init.defaultBranch=main', 'init', '-q'], []), $runDir, $env);
        $this->shell(array_merge(['git'], $identity, ['add', '-A']), $runDir, $env);
        $this->shell(array_merge(['git'], $identity, ['commit', '-q', '-m', 'fixture base']), $runDir, $env);
        file_put_contents($runDir . '/src/Quiet/Touched.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture\\Quiet;\n\nfinal class Touched {}\n");
        $this->shell(array_merge(['git'], $identity, ['add', '-A']), $runDir, $env);
        $this->shell(array_merge(['git'], $identity, ['commit', '-q', '-m', 'fixture change']), $runDir, $env);
    }

    /** @param list<string> $argv
     * @param array<string, string> $env
     */
    private function shell(array $argv, string $cwd, array $env = []): void
    {
        $this->execute($argv, $cwd, 'stdout', $env);
    }

    /** @param list<string> $argv
     * @param array<string, string> $extraEnv
     */
    private function execute(array $argv, string $cwd, string $observable, array $extraEnv = []): Observation
    {
        $environment = array_merge(getenv(), $extraEnv, ['NO_COLOR' => '1', 'COLUMNS' => '120']);

        try {
            $result = ChildProcess::run($argv, $cwd, environment: $environment);
        } catch (RuntimeException $error) {
            // DeclarationError, not the bare RuntimeException run() throws:
            // Stand::run() catches DeclarationError by name around a call
            // that reaches here ($this->runner->observe(...)), to turn one
            // row's failed launch into a reported verdict instead of an
            // uncaught crash of the whole grid.
            throw new DeclarationError('cannot start ' . implode(' ', $argv) . ': ' . $error->getMessage());
        }

        $stdout = $result['stdout'];
        $stderr = $result['stderr'];
        $exit = $result['exitCode'];

        $fileTarget = str_starts_with($observable, 'file:') ? $cwd . '/' . substr($observable, 5) : null;
        $dirTarget = str_starts_with($observable, 'dir:') ? $cwd . '/' . substr($observable, 4) : null;

        return new Observation(
            $exit,
            $this->tokenize($stdout, $cwd),
            $this->tokenize($stderr, $cwd),
            $fileTarget !== null && is_file($fileTarget),
            $dirTarget !== null && is_dir($dirTarget),
        );
    }

    /**
     * Absolute paths are what `scripts/check-private-leaks.sh` forbids in a
     * tracked file, and the frozen observations are tracked. Substitution is
     * longest prefix first: the fixture run directory lives under the scratch
     * root, so the other order would make `<FIXTURE>` unreachable.
     */
    public function tokenize(string $text, string $runDir): string
    {
        $replacements = [];

        foreach ([[$runDir, '<FIXTURE>'], [$this->scratchRoot, '<SCRATCH>'], [$this->productRoot, '<PRODUCT>'], [$this->repositoryRoot, '<REPO>']] as [$path, $token]) {
            $replacements[$path] = $token;
            $resolved = realpath($path);

            if (\is_string($resolved)) {
                $replacements[$resolved] = $token;
            }
        }

        uksort($replacements, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($replacements as $path => $token) {
            $text = str_replace($path, $token, $text);
        }

        return $text;
    }

    public static function slug(string $key): string
    {
        $slug = preg_replace('~[^A-Za-z0-9._-]+~', '_', $key);

        return \is_string($slug) ? trim($slug, '_') : md5($key);
    }

    private static function copyTree(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $destination = $target . '/' . substr($item->getPathname(), \strlen($source) + 1);

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0o775, true);
                }

                continue;
            }

            copy($item->getPathname(), $destination);
        }
    }

    private static function removeTree(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
