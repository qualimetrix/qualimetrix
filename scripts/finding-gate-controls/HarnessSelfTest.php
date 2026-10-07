<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\GateReport;
use QmxFindingGate\Tsv;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * The harness's own mechanics, checked without running a control.
 *
 * Kept apart from the gate's self-test because the dependency runs one way:
 * the harness drives the gate, and the gate must not load the harness to test
 * itself.
 */
final class HarnessSelfTest
{
    /** @var list<string> */
    private array $failures = [];

    /** @return list<string> */
    public function run(): array
    {
        $this->controlCloneOwnsItsRepository();
        $this->referenceResolvesBeforeTheClone();
        $this->greenIsHeldToEveryDeclarationCount();
        $this->exactScopesDoNotAcceptNeighbouringPublications();
        $this->inheritedPermissionsAreClearedOnlyInThePrivateTree();
        $this->childStreamsArriveBeforeExitAndRetainTheirBuffers();

        return $this->failures;
    }

    private function exactScopesDoNotAcceptNeighbouringPublications(): void
    {
        $class = \QmxFindingGate\FailureClass::SURFACE_MISMATCH;
        $scope = 'case:alpha|format:text';
        $expectation = new Expectation($class, $scope, exactScope: true);
        $this->same(true, $expectation->matches($class, $scope), 'an exact expectation matches its complete scope');
        foreach (['case:alpha|format:text-verbose', 'candidate / ' . $scope, $scope . '|record:{}', 'case:alpha-neighbour|format:text'] as $neighbour) {
            $this->same(false, $expectation->matches($class, $neighbour), 'an exact expectation rejects ' . $neighbour);
        }
        $this->same(false, $expectation->matches(\QmxFindingGate\FailureClass::VALUE_MISMATCH, $scope), 'the exact scope does not license another failure class');
        $this->same(true, (new Expectation($class, 'case:alpha'))->matches($class, $scope), 'an explicitly broad expectation retains its declared substring matching');
        foreach ([null, ''] as $absent) {
            $refused = false;
            try {
                new Expectation($class, $absent, exactScope: true);
            } catch (RuntimeException) {
                $refused = true;
            }
            $this->same(true, $refused, 'an exact expectation without a scope is refused');
        }

        $directory = Shell::temporaryDirectory('harness-self-test-exact-surface-');
        $report = $directory . '/report.json';
        $run = ['stdout' => '', 'stderr' => '', 'exit' => 1];
        $exact = 'case:alpha|baseline-file';
        $required = ['class' => \QmxFindingGate\FailureClass::RECORD_UNDECLARED, 'scope' => 'case:alpha|format:json'];
        $control = Control::red(
            'probe',
            'a required record failure with a secondary declared surface result',
            Mutation::none(),
            [new Expectation($required['class'], $required['scope'], exactScope: true)],
        );

        try {
            file_put_contents($report, (string) json_encode(['failures' => [
                $required,
                ['class' => \QmxFindingGate\FailureClass::DELTA_MISMATCH, 'scope' => $exact],
            ]], \JSON_THROW_ON_ERROR));
            $outcome = Outcome::of($control, $run, $report, declaredExactSurfaces: [$exact]);
            $this->same(true, $outcome->asDeclared, 'an exact-surface delta is declaration noise beside a required red mechanism');

            file_put_contents($report, (string) json_encode(['failures' => [
                $required,
                ['class' => \QmxFindingGate\FailureClass::SURFACE_MISMATCH, 'scope' => $exact],
            ]], \JSON_THROW_ON_ERROR));
            $outcome = Outcome::of($control, $run, $report, declaredExactSurfaces: [$exact]);
            $this->same(false, $outcome->asDeclared, 'an exact-surface declaration cannot absorb an unrelated surface mismatch');
        } finally {
            Shell::removeRecursively($directory);
        }
    }

    private function inheritedPermissionsAreClearedOnlyInThePrivateTree(): void
    {
        $repository = \dirname(__DIR__, 2);
        $originalMap = Shell::read($repository . '/finding-gate/maps/channels.tsv');
        $originalDelta = Shell::read($repository . '/finding-gate/declared-delta.tsv');
        $scratch = null;

        try {
            $scratch = Scratch::contentOf($repository);
            $harness = (new ReflectionClass(Harness::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(Harness::class, 'repository'))->setValue($harness, $scratch->tree);
            $hasPermissions = new ReflectionMethod(Harness::class, 'hasInheritedPermissions');
            $prepare = new ReflectionMethod(Harness::class, 'prepareIdentityPermissions');

            Shell::replace($scratch->path('finding-gate/maps/channels.tsv'), "invalid header\n");
            $refused = false;
            try {
                $hasPermissions->invoke($harness);
            } catch (Throwable) {
                $refused = true;
            }
            $this->same(true, $refused, 'an invalid inherited map is refused before preparation');
            $this->same("invalid header\n", Shell::read($scratch->path('finding-gate/maps/channels.tsv')), 'the invalid original permission has not been cleared');

            Shell::replace($scratch->path('finding-gate/maps/channels.tsv'), Tsv::render(['old', 'new', 'reason'], [
                ['code-smell.unused-private', 'code-smell.unused-privat2', 'A private inherited transition.'],
            ]));
            $productPath = 'src/Analysis/Evidence/CodeSmell/UnusedPrivateRule.php';
            $originalProduct = Shell::read($repository . '/' . $productPath);
            $dirtyProduct = $originalProduct . "\n// A private working-tree variation.\n";
            Shell::replace($scratch->path($productPath), $dirtyProduct);
            $this->same(true, $hasPermissions->invoke($harness), 'the inherited transition is present before preparation');
            $orphan = $scratch->path('finding-gate/declared-outcomes');
            if (!is_dir($orphan)) {
                mkdir($orphan);
            }
            file_put_contents($orphan . '/orphan.txt', 'A private orphan.');
            $prepare->invoke(null, $scratch);

            foreach ((new ReflectionMethod(Harness::class, 'permissionTables'))->invoke(null) as $path => $columns) {
                if (is_file($repository . '/' . $path)) {
                    $this->same(Tsv::render($columns, []), Shell::read($scratch->path($path)), $path . ' is header-only in the comparison tree');
                } else {
                    $this->same(false, is_file($scratch->path($path)), $path . ' stays absent');
                }
            }
            foreach ((new ReflectionMethod(Harness::class, 'permissionDirectories'))->invoke(null) as $directory) {
                $this->same(false, is_dir($scratch->path($directory)), $directory . ' is absent in the comparison tree');
            }
            $this->same(false, $hasPermissions->invoke($harness), 'the prepared child cannot delegate again');
            $this->same($dirtyProduct, Shell::read($scratch->path($productPath)), 'private product bytes survive identity preparation');
            $this->same($originalProduct, Shell::read($repository . '/' . $productPath), 'the original product bytes stay intact');
            $this->same($originalMap, Shell::read($repository . '/finding-gate/maps/channels.tsv'), 'the original map retains its bytes');
            $this->same($originalDelta, Shell::read($repository . '/finding-gate/declared-delta.tsv'), 'the original delta index retains its bytes');

            $commit = str_repeat('a', 40);
            (new ReflectionProperty(Harness::class, 'reference'))->setValue($harness, $commit);
            (new ReflectionProperty(Harness::class, 'reportDirectory'))->setValue($harness, 'relative-reports');
            (new ReflectionProperty(Harness::class, 'referenceName'))->setValue($harness, 'HEAD');
            $delegationCommand = new ReflectionMethod(Harness::class, 'delegationCommand');
            $this->same(
                [
                    \PHP_BINARY,
                    $scratch->path('scripts/finding-gate-controls.php'),
                    '--reference=' . $commit,
                    '--only=positive,rename-no-map',
                    '--jobs=2',
                    '--force-expect=positive:surface-mismatch',
                    '--detached',
                    '--report-dir=' . getcwd() . '/relative-reports',
                ],
                $delegationCommand->invoke($harness, $scratch, ['positive', 'rename-no-map'], ['positive' => 'surface-mismatch'], 2, false),
                'the child receives the resolved reference and original absolute report destination',
            );

            $factoryProbe = <<<'PHP'
                require getcwd() . '/scripts/finding-gate/classes.php';
                require getcwd() . '/scripts/finding-gate-controls/classes.php';
                $controls = \QmxFindingGateControls\Controls::all();
                $map = null;
                $restoration = null;
                foreach ($controls as $control) {
                    if ($control->id === 'fingerprint-declared-rename') {
                        foreach ((new ReflectionProperty(\QmxFindingGateControls\Mutation::class, 'actions'))->getValue($control->mutation) as $action) {
                            if ($action['path'] === 'finding-gate/maps/channels.tsv') {
                                $map = $action['contents'];
                            }
                        }
                    }
                    if ($control->id === 'derive-writes-green-run') {
                        $restoration = [$control->restoredAfterRun, $control->restoredContent];
                    }
                }
                echo json_encode([
                    'ids' => array_map(static fn($control): string => $control->id, $controls),
                    'map' => $map,
                    'restoration' => $restoration,
                ], JSON_THROW_ON_ERROR);
                PHP;
            $factory = Shell::run([\PHP_BINARY, '-r', $factoryProbe], $scratch->tree);
            $this->same(0, $factory['exit'], 'the prepared tree builds every control factory: ' . $factory['stderr']);
            if ($factory['exit'] === 0) {
                $metadata = json_decode($factory['stdout'], true, 512, \JSON_THROW_ON_ERROR);
                $ids = $metadata['ids'];
                $this->same(30, \count($ids), 'the prepared tree retains all 30 controls');
                $this->same('positive', $ids[0] ?? null, 'the first control keeps its place');
                $this->same('report-value-no-row', $ids[20] ?? null, 'the fixed factories keep their order');
                $this->same(
                    Tsv::render(['old', 'new', 'reason'], [])
                        . "code-smell.unused-private\tcode-smell.unused-privat2\tthe control renames the channel's code\n",
                    $metadata['map'],
                    'the own map carries one control row and no inherited transition',
                );
                $this->same(
                    ['finding-gate/declared-delta.tsv', 'finding-gate/declared-delta'],
                    $metadata['restoration'][0],
                    'the derive control keeps the index and directory restoration targets',
                );
                $this->same(
                    ['finding-gate/declared-delta.tsv' => Tsv::render(['surface', 'file', 'reason'], [])],
                    $metadata['restoration'][1],
                    'the derive control restores the identity index bytes',
                );
            }
        } catch (Throwable $error) {
            $this->failures[] = 'a private comparison context (' . $error->getMessage() . ')';
        } finally {
            $scratch?->remove();
        }
    }

    private function childStreamsArriveBeforeExitAndRetainTheirBuffers(): void
    {
        $events = [];
        $child = null;
        $script = 'fwrite(STDOUT,"first\\n"); fflush(STDOUT); usleep(250000); fwrite(STDERR,"second\\n"); exit(7);';
        $child = Shell::start([\PHP_BINARY, '-r', $script], \dirname(__DIR__, 2), onOutput: static function (int $stream, string $chunk) use (&$events, &$child): void {
            $events[] = [$stream, $chunk, $child?->settled()];
        });
        while (!$child->settled()) {
            Shell::poll();
        }
        $result = $child->result();
        $this->same([1, "first\n", false], $events[0] ?? null, 'stdout arrives before child exit');
        $this->same([2, "second\n", false], $events[1] ?? null, 'stderr arrives before child exit');
        $this->same('first' . "\n", $result['stdout'], 'streamed stdout remains buffered once');
        $this->same('second' . "\n", $result['stderr'], 'streamed stderr remains buffered once');
        $this->same(7, $result['exit'], 'the child exit code survives streaming');

        $interruption = <<<'PHP'
            require $argv[1] . '/scripts/finding-gate-controls/Shell.php';
            require $argv[1] . '/scripts/finding-gate-controls/Scratch.php';
            $scratch = \QmxFindingGateControls\Scratch::contentOf($argv[1]);
            register_shutdown_function(static function (): void {
                \QmxFindingGateControls\Shell::terminateAll();
                \QmxFindingGateControls\Scratch::removeAll();
            });
            \QmxFindingGateControls\Shell::superviseFor(false, static function (string $reason): void {});
            echo $scratch->tree, "\n";
            fflush(STDOUT);
            \QmxFindingGateControls\Shell::start([PHP_BINARY, '-r', 'sleep(30);'], $scratch->tree);
            \QmxFindingGateControls\Shell::requestStop('self-test');
            \QmxFindingGateControls\Shell::poll();
            PHP;
        $stopped = Shell::run([\PHP_BINARY, '-r', $interruption, \dirname(__DIR__, 2)], \dirname(__DIR__, 2));
        $this->same(130, $stopped['exit'], 'a supervised interruption keeps its exit status');
        $this->same(false, is_dir(trim($stopped['stdout'])), 'a supervised interruption removes its private tree');
    }

    /**
     * A control's clone is a repository of its own, also when the developer's
     * checkout is a linked worktree.
     *
     * There `.git` is a `gitdir:` pointer file, and the copy the clone used to
     * take of it pointed straight back: every control's gate then added, pruned
     * and removed its reference checkout in the developer's repository, several
     * at once. The linked worktree carries a commit and a staged file of its
     * own, so the clone is also held to reading as that checkout does.
     */
    private function controlCloneOwnsItsRepository(): void
    {
        $repository = null;
        $outside = null;
        $scratch = null;

        try {
            $repository = self::throwawayRepository();
            $outside = Shell::temporaryDirectory('harness-self-test-linked-worktree-');
            $linked = $outside . '/linked';
            $branch = self::git($repository, 'symbolic-ref', '--short', 'HEAD');
            self::git($repository, 'worktree', 'add', '--quiet', '-b', 'linked', $linked, 'HEAD');
            file_put_contents($linked . '/committed.txt', "linked\n");
            self::git($linked, 'add', 'committed.txt');
            self::git($linked, '-c', 'user.email=self-test@qmx', '-c', 'user.name=self-test', 'commit', '--quiet', '--message', 'linked');
            file_put_contents($linked . '/staged.txt', "staged\n");
            self::git($linked, 'add', 'staged.txt');
            $registered = self::registeredWorktrees($repository);

            $scratch = Scratch::cloneOf($linked);
            $this->same(
                realpath($scratch->tree . '/.git'),
                realpath(self::git($scratch->tree, 'rev-parse', '--path-format=absolute', '--git-common-dir')),
                'the clone of a linked worktree resolves to a repository of its own',
            );
            $this->same(
                self::git($linked, 'rev-parse', 'HEAD', $branch),
                self::git($scratch->tree, 'rev-parse', 'HEAD', $branch),
                'the clone reads the worktree\'s HEAD and every branch of its repository',
            );
            $this->same(
                self::git($linked, 'diff', '--cached', '--name-only'),
                self::git($scratch->tree, 'diff', '--cached', '--name-only'),
                'and the worktree\'s staged state',
            );

            self::git($scratch->tree, 'worktree', 'add', '--quiet', '--detach', $scratch->beside('reference') . '/tree', 'HEAD');
            $this->same(
                $registered,
                self::registeredWorktrees($repository),
                'a worktree the clone adds is registered in the clone, never in the repository it was cloned from',
            );

            $guard = (new ReflectionClass(Harness::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(Harness::class, 'repository'))->setValue($guard, $linked);
            $workingTreeState = new ReflectionMethod(Harness::class, 'workingTreeState');
            $before = $workingTreeState->invoke($guard);
            file_put_contents($scratch->path('committed.txt'), "hardlink write-through\n");
            $this->same(false, $before === $workingTreeState->invoke($guard), 'the original-tree guard sees a write through a hardlink');
        } catch (RuntimeException $error) {
            $this->failures[] = 'a control\'s clone owns its repository (' . $error->getMessage() . ')';
        } finally {
            $scratch?->remove();

            if ($repository !== null) {
                foreach (self::registeredWorktrees($repository) as $path) {
                    Shell::run(['git', '-C', $repository, 'worktree', 'remove', '--force', '--force', $path], $repository);
                }

                Shell::removeRecursively($repository);
            }

            if ($outside !== null) {
                Shell::removeRecursively($outside);
            }
        }
    }

    /**
     * A reference only the developer's repository can resolve still reaches
     * every control, as the commit it names.
     *
     * The clone carries refs and objects, not reflogs, pseudo-refs or branch
     * configuration: `HEAD@{1}` and `ORIG_HEAD` resolve in the checkout and
     * nowhere else. Here both name a commit no ref reaches any more.
     */
    private function referenceResolvesBeforeTheClone(): void
    {
        $repository = null;
        $scratch = null;

        try {
            $repository = self::throwawayRepository();
            file_put_contents($repository . '/dropped.txt', "dropped\n");
            self::git($repository, 'add', 'dropped.txt');
            self::git($repository, '-c', 'user.email=self-test@qmx', '-c', 'user.name=self-test', 'commit', '--quiet', '--message', 'dropped');
            $dropped = self::git($repository, 'rev-parse', 'HEAD');
            self::git($repository, 'reset', '--quiet', '--hard', 'HEAD~1');

            foreach (['HEAD@{1}', 'ORIG_HEAD', $dropped] as $reference) {
                $this->same($dropped, Harness::resolveReference($repository, $reference), \sprintf('"%s" resolves to the commit it names', $reference));
            }

            $scratch = Scratch::cloneOf($repository);
            $this->same(
                $dropped,
                self::git($scratch->tree, 'rev-parse', '--verify', '--quiet', $dropped . '^{commit}'),
                'the clone carries that commit although no ref reaches it',
            );
            $this->same(
                1,
                Shell::run(['git', 'rev-parse', '--verify', '--quiet', 'HEAD@{1}'], $scratch->tree)['exit'],
                'while the reflog form the developer wrote means nothing there',
            );

            $refused = false;

            try {
                Harness::resolveReference($repository, str_repeat('0', 40));
            } catch (RuntimeException) {
                $refused = true;
            }

            $this->same(true, $refused, 'a reference that names no commit is refused before any clone is made');
        } catch (RuntimeException $error) {
            $this->failures[] = 'a reference resolves before the clone (' . $error->getMessage() . ')';
        } finally {
            $scratch?->remove();

            if ($repository !== null) {
                Shell::removeRecursively($repository);
            }
        }
    }

    /**
     * Never the developer's repository: a case that fails halfway would
     * otherwise leave behind exactly the registration it exists to refuse.
     * `vendor/` is committed so the linked worktree checks it out: a clone
     * refuses a tree without one.
     */
    /**
     * A green control is held to every declaration form's count, not only to
     * the declared deltas and field moves: a declaration its mutation did not
     * state would otherwise be what turned it green.
     */
    private function greenIsHeldToEveryDeclarationCount(): void
    {
        $directory = Shell::temporaryDirectory('harness-self-test-counts-');
        $report = $directory . '/report.json';
        $run = ['stdout' => '', 'stderr' => '', 'exit' => 0];

        try {
            $counts = array_fill_keys(array_keys(GateReport::DECLARATION_COUNTS), 0);
            file_put_contents($report, (string) json_encode(['failures' => [], 'declaredDeltaCount' => 0, 'declaredExactSurfaceCount' => 0, 'exactSurfaceUsedCount' => 0, 'fieldMoveCount' => 0, ...$counts, 'declaredRecordCount' => 1]));

            $this->same(
                false,
                Outcome::of(Control::green('probe', 'a green run'), $run, $report)->asDeclared,
                'a green control whose run states a declared record nobody planted is not as declared',
            );
            $this->same(
                true,
                Outcome::of(Control::greenWith('probe', 'a planted record', Mutation::none(), ['declaredRecordCount' => 1]), $run, $report)->asDeclared,
                'a green control that states the record its mutation plants is as declared',
            );
            $this->same(
                true,
                Outcome::of(Control::green('probe', 'the repository\'s record'), $run, $report, declarationCounts: ['declaredRecordCount' => 1])->asDeclared,
                'a green control is held to the declarations the repository states',
            );
        } finally {
            Shell::removeRecursively($directory);
        }
    }

    private static function throwawayRepository(): string
    {
        $root = Shell::temporaryDirectory('harness-self-test-repository-');
        mkdir($root . '/vendor');
        file_put_contents($root . '/vendor/autoload.php', "<?php\n");
        file_put_contents($root . '/tracked.txt', "tracked\n");
        self::git($root, 'init', '--quiet');
        self::git($root, 'add', '--all');
        self::git($root, '-c', 'user.email=self-test@qmx', '-c', 'user.name=self-test', 'commit', '--quiet', '--message', 'fixture');

        return $root;
    }

    private static function git(string $directory, string ...$arguments): string
    {
        return trim(Shell::mustRun(['git', ...array_values($arguments)], $directory));
    }

    /**
     * Asked of git rather than of the filesystem: the defect is a registration
     * that outlives its directory.
     *
     * @return list<string>
     */
    private static function registeredWorktrees(string $repository): array
    {
        $listed = Shell::run(['git', '-C', $repository, 'worktree', 'list', '--porcelain'], $repository);
        $paths = [];

        foreach (explode("\n", $listed['stdout']) as $line) {
            if (str_starts_with($line, 'worktree ') && realpath(substr($line, 9)) !== realpath($repository)) {
                $paths[] = substr($line, 9);
            }
        }

        return $paths;
    }

    private function same(mixed $expected, mixed $actual, string $description): void
    {
        if ($expected !== $actual) {
            $this->failures[] = \sprintf(
                '%s (expected %s, got %s)',
                $description,
                json_encode($expected, \JSON_UNESCAPED_SLASHES),
                json_encode($actual, \JSON_UNESCAPED_SLASHES),
            );
        }
    }
}
