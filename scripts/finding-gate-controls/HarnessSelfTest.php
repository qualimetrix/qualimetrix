<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\DeclaredFields;
use QmxFindingGate\DeclaredRecords;
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
        foreach (['case:alpha|format:text-detail', 'candidate / ' . $scope, $scope . '|record:{}', 'case:alpha-neighbour|format:text'] as $neighbour) {
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

            mkdir($directory . '/finding-gate/declared-delta', 0o700, true);
            mkdir($directory . '/finding-gate/declared-exact-surfaces', 0o700, true);
            Shell::replace($directory . '/finding-gate/declared-delta/ordinary.diff', "ordinary diff\n");
            Shell::replace($directory . '/finding-gate/declared-exact-surfaces/exact.diff', "exact diff\n");
            Shell::replace($directory . '/finding-gate/declared-delta.tsv', Tsv::render(
                \QmxFindingGate\DeclaredDelta::COLUMNS,
                [['case:ordinary|rules', 'declared-delta/ordinary.diff', 'An ordinary fixture.']],
            ));
            Shell::replace($directory . '/finding-gate/declared-exact-surfaces.tsv', Tsv::render(
                \QmxFindingGate\DeclaredExactSurfaces::COLUMNS,
                [['alpha', 'baseline-file', 'declared-exact-surfaces/exact.diff', 'An exact fixture.']],
            ));

            $harness = (new ReflectionClass(Harness::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(Harness::class, 'repository'))->setValue($harness, $directory);
            $runHarness = new ReflectionMethod(Harness::class, 'run');
            foreach ([
                ['case:alpha|baseline-file', \QmxFindingGate\FailureClass::SURFACE_MISMATCH, true, true,
                    'an exact intention admits an equality expectation past preflight'],
                ['case:ordinary|rules', \QmxFindingGate\FailureClass::SURFACE_MISMATCH, true, false,
                    'an ordinary structural delta still blocks an equality expectation before cloning'],
                ['case:ordinary|rules', \QmxFindingGate\FailureClass::RECORD_STALE, false, true,
                    'an ordinary structural delta admits a required stale record past preflight'],
                ['case:ordinary|rules', \QmxFindingGate\FailureClass::RECORD_STALE, true, true,
                    'an ordinary structural delta admits a tolerated stale record past preflight'],
            ] as [$surface, $failureClass, $tolerated, $admitted, $description]) {
                $record = new Expectation(\QmxFindingGate\FailureClass::RECORD_UNDECLARED, 'case:alpha|format:json', exactScope: true);
                $pinned = new Expectation($failureClass, $surface, exactScope: true);
                $control = Control::red(
                    'preflight-probe',
                    'a surface expectation before cloning',
                    Mutation::none(),
                    $tolerated ? [$record] : [$record, $pinned],
                    $tolerated ? [$pinned] : [],
                );
                $failure = '';
                try {
                    // The temporary repository has no Git metadata, so an admitted control stops before cloning.
                    $runHarness->invoke($harness, [$control], [], 1);
                } catch (RuntimeException $error) {
                    $failure = $error->getMessage();
                }
                $this->same(
                    true,
                    str_starts_with($failure, $admitted ? 'git status --porcelain failed' : 'Control "preflight-probe" expects'),
                    $description,
                );
            }
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
            $sourcePath = 'src/Reporting/Formatter/FindingRecord.php';
            $originalSource = Shell::read($repository . '/' . $sourcePath);
            $fieldIndex = 'finding-gate/' . DeclaredFields::INDEX;
            $fieldDerived = 'finding-gate/' . DeclaredFields::DERIVED;
            $originalFields = [];
            foreach ([$fieldIndex, $fieldDerived] as $path) {
                $originalFields[$path] = is_file($repository . '/' . $path)
                    ? [true, Shell::read($repository . '/' . $path)] : [false, null];
            }
            $originalTuple = \QmxFindingGate\EquivalenceTuple::derive($repository);
            $this->same(19, \count($originalTuple->fields), 'the existing finding record publishes nineteen fields');
            $tuplePlant = <<<'PHP'
                use QmxFindingGate\DeclarationTable;
                use QmxFindingGate\DeclaredFields;
                use QmxFindingGate\EquivalenceTuple;
                use QmxFindingGate\Tsv;
                use QmxFindingGateControls\Scratch;
                use QmxFindingGateControls\Shell;
                use QmxFindingGateControls\TupleControls;

                require getcwd() . '/scripts/finding-gate/classes.php';
                require getcwd() . '/scripts/finding-gate-controls/classes.php';
                $root = getcwd();
                $producer = 'src/Reporting/Formatter/FindingRecord.php';
                $index = 'finding-gate/' . DeclaredFields::INDEX;
                $derived = 'finding-gate/' . DeclaredFields::DERIVED;
                $snapshot = static fn(string $path): array => is_file($path) ? [true, Shell::read($path)] : [false, null];
                $originalSource = Shell::read($root . '/' . $producer);
                $originalTuple = EquivalenceTuple::derive($root);
                $originalTables = [$index => $snapshot($root . '/' . $index)[1], $derived => $snapshot($root . '/' . $derived)[1]];
                $originalRows = [
                    $index => DeclarationTable::rows($root . '/finding-gate', DeclaredFields::INDEX, DeclaredFields::COLUMNS),
                    $derived => DeclarationTable::rows($root . '/finding-gate', DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS),
                ];
                $stage = static function (array $tables) use ($root): void {
                    foreach ($tables as $path => $contents) {
                        if ($contents !== null) {
                            Shell::replace($root . '/' . $path, $contents);
                        } elseif (is_file($root . '/' . $path) && !unlink($root . '/' . $path)) {
                            throw new RuntimeException('Cannot remove the private field table ' . $path);
                        }
                    }
                };
                $observations = [];
                $check = static function (mixed $expected, mixed $actual, string $description) use (&$observations): void {
                    $observations[] = [$expected, $actual, $description];
                };
                $intentRows = [
                    ['added', 'json', 'ranking', 'privateRanking', 'A private ranked field.'],
                    ['added', 'json', 'format:json', 'privateRetained', 'A private JSON field.'],
                    ['added', 'metrics', 'format:metrics', 'privateRanking', 'A private metrics field.'],
                ];
                $derivedRows = [
                    ['json', 'ranking', 'privateRanking', 'self-test-case', '{}', '1'],
                    ['json', 'format:json', 'privateRetained', 'self-test-case', '{}', '2'],
                    ['metrics', 'format:metrics', 'privateRanking', 'self-test-case', '{}', '3'],
                ];
                $retained = [
                    $index => Tsv::render(DeclaredFields::COLUMNS, [$intentRows[1], $intentRows[2]]),
                    $derived => Tsv::render(DeclaredFields::DERIVED_COLUMNS, [$derivedRows[1], $derivedRows[2]]),
                ];
                $cells = [
                    'current' => $originalTables,
                    'absent' => [$index => null, $derived => null],
                    'empty' => [$index => Tsv::render(DeclaredFields::COLUMNS, []), $derived => Tsv::render(DeclaredFields::DERIVED_COLUMNS, [])],
                    'paired' => [$index => Tsv::render(DeclaredFields::COLUMNS, $intentRows), $derived => Tsv::render(DeclaredFields::DERIVED_COLUMNS, $derivedRows)],
                ];
                try {
                    foreach ($cells as $name => $tables) {
                        $stage($tables);
                        DeclaredFields::load($root . '/finding-gate');
                        $mutation = TupleControls::publisherDrift()->mutation;
                        $directory = $argv[1] . '/' . $name;
                        $tree = $directory . '/tree';
                        mkdir($tree, 0700, true);
                        $target = (new ReflectionClass(Scratch::class))->newInstanceWithoutConstructor();
                        (new ReflectionMethod(Scratch::class, '__construct'))->invoke($target, $tree, $directory);
                        try {
                            $inputs = array_values(array_unique([...$mutation->relativePaths(), $producer, $index, $derived]));
                            foreach ($inputs as $path) {
                                if (is_file($root . '/' . $path)) {
                                    if (!is_dir(dirname($target->path($path)))) {
                                        mkdir(dirname($target->path($path)), 0700, true);
                                    }
                                    Shell::replace($target->path($path), Shell::read($root . '/' . $path));
                                }
                            }
                            $check($originalSource, Shell::read($target->path($producer)), $name . ': the target retains the original producer before mutation');
                            foreach ($tables as $path => $contents) {
                                $check([$contents !== null, $contents], $snapshot($target->path($path)), $name . ': the target preserves table presence and bytes before mutation: ' . $path);
                            }
                            $beforeIntents = DeclarationTable::rows($target->path('finding-gate'), DeclaredFields::INDEX, DeclaredFields::COLUMNS);
                            $beforeDerived = DeclarationTable::rows($target->path('finding-gate'), DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS);
                            if ($name === 'current') {
                                $check($originalRows[$index], $beforeIntents, 'current: every field intention enters the private tree');
                                $check($originalRows[$derived], $beforeDerived, 'current: every field measurement enters the private tree');
                            } else {
                                $check($name === 'paired' ? 3 : 0, count($beforeIntents), $name . ': the field intention pre-count is concrete');
                                $check($name === 'paired' ? 3 : 0, count($beforeDerived), $name . ': the field measurement pre-count is concrete');
                            }
                            $expectedRows = [];
                            $expectedTables = $tables;
                            $expectedPaths = [];
                            foreach ([$index => $beforeIntents, $derived => $beforeDerived] as $path => $rows) {
                                $expectedRows[$path] = array_values(array_filter($rows, static fn(array $row): bool =>
                                    $row['report'] !== 'json' || $row['view'] !== 'ranking'));
                                if ($expectedRows[$path] !== $rows) {
                                    $expectedPaths[] = $path;
                                    $columns = $path === $index ? DeclaredFields::COLUMNS : DeclaredFields::DERIVED_COLUMNS;
                                    $expectedTables[$path] = Tsv::render($columns, array_map(static fn(array $row): array => array_values($row), $expectedRows[$path]));
                                }
                            }
                            if ($name === 'paired') {
                                $check($retained, $expectedTables, 'paired: the expected cleanup retains both exact non-ranking rows');
                            }
                            $check($expectedPaths, array_values(array_intersect($mutation->relativePaths(), [$index, $derived])), $name . ': field cleanup is composed exactly for tables carrying unavailable ranking rows');
                            $mutation->apply($target, $root);
                            $mutatedTuple = EquivalenceTuple::derive($tree);
                            $check([...$originalTuple->fields, 'probe'], $mutatedTuple->fields, $name . ': the shared added-member mutation reaches the actual finding record');
                            $check([...$originalTuple->sources, EquivalenceTuple::source()], $mutatedTuple->sources, $name . ': the added member retains the finding record producer');
                            $check($originalSource, Shell::read($root . '/' . $producer), $name . ': the shared mutation leaves the original publisher intact');
                            foreach ($expectedTables as $path => $contents) {
                                $check([$contents !== null, $contents], $snapshot($target->path($path)), $name . ': the private tuple plant removes only unavailable ranking rows: ' . $path);
                                $check([$tables[$path] !== null, $tables[$path]], $snapshot($root . '/' . $path), $name . ': the original field table retains its presence and bytes: ' . $path);
                            }
                            $afterIntents = DeclarationTable::rows($target->path('finding-gate'), DeclaredFields::INDEX, DeclaredFields::COLUMNS);
                            $afterDerived = DeclarationTable::rows($target->path('finding-gate'), DeclaredFields::DERIVED, DeclaredFields::DERIVED_COLUMNS);
                            if ($name === 'current') {
                                $check($expectedRows[$index], $afterIntents, 'current: every available field intention survives the private tuple plant');
                                $check($expectedRows[$derived], $afterDerived, 'current: every available field measurement survives the private tuple plant');
                            } else {
                                $check($name === 'paired' ? 2 : 0, count($afterIntents), $name . ': exactly the retained field intentions remain');
                                $check($name === 'paired' ? 2 : 0, count($afterDerived), $name . ': exactly the retained field measurements remain');
                            }
                            $check(count($afterIntents) < count($beforeIntents), count($afterDerived) < count($beforeDerived), $name . ': ranking field intentions and measurements are paired, including an empty pair');
                            $fields = DeclaredFields::load($target->path('finding-gate'));
                            $check([], $fields->changes('json', 'ranking'), $name . ': the private tuple plant leaves no unmeasurable ranking field obligations');
                            $check($name === 'paired' ? ['privateRetained' => 'added'] : [], $fields->changes('json', 'format:json'), $name . ': the retained JSON field obligation survives');
                            $check($name === 'paired' ? ['privateRanking' => 'added'] : [], $fields->changes('metrics', 'format:metrics'), $name . ': the retained metrics field obligation survives');
                        } finally {
                            $target->remove();
                        }
                    }
                } finally {
                    $stage($originalTables);
                }
                foreach ($originalTables as $path => $contents) {
                    $check([$contents !== null, $contents], $snapshot($root . '/' . $path), 'the private source restores the original table presence and bytes: ' . $path);
                }
                echo json_encode($observations, JSON_THROW_ON_ERROR);
                PHP;
            $plant = Shell::run([\PHP_BINARY, '-r', $tuplePlant, $scratch->beside('tuple-cells')], $scratch->tree);
            $this->same(0, $plant['exit'], 'the actual private tuple plant covers all four table cells: ' . $plant['stderr']);
            if ($plant['exit'] === 0) {
                foreach (json_decode($plant['stdout'], true, 512, \JSON_THROW_ON_ERROR) as [$expected, $actual, $description]) {
                    $this->same($expected, $actual, $description);
                }
            }
            $this->same($originalSource, Shell::read($repository . '/' . $sourcePath), 'the shared mutation leaves the original publisher intact');
            foreach ($originalFields as $path => $snapshot) {
                $actual = is_file($repository . '/' . $path) ? [true, Shell::read($repository . '/' . $path)] : [false, null];
                $this->same($snapshot, $actual, 'the original field table retains its presence and bytes: ' . $path);
            }

            $recordIndex = 'finding-gate/' . DeclaredRecords::INDEX;
            $recordDerived = 'finding-gate/' . DeclaredRecords::DERIVED;
            $originalRecordIndex = Shell::read($repository . '/' . $recordIndex);
            $originalRecordDerived = is_file($repository . '/' . $recordDerived)
                ? Shell::read($repository . '/' . $recordDerived) : null;
            $pairedRecord = '{"channel":"self-test.paired"}';
            $pairedIntent = ['introduced', 'self-test-case', 'json', 'format:json', $pairedRecord, 'A private measured record.'];
            $pairedIntentText = Tsv::render(DeclaredRecords::COLUMNS, [$pairedIntent]);
            $pairedDerivedText = Tsv::render(DeclaredRecords::DERIVED_COLUMNS, [
                ['introduced', 'self-test-case', 'json', 'format:json', $pairedRecord],
            ]);
            Shell::replace($scratch->path($recordIndex), $pairedIntentText);
            Shell::replace($scratch->path($recordDerived), $pairedDerivedText);
            $idleMutation = RecordControls::idleSelector()->mutation;
            $idleMutation->apply($scratch, $repository);
            $records = DeclaredRecords::load($scratch->path('finding-gate'));
            $this->same(2, $records->count(), 'the idle selector keeps the measured intent and adds only one new intent');
            $this->same($pairedIntent, array_values($records->intents('json', 'format:json')[0]), 'the measured record retains its original intent');
            $this->same($pairedDerivedText, $records->derivedText(), 'the existing derived measurement keeps its bytes');
            $this->same($pairedIntentText, substr(Shell::read($scratch->path($recordIndex)), 0, \strlen($pairedIntentText)), 'the original intent rows keep their bytes');
            $this->same(true, $records->claim('introduced', 'self-test-case', 'json', 'format:json', $pairedRecord), 'the existing measured record is still claimable');
            $stale = $records->staleIntents();
            $this->same(1, \count($stale), 'only the newly added selector remains stale');
            $this->same(DeclaredRecords::INDEX, $stale[0]['scope'] ?? null, 'the idle selector is stale in the record index');
            $this->same(true, str_contains($stale[0]['detail'] ?? '', 'nothing.published'), 'the stale selector is the planted one');

            Shell::replace($scratch->path($recordIndex), Tsv::render(DeclaredRecords::COLUMNS, []));
            Shell::replace($scratch->path($recordDerived), Tsv::render(DeclaredRecords::DERIVED_COLUMNS, []));
            $idleMutation->apply($scratch, $repository);
            $this->same(1, DeclaredRecords::load($scratch->path('finding-gate'))->count(), 'the header-only index gains exactly one idle selector');
            $this->same($originalRecordIndex, Shell::read($repository . '/' . $recordIndex), 'the original record index retains its bytes');
            $this->same(
                $originalRecordDerived,
                is_file($repository . '/' . $recordDerived) ? Shell::read($repository . '/' . $recordDerived) : null,
                'the original record measurements retain their presence and bytes',
            );
            if ($originalRecordDerived !== null) {
                Shell::replace($scratch->path($recordDerived), $originalRecordDerived);
            } elseif (!unlink($scratch->path($recordDerived))) {
                throw new RuntimeException('Cannot remove the staged record measurement table.');
            }

            $orphan = $scratch->path('finding-gate/declared-exact-surfaces');
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
                $restoration = null;
                foreach ($controls as $control) {
                    if ($control->id === 'derive-writes-green-run') {
                        $restoration = [$control->restoredAfterRun, $control->restoredContent];
                    }
                }
                echo json_encode([
                    'ids' => array_map(static fn($control): string => $control->id, $controls),
                    'restoration' => $restoration,
                ], JSON_THROW_ON_ERROR);
                PHP;
            $factory = Shell::run([\PHP_BINARY, '-r', $factoryProbe], $scratch->tree);
            $this->same(0, $factory['exit'], 'the prepared tree builds every control factory: ' . $factory['stderr']);
            if ($factory['exit'] === 0) {
                $metadata = json_decode($factory['stdout'], true, 512, \JSON_THROW_ON_ERROR);
                $ids = $metadata['ids'];
                $this->same(28, \count($ids), 'the prepared tree retains all 28 controls');
                $this->same('positive', $ids[0] ?? null, 'the first control keeps its place');
                $this->same('report-value-no-row', $ids[18] ?? null, 'the fixed factories keep their order');
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

            $unused = ChannelRenamePlants::unusedPrivateRenameDeclarations();
            $claims = [
                ['smells', 'code-smell.unused-private@class', 'code-smell.unused-privat2@class', $unused],
                ['detectors-smells', 'code-smell.unused-private@class', 'code-smell.unused-privat2@class', $unused],
                ['detectors', 'code-smell.unused-private@class', 'code-smell.unused-privat2@class', $unused],
            ];
            foreach ($claims as [$case, $oldClaim, $newClaim, $mutation]) {
                $relative = 'finding-gate/cases/' . $case . '/case.json';
                $original = Shell::read($repository . '/' . $relative);
                $quoted = json_encode($oldClaim, \JSON_THROW_ON_ERROR);
                $escaped = '"\\u' . \sprintf('%04x', \ord($oldClaim[0])) . substr($quoted, 2);
                $this->same(1, substr_count($original, $quoted), $case . ' has one claim to escape');
                Shell::replace($scratch->path($relative), str_replace($quoted, $escaped, $original));
                $before = json_decode(Shell::read($scratch->path($relative)), true, 512, \JSON_THROW_ON_ERROR);
                $actions = (new ReflectionProperty(Mutation::class, 'actions'))->getValue($mutation);
                $caseActions = array_values(array_filter($actions, static fn(array $action): bool => $action['path'] === $relative));
                $this->same(1, \count($caseActions), $case . ' has one case claim action');
                if (\count($caseActions) !== 1) {
                    continue;
                }
                $action = $caseActions[0];
                $claimMutation = match ($action['kind']) {
                    'edit' => Mutation::edit($relative, $action['replacements'], 'the case claim follows the rename'),
                    'replace' => Mutation::replace([$relative => $action['contents']], 'the case claim follows the rename'),
                    default => throw new RuntimeException('A case claim uses no supported mutation action.'),
                };
                try {
                    $claimMutation->apply($scratch, $repository);
                } catch (RuntimeException $error) {
                    $this->failures[] = $case . ' escaped claim mutation (' . $error->getMessage() . ')';
                    continue;
                }
                $expected = $before;
                $oldChannel = \QmxFindingGate\SubjectLevel::channelOf($oldClaim);
                $newChannel = \QmxFindingGate\SubjectLevel::channelOf($newClaim);
                foreach ($expected['channels'] as $index => $claim) {
                    if (\QmxFindingGate\SubjectLevel::channelOf($claim) !== $oldChannel) {
                        continue;
                    }
                    $expected['channels'][$index] = $newChannel . substr($claim, \strlen($oldChannel));
                }
                $actual = json_decode(Shell::read($scratch->path($relative)), true, 512, \JSON_THROW_ON_ERROR);
                $this->same($expected, $actual, $case . ' changes only its semantic channel claim');
                $this->same(
                    $expected['channels'],
                    \QmxFindingGate\CaseDefinition::load($scratch->path('finding-gate/cases/' . $case))->channels,
                    $case . ' keeps every other native claim and level',
                );
            }
            try {
                ChannelRenamePlants::renamedCaseClaims(
                    \QmxFindingGate\CaseDefinition::load($repository . '/finding-gate/cases/smells'),
                    'code-smell.absent',
                    'code-smell.renamed',
                );
                $this->failures[] = 'a missing semantic claim was accepted';
            } catch (RuntimeException $error) {
                $this->same(
                    'Case smells no longer claims channel code-smell.absent.',
                    $error->getMessage(),
                    'a missing semantic claim is refused before mutation',
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

        $repository = null;
        $launched = null;
        try {
            $repository = self::throwawayRepository();
            mkdir($repository . '/scripts');
            Shell::replace($repository . '/scripts/finding-gate.php', "<?php echo ini_get('memory_limit'), \"\\n\";\n");
            $harness = (new ReflectionClass(Harness::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(Harness::class, 'repository'))->setValue($harness, $repository);
            (new ReflectionProperty(Harness::class, 'reference'))->setValue($harness, 'HEAD');
            $launched = (new ReflectionMethod(Harness::class, 'launch'))->invoke($harness, Control::green('budget-probe', 'child PHP budget'));
            while (!$launched['child']->settled()) {
                Shell::poll();
            }
            $budgetRun = $launched['child']->result();
            $this->same(0, $budgetRun['exit'], 'the real gate child exits successfully');
            $this->same("1G\n", $budgetRun['stdout'], 'the real gate child receives a 1G memory budget');
            $this->same('', $budgetRun['stderr'], 'the real gate child has no diagnostic');
        } catch (Throwable $error) {
            $this->failures[] = 'the gate child memory budget (' . $error->getMessage() . ')';
        } finally {
            if ($launched !== null) {
                if (!$launched['child']->settled()) {
                    Shell::terminateAll();
                }
                $launched['scratch']->remove();
            }
            if ($repository !== null) {
                Shell::removeRecursively($repository);
            }
        }

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
