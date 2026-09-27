<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use QmxFindingGate\Gate;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\Options;
use QmxFindingGate\Process;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\SyntheticTree;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\ChannelRenameTsvCorpus;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * The other half of the shared corpus: what the finding gate's reader does
 * with the same lines.
 *
 * Without this, "the carry reads the same format the gate declares renames
 * in" is a sentence in a plan. With it, a change to either reader that moves
 * a verdict has to move the corpus too, and the five deliberate divergences
 * are pinned as declarations rather than discovered later as surprises.
 *
 * The gate's own classes (`QmxFindingGate\`) have no autoload root — only this
 * test directory does — so its classes are required by hand. That is the same
 * fact the corpus exists because of: the two readers cannot be one.
 */
final class ChannelRenameTsvGateAgreementTest extends TestCase
{
    /** The other four maps a gate load insists on finding beside `channels.tsv`. */
    private const array SIBLING_MAPS = ['symbols.tsv', 'metric-keys.tsv', 'inputs.tsv', 'report-values.tsv'];

    private string $tempDir;

    public static function setUpBeforeClass(): void
    {
        $gate = \dirname(__DIR__);

        require_once $gate . '/classes.php';
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function provideCorpus(): iterable
    {
        foreach (ChannelRenameTsvCorpus::cases() as $case) {
            yield $case['id'] => [$case['contents'], $case['gate'], $case['note']];
        }
    }

    protected function setUp(): void
    {
        $this->tempDir = (string) tempnam(sys_get_temp_dir(), 'qmx-gate-corpus-');
        unlink($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');

        foreach (\is_array($files) ? $files : [] as $file) {
            unlink($file);
        }

        rmdir($this->tempDir);
    }

    #[Test]
    #[DataProvider('provideCorpus')]
    public function itAnswersTheSharedCorpusAsDeclared(string $contents, bool $accepted, string $note): void
    {
        file_put_contents($this->tempDir . '/channels.tsv', $contents);

        foreach (self::SIBLING_MAPS as $sibling) {
            file_put_contents($this->tempDir . '/' . $sibling, "old\tnew\treason\n");
        }

        $refusal = null;

        try {
            RenameMaps::load($this->tempDir, MetricVocabulary::none());
        } catch (Throwable $e) {
            $refusal = $e;
        }

        self::assertSame($accepted, $refusal === null, $note . ' ' . ($refusal?->getMessage() ?? ''));
    }

    #[Test]
    public function itRestatesOnlyTheDeclaredYamlPathAndPreservesNeighbourBytes(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => '["rules","a.rule","warning"]', 'new' => '["rules","a.rule","limit"]', 'source' => RenameMaps::INPUTS],
        ]);
        $input = "# limit: comment\nrules:\n  a.rule:\n    limit: 3 # limit: stays\n  b.rule:\n    limit: 4\nnote: 'limit: quoted'\n";
        self::assertSame(str_replace('    limit: 3', '    warning: 3', $input), $maps->reverseYaml($input));
        self::assertSame([], $maps->staleRows());
        $idle = RenameMaps::fromPairs([
            ['old' => 'warning:', 'new' => 'limit:', 'source' => RenameMaps::INPUTS],
        ]);
        self::assertSame("# limit: only\nnote: 'limit: scalar'\n", $idle->reverseYaml("# limit: only\nnote: 'limit: scalar'\n"));
        self::assertCount(1, $idle->staleRows());
    }

    #[Test]
    public function itRefusesAKeyCollisionAndAnUnaddressableYamlPath(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => '["rules","a.rule","warning"]', 'new' => '["rules","a.rule","limit"]', 'source' => RenameMaps::INPUTS],
        ]);
        foreach (["rules: {a.rule: {limit: 3}}\n", "rules:\n  a.rule:\n    warning: 2\n    limit: 3\n"] as $input) {
            try {
                $maps->reverseYaml($input);
                self::fail('Unsupported or colliding YAML was accepted.');
            } catch (GateError) {
                self::assertSame([], $maps->firedRows());
            }
        }
    }

    #[Test]
    public function itRefusesAffectedSelectorReachAndMetricFormulaGrammarWithoutCreditingRows(): void
    {
        $channel = RenameMaps::fromPairs([['old' => 'a.old', 'new' => 'a.new', 'source' => RenameMaps::CHANNELS]]);
        foreach (["only_rules: [a.new]\n", "only_rules: ['a.*']\n", "exclude_rules: [a.old]\n"] as $input) {
            try {
                $channel->reverseYaml($input);
                self::fail('Affected selector reach was guessed.');
            } catch (GateError $error) {
                self::assertStringContainsString('reach-aware', $error->getMessage());
                self::assertSame([], $channel->firedRows());
            }
        }
        $metric = RenameMaps::fromPairs([['old' => 'ccn', 'new' => 'complexity.ccn', 'source' => RenameMaps::METRIC_KEYS]]);
        $input = "computed_metrics:\n  computed.test:\n    formula: 'm[\"complexity.ccn.avg\"]'\n";
        try {
            $metric->reverseYaml($input);
            self::fail('Affected metric grammar was guessed.');
        } catch (GateError $error) {
            self::assertStringContainsString('grammar-aware', $error->getMessage());
            self::assertSame([], $metric->firedRows());
        }
        self::assertSame($input, RenameMaps::fromPairs([])->reverseYaml($input));
        $unaffected = "computed_metrics:\n  computed.test:\n    formula: 'm[\"other.metric\"]'\n";
        self::assertSame($unaffected, $metric->reverseYaml($unaffected));
    }

    #[Test]
    public function itTranslatesOnlyExactCliInputCellsAndPreservesSettingValues(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => '--old-flag', 'new' => '--new-flag', 'source' => RenameMaps::INPUTS],
            ['old' => 'a.old:warning', 'new' => 'a.new:limit', 'source' => RenameMaps::INPUTS],
            ['old' => 'a.old', 'new' => 'a.new|b.new', 'source' => RenameMaps::INPUTS],
        ]);
        self::assertSame(
            ['--old-flag=--new-flag', '--rule-opt=a.old:warning=a.new:limit', '--disable-rule=a.old,a.old', '--config', 'fixture.yaml', '--unrelated=a.newNeighbour'],
            $maps->reverseArguments(['--new-flag=--new-flag', '--rule-opt=a.new:limit=a.new:limit', '--disable-rule=a.new,b.new', '--config', 'fixture.yaml', '--unrelated=a.newNeighbour']),
        );
        self::assertSame([], $maps->staleRows());
        self::assertSame(['--only-rules=a.new', '--preset', 'strict'], RenameMaps::fromPairs([])->reverseArguments(['--only-rules=a.new', '--preset', 'strict']));
    }

    #[Test]
    public function itRefusesAffectedCliRolesAtomicallyAndKeepsUnknownInputsUncredited(): void
    {
        foreach (['--only-rules=a.new', '--only-rules=a.*', '--disable-rule=a.new', '--rule-opt=a.new:limit=2', '--unknown=a.new', '--config=src/NewName.php'] as $argument) {
            $maps = RenameMaps::fromPairs([
                ['old' => '--old-flag', 'new' => '--new-flag', 'source' => RenameMaps::INPUTS],
                ['old' => 'a.old', 'new' => 'a.new', 'source' => RenameMaps::CHANNELS],
                ['old' => 'App\\OldName', 'new' => 'App\\NewName', 'source' => RenameMaps::SYMBOLS],
            ]);
            try {
                $maps->reverseArguments(['--new-flag=3', $argument]);
                self::fail('An unsupported CLI role was silently accepted.');
            } catch (GateError $error) {
                self::assertStringContainsString('mapped CLI', $error->getMessage());
                self::assertSame([], $maps->firedRows());
            }
            $maps->assertUnsupportedInputUntouched('unrelated a.newNeighbour');
            self::assertSame([], $maps->firedRows());
            try {
                $maps->assertUnsupportedInputUntouched('unowned a.new');
                self::fail('An unknown input consumed a mapped token.');
            } catch (GateError $error) {
                self::assertStringContainsString('supported named role', $error->getMessage());
                self::assertSame([], $maps->firedRows());
            }
        }
        $paths = RenameMaps::fromPairs([['old' => 'App\\OldName', 'new' => 'App\\NewName', 'source' => RenameMaps::SYMBOLS]]);
        $paths->assertSymbolPathsUntouched(['src', 'lib', 'src/NewNameNeighbour.php']);
        self::assertSame([], $paths->firedRows());
        self::assertSame('App\\OldName', $paths->reverseSymbol('App\\NewName'));
        try {
            $paths->assertSymbolPathsUntouched(['src/NewName.php']);
            self::fail('An already credited symbol licensed a guessed path rename.');
        } catch (GateError $error) {
            self::assertStringContainsString('physical path correspondence', $error->getMessage());
            self::assertCount(1, $paths->firedRows());
        }
        $maps = RenameMaps::fromPairs([['old' => 'a.old', 'new' => 'a.new', 'source' => RenameMaps::CHANNELS]]);
        try {
            $maps->reverseChannelMap("old\tnew\treason\na.new\tb.old\ta.new stays\ninvalid\n");
            self::fail('A malformed row after a valid translation was accepted.');
        } catch (GateError $error) {
            self::assertStringContainsString('exactly three fields', $error->getMessage());
            self::assertSame([], $maps->firedRows());
        }
    }

    #[Test]
    public function itTranslatesExactSymbolsAndChannelMapFieldsInTheirOwnRoles(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => 'App\\Old', 'new' => 'App\\New', 'source' => RenameMaps::SYMBOLS],
            ['old' => 'a.old', 'new' => 'a.new', 'source' => RenameMaps::CHANNELS],
        ]);
        self::assertSame('App\\Old', $maps->reverseSymbol('App\\New'));
        self::assertSame('App\\NewNeighbour', $maps->reverseSymbol('App\\NewNeighbour'));
        self::assertSame("old\tnew\treason\n# a.new stays\na.old\tb.old\ta.new\n", $maps->reverseChannelMap("old\tnew\treason\n# a.new stays\na.new\tb.old\ta.new\n"));
        self::assertSame([], $maps->staleRows());
        $collapse = RenameMaps::fromPairs([
            ['old' => 'a.old', 'new' => 'same.new', 'source' => RenameMaps::CHANNELS],
            ['old' => 'b.old', 'new' => 'same.new', 'source' => RenameMaps::CHANNELS],
        ]);
        $this->expectException(GateError::class);
        $collapse->reverseChannelMap("old\tnew\treason\nsame.new\tx\ta reason\n");
    }

    #[Test]
    public function itTranslatesOnlyNamedYamlSymbolAndComposerAutoloadPositions(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => 'App\\Old', 'new' => 'App\\New', 'source' => RenameMaps::SYMBOLS],
            ['old' => 'src/Old.php', 'new' => 'src/New.php', 'source' => RenameMaps::SYMBOLS],
        ]);
        $yaml = <<<'YAML'
# App\New comment remains
architecture:
  layers:
    - name: a
      extends:
        - 'App\New' # App\New comment remains
      exclude:
        implements:
          - App\New
note: 'App\New prose remains'
YAML;
        $expected = str_replace(["- 'App\\New'", '- App\\New'], ["- 'App\\Old'", '- App\\Old'], $yaml);
        self::assertSame($expected, $maps->reverseYaml($yaml));
        $composer = '{ "description": "unchanged", "ratio": 1.50, "autoload": {"psr-4": {"App\\\\New\\\\": ["src/New.php"]}, "files": ["src/New.php"]}, "autoload-dev": {"classmap": ["src/New.php"]}, "neighbour": "App\\\\NewNeighbour" }';
        $expected = str_replace(['App\\\\New\\\\', 'src/New.php'], ['App\\\\Old\\\\', 'src/Old.php'], $composer);
        self::assertSame($expected, $maps->reverseComposer($composer));
        self::assertSame([], $maps->staleRows());
        foreach (["architecture:\n  layers:\n    - name: a\n      extends: ['App\\New']\n", "architecture:\n  layers:\n    - name: a\n      patterns: ['App\\New\\**']\n", "note: 'App\\New'\n"] as $unknown) {
            try {
                $maps->reverseYaml($unknown);
                self::fail('An unsupported YAML symbol position was accepted.');
            } catch (GateError $error) {
                self::assertStringContainsString('mapped', $error->getMessage());
            }
        }
        foreach (['{"description":"App\\\\New"}', '{"autoload":{"psr-4":{"App\\\\New\\\\":"src/New.php","App\\\\Old\\\\":"other"}}}'] as $unknown) {
            try {
                $maps->reverseComposer($unknown);
                self::fail('An unsupported or colliding Composer position was accepted.');
            } catch (GateError $error) {
                self::assertStringContainsString('mapped', $error->getMessage());
            }
        }
    }

    #[Test]
    public function itTranslatesOnlyNamedPhpTokensAndPreservesDocumentationAndProseBytes(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => 'App\\Old', 'new' => 'App\\NewName', 'source' => RenameMaps::SYMBOLS],
            ['old' => 'a.old', 'new' => 'a.new', 'source' => RenameMaps::CHANNELS],
        ]);
        $input = <<<'PHP'
<?php
use App\NewName as Local;
/**
 * @qmx-ignore a.new:class -- a.new reason stays
 * `@qmx-ignore a.new` stays documentation
 * ```php
 * @qmx-threshold a.new warning=3
 * ```
 * App\NewName prose stays
 */
function build(): \App\NewName { return new \App\NewName(); }
// @qmx-ignore-next-line a.new -- reason
function neighbour(): \App\NewNeighbour { return new Local(); }
PHP;
        $expected = str_replace(['use App\\NewName as', ': \\App\\NewName {', 'new \\App\\NewName()', '@qmx-ignore a.new:class', '@qmx-ignore-next-line a.new --'], ['use App\\Old as', ': \\App\\Old {', 'new \\App\\Old()', '@qmx-ignore a.old:class', '@qmx-ignore-next-line a.old --'], $input);
        self::assertSame($expected, $maps->reversePhp($input));
        self::assertSame([], $maps->staleRows());
        $documentation = "<?php\n/** `@qmx-ignore a.new` App\\NewName */\n";
        $idle = RenameMaps::fromPairs([['old' => 'a.old', 'new' => 'a.new', 'source' => RenameMaps::CHANNELS]]);
        self::assertSame($documentation, $idle->reversePhp($documentation));
        self::assertCount(1, $idle->staleRows());
        $unmapped = "<?php\nnamespace Other; class NewName {}\n";
        self::assertSame($unmapped, $maps->reversePhp($unmapped));
        $method = RenameMaps::fromPairs([['old' => 'App\\Holder::before', 'new' => 'App\\Holder::renamed', 'source' => RenameMaps::SYMBOLS]]);
        $outside = "<?php\nnamespace App; class Holder {} function renamed() {}\n";
        self::assertSame($outside, $method->reversePhp($outside));
        self::assertSame([], $method->firedRows());
    }

    /** @return iterable<string,array{string}> */
    public static function unsupportedPhp(): iterable
    {
        yield 'implicit import' => ["<?php\nuse App\\NewName;\n"];
        yield 'relative name' => ["<?php\nfunction f(): App\\NewName {}\n"];
        yield 'namespace relative name' => ["<?php\nnamespace App; function f(): namespace\\NewName {}\n"];
        yield 'qualified relative name' => ["<?php\nnamespace App; function f(): Nested\\NewName {}\n"];
        yield 'declaration' => ["<?php\nnamespace App; class NewName {}\n"];
        yield 'declaration after matched reference' => ["<?php\nnamespace App; function f(): \\App\\NewName {} class NewName {}\n"];
        yield 'method declaration' => ["<?php\nnamespace App; class Holder { function renamed() {} }\n"];
        yield 'reference method declaration' => ["<?php\nnamespace App; class Holder { function &renamed() {} }\n"];
        yield 'method reference' => ["<?php\n\\App\\Holder::renamed();\n"];
        yield 'string' => ["<?php\n\$class = 'App\\NewName';\n"];
        yield 'interpolation' => ["<?php\n\$class = \"App\\NewName{\$suffix}\";\n"];
        yield 'dynamic name' => ["<?php\nnew \$NewName();\n"];
        yield 'unknown tag' => ["<?php\n/** @qmx-ignore-lines a.new */\n"];
        yield 'wildcard target' => ["<?php\n/** @qmx-ignore a.* */\n"];
        yield 'pair target' => ["<?php\n/** @qmx-threshold a.new#other 3 */\n"];
        yield 'unknown level' => ["<?php\n/** @qmx-ignore a.new:unknown */\n"];
    }

    #[Test]
    #[DataProvider('unsupportedPhp')]
    public function itRefusesMappedPhpNamesOutsideTheFiniteInputProfileWithoutCreditingRows(string $input): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => 'App\\Old', 'new' => 'App\\NewName', 'source' => RenameMaps::SYMBOLS],
            ['old' => 'App\\Nested\\OldName', 'new' => 'App\\Nested\\NewName', 'source' => RenameMaps::SYMBOLS],
            ['old' => 'App\\Holder::before', 'new' => 'App\\Holder::renamed', 'source' => RenameMaps::SYMBOLS],
            ['old' => 'a.old', 'new' => 'a.new', 'source' => RenameMaps::CHANNELS],
        ]);
        $this->expectException(GateError::class);
        try {
            $maps->reversePhp($input);
        } finally {
            self::assertSame([], $maps->firedRows());
        }
    }

    #[Test]
    public function itPermutesOnlyTheDeclaredEnumerationAndRetainsMemberValueBytes(): void
    {
        $descriptor = static fn(string $kind, string $field, array $members): string => (string) json_encode(['surface' => 'format:suppressed', 'path' => [$field], 'kind' => $kind, 'members' => $members]);
        $maps = RenameMaps::fromPairs([
            ['old' => $descriptor('values', 'mechanisms', ['a', 'b']), 'new' => $descriptor('values', 'mechanisms', ['b', 'a']), 'source' => RenameMaps::REPORT_VALUES],
            ['old' => $descriptor('keys', 'byMechanism', ['a', 'b']), 'new' => $descriptor('keys', 'byMechanism', ['b', 'a']), 'source' => RenameMaps::REPORT_VALUES],
        ]);
        $input = '{"mechanisms": ["a", "b"], "byMechanism": {"a": 1.50, "b": 2}, "neighbour": ["a", "b"]}';
        $expected = '{"mechanisms": ["b", "a"], "byMechanism": {"b": 2, "a": 1.50}, "neighbour": ["a", "b"]}';
        self::assertSame($expected, $maps->forward($input, 'format:suppressed'));
        self::assertSame([], $maps->staleRows());
        self::assertSame($input, $maps->forward($input, 'format:json'));
        $value = RenameMaps::fromPairs([
            ['old' => 'old-value', 'new' => 'new-value', 'source' => RenameMaps::REPORT_VALUES],
        ]);
        self::assertSame('{"mechanism":"new-value","note":"old-value"}', $value->forward('{"mechanism":"old-value","note":"old-value"}', 'format:suppressed'));
    }

    #[Test]
    public function itChecksTheWholeSuffixResidueAndBothTreesIndependentBaseKeys(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => 'strategy:avg', 'new' => 'strategy:mean', 'source' => RenameMaps::METRIC_KEYS],
        ], MetricVocabulary::of(['mean', 'sum'], ['ccn']));
        $maps->acceptReferenceVocabulary(MetricVocabulary::of(['avg', 'sum'], ['ccn']));
        self::assertSame('"ccn.mean" "ccn.avgx"', $maps->forward('"ccn.avg" "ccn.avgx"', 'format:metrics'));
        self::assertSame([], $maps->staleRows());
        $collision = RenameMaps::fromPairs([
            ['old' => 'ccn', 'new' => 'complexity.ccn', 'source' => RenameMaps::METRIC_KEYS],
        ], MetricVocabulary::of(['sum'], ['complexity.ccn']));
        $this->expectException(GateError::class);
        $collision->acceptReferenceVocabulary(MetricVocabulary::of(['sum'], ['ccn', 'ccn.sum']));
    }

    #[Test]
    public function itBootstrapsYamlFromTheToolDependencyInAFreshPublicLoaderProcess(): void
    {
        $script = 'require $argv[1] . "/scripts/finding-gate/classes.php";'
            . ' $maps = QmxFindingGate\\RenameMaps::fromPairs([["old"=>"old_key:","new"=>"new_key:","source"=>"inputs.tsv"]]);'
            . ' echo $maps->reverseYaml("new_key: 1\\n# new_key: stays\\n");';
        $run = Process::run([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 3)], \dirname(__DIR__, 3));
        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertSame("old_key: 1\n# new_key: stays\n", $run['stdout']);
    }

    #[Test]
    public function itGeneratesOnlyExplicitSymbolCorrespondenceAndRefusesAnUnmatchedNeighbour(): void
    {
        $root = Fs::temporaryDirectory('symbol-map-generator-test-');
        try {
            Fs::write($root . '/src/Old.php', "<?php\nnamespace App;\nclass OldName { public function run(): void {} }\n");
            foreach ([['git', 'init', '--quiet'], ['git', 'add', '--all'], ['git', '-c', 'user.name=fixture', '-c', 'user.email=fixture@qmx', 'commit', '--quiet', '-m', 'Reference symbols']] as $command) {
                self::assertSame(0, Process::run($command, $root)['exit']);
            }
            unlink($root . '/src/Old.php');
            Fs::write($root . '/src/New.php', "<?php\nnamespace App;\nclass NewName { public function run(): void {} }\n");
            Fs::write($root . '/correspondences.tsv', "old\tnew\nApp\\OldName\tApp\\NewName\nApp\\OldName::run\tApp\\NewName::run\nsrc/Old.php\tsrc/New.php\n");
            $command = ['python3', \dirname(__DIR__, 2) . '/generate-gate-symbol-map.py', '--candidate=' . $root, '--reference=HEAD', '--correspondences=' . $root . '/correspondences.tsv'];
            $before = Fs::read($root . '/src/New.php');
            $run = Process::run($command, $root);
            self::assertSame(0, $run['exit'], $run['stderr']);
            self::assertSame("old\tnew\treason\nApp\\OldName\tApp\\NewName\t?\nApp\\OldName::run\tApp\\NewName::run\t?\nsrc/Old.php\tsrc/New.php\t?\n", $run['stdout']);
            self::assertSame($before, Fs::read($root . '/src/New.php'));
            Fs::write($this->tempDir . '/symbols.tsv', $run['stdout']);
            foreach (['channels.tsv', 'metric-keys.tsv', 'inputs.tsv', 'report-values.tsv'] as $map) {
                Fs::write($this->tempDir . '/' . $map, "old\tnew\treason\n");
            }
            try {
                RenameMaps::load($this->tempDir, MetricVocabulary::none());
                self::fail('A generated proposal with no supplied reasons was accepted.');
            } catch (GateError $error) {
                self::assertStringContainsString('explicit reason', $error->getMessage());
            }
            Fs::write($root . '/src/Neighbour.php', "<?php\nnamespace App;\nclass Unlisted {}\n");
            $unmatched = Process::run($command, $root);
            self::assertSame(1, $unmatched['exit']);
            self::assertStringContainsString('Unmatched', $unmatched['stderr']);
        } finally {
            Fs::removeRecursively($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideStrategyRuns(): iterable
    {
        foreach (['exact', 'neighbour', 'stale', 'collision', 'multiple', 'identity'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[Test]
    #[DataProvider('provideStrategyRuns')]
    public function itJudgesStrategyRenamesThroughThePublicGateAndNeverDerivesAnUnannouncedChange(string $mode): void
    {
        $specification = SyntheticTree::clean();
        $publication = static fn(string $key): string => json_encode(['symbols' => [['type' => 'method', 'name' => 'Replay\\Alpha::run', 'file' => 'src/Alpha.php', 'line' => 1, 'metrics' => [$key => 3]]]], \JSON_THROW_ON_ERROR);
        $specification['answers']['case:alpha|format:metrics'] = ['stdout' => $publication($mode === 'stale' ? 'ccn' : 'ccn.avg')];
        $specification['candidateAnswers']['case:alpha|format:metrics'] = ['stdout' => $publication($mode === 'stale' ? 'ccn' : ($mode === 'identity' ? 'ccn.avg' : 'ccn.mean'))];
        if ($mode !== 'identity') {
            $specification['maps']['metric-keys'] = ["strategy:avg\tstrategy:mean\tthe aggregation strategy spelling moves"];
        }
        if ($mode === 'multiple') {
            $specification['maps']['metric-keys'][] = "strategy:avg\tstrategy:average\ta second target is undecidable";
        }
        $root = SyntheticTree::create($specification);
        $strategy = 'src/Analysis/Evidence/Measurement/Contract/AggregationStrategy.php';
        $names = 'src/Analysis/Evidence/Measurement/Contract/MetricName.php';
        try {
            Fs::write($root . '/' . $strategy, "<?php\nenum AggregationStrategy: string {\n    case Avg = 'avg';\n    case Sum = 'sum';\n}\n");
            if ($mode === 'collision') {
                Fs::write($root . '/' . $names, "<?php\nfinal class MetricName {\n    public const string CCN = 'ccn';\n    public const string OTHER = 'ccn.mean';\n}\n");
            }
            foreach ([['git', 'add', '--', $strategy, $names], ['git', '-c', 'user.name=replay', '-c', 'user.email=replay@qmx', 'commit', '--quiet', '-m', 'Reference vocabulary']] as $command) {
                self::assertSame(0, Process::run($command, $root)['exit']);
            }
            if ($mode !== 'identity') {
                Fs::write($root . '/' . $strategy, "<?php\nenum AggregationStrategy: string {\n    case Mean = 'mean';\n" . ($mode === 'neighbour' ? "    case Sum = 'total';\n" : "    case Sum = 'sum';\n") . "}\n");
            }
            if ($mode === 'collision') {
                Fs::write($root . '/' . $names, "<?php\nfinal class MetricName {\n    public const string CCN = 'ccn';\n}\n");
            }
            $before = self::declarationBytes($root);
            $report = new GateReport();
            $options = Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--jobs=4'], $root);
            $refused = false;
            $refusalReason = '';
            try {
                (new Gate($options, $report))->compare();
            } catch (GateError $error) {
                $refused = true;
                $refusalReason = $error->getMessage();
            }
            if (\in_array($mode, ['exact', 'identity'], true)) {
                self::assertFalse($refused, $refusalReason);
                self::assertSame(GateReport::EXIT_GREEN, $report->exitCode(), $report->render());
            } else {
                self::assertTrue($refused || $report->exitCode() === GateReport::EXIT_RED, $report->render());
                if ($mode === 'stale') {
                    self::assertContains(FailureClass::MAP_STALE, $report->failureClasses(), $refusalReason . $report->render());
                }
                $derive = new GateReport();
                try {
                    self::assertSame([], (new Gate($options, $derive))->deriveDeclarations());
                } catch (GateError) {
                    self::assertTrue($refused);
                }
            }
            self::assertSame($before, self::declarationBytes($root));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideEnumerationRuns(): iterable
    {
        foreach (['exact', 'unannounced', 'neighbour', 'stale'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[Test]
    #[DataProvider('provideEnumerationRuns')]
    public function itJudgesScopedEnumerationIntentsThroughThePublicGate(string $mode): void
    {
        $specification = SyntheticTree::clean();
        $old = ['suppressed' => [], 'byMechanism' => [], 'neverMatched' => [], 'mechanisms' => ['a', 'b'], 'neighbour' => ['a', 'b']];
        $new = $old;
        if ($mode !== 'stale') {
            $new['mechanisms'] = ['b', 'a'];
        }
        if ($mode === 'neighbour') {
            $new['neighbour'] = ['b', 'a'];
        }
        $specification['answers']['case:alpha|format:suppressed'] = ['stdout' => json_encode($old, \JSON_THROW_ON_ERROR)];
        $specification['candidateAnswers']['case:alpha|format:suppressed'] = ['stdout' => json_encode($new, \JSON_THROW_ON_ERROR)];
        if ($mode !== 'unannounced') {
            $path = $mode === 'stale' ? 'neverPublished' : 'mechanisms';
            $descriptor = static fn(array $members): string => json_encode(['surface' => 'format:suppressed', 'path' => [$path], 'kind' => 'values', 'members' => $members], \JSON_THROW_ON_ERROR);
            $specification['maps']['report-values'] = [$descriptor(['a', 'b']) . "\t" . $descriptor(['b', 'a']) . "\tan exact enumeration permutation"];
        }
        $root = SyntheticTree::create($specification);
        try {
            $before = self::declarationBytes($root);
            $options = Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--jobs=4'], $root);
            $report = new GateReport();
            (new Gate($options, $report))->compare();
            self::assertSame($mode === 'exact' ? GateReport::EXIT_GREEN : GateReport::EXIT_RED, $report->exitCode(), $report->render());
            if ($mode !== 'exact') {
                self::assertContains($mode === 'stale' ? FailureClass::MAP_STALE : FailureClass::SURFACE_MISMATCH, $report->failureClasses(), $report->render());
                self::assertSame([], (new Gate($options, new GateReport()))->deriveDeclarations());
            }
            self::assertSame($before, self::declarationBytes($root));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideProducerRuns(): iterable
    {
        foreach (['exact', 'exact-truncated', 'wrong-counts', 'no-matches-truncated', 'standstill', 'unmatched', 'unmatched-truncated', 'foreign-channel', 'foreign-channel-truncated'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[Test]
    #[DataProvider('provideProducerRuns')]
    public function itCreditsOnlyMatchedProducerMovementsThroughThePublicGate(string $mode): void
    {
        $specification = SyntheticTree::clean();
        $channels = ['health.complexity', 'health.cohesion'];
        if ($mode === 'standstill') {
            $channels[] = 'health.typing';
        }
        sort($channels);
        $specification['cases']['alpha'] = array_map(static fn(string $channel): string => $channel . '@callable', $channels);
        $specification['static'] = $specification['fixture'] = array_fill_keys($channels, ['callable']);
        $specification['findings']['alpha'] = [];
        $specification['candidateFindings']['alpha'] = [];
        foreach ($channels as $index => $channel) {
            $reference = SyntheticTree::finding($specification['tuple'], $channel, 'declaration:callable:Replay\\Alpha::run' . $index . '@src/Alpha.php');
            $reference['rule'] = 'computed.health';
            $candidate = $reference;
            if ($channel !== 'health.typing') {
                $candidate['rule'] = ($mode === 'no-matches-truncated' || (str_starts_with($mode, 'unmatched') && $index === 1)) ? 'health.unlisted' : $channel;
            }
            if (str_starts_with($mode, 'foreign-channel') && $index === 1) {
                $candidate['channel'] = 'health.foreign';
            }
            $specification['findings']['alpha'][] = $reference;
            $specification['candidateFindings']['alpha'][] = $candidate;
            $target = $channel === 'health.typing' ? $channel : $channel . '#' . $channel;
            $specification['maps']['channels'][] = 'computed.health#' . $channel . "\t" . $target . "\ta declared producer movement";
        }
        if (\in_array($mode, ['exact-truncated', 'wrong-counts', 'no-matches-truncated', 'unmatched-truncated', 'foreign-channel-truncated'], true)) {
            $specification['truncated'] = ['alpha'];
        }
        if ($mode === 'wrong-counts') {
            $answers = SyntheticTree::caseAnswers('alpha', $specification['candidateFindings']['alpha'], true, []);
            self::assertArrayHasKey('file', $answers['case:alpha|check:output']);
            $output = json_decode($answers['case:alpha|check:output']['file'], true, 512, \JSON_THROW_ON_ERROR);
            $output['violationsMeta']['byRule'] = ['health.complexity' => 2];
            $specification['candidateAnswers']['case:alpha|check:output'] = ['file' => json_encode($output, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n"];
        }
        $root = SyntheticTree::create($specification);
        try {
            $before = self::declarationBytes($root);
            $options = Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--jobs=4'], $root);
            $report = new GateReport();
            (new Gate($options, $report))->compare();
            self::assertSame(\in_array($mode, ['exact', 'exact-truncated'], true) ? GateReport::EXIT_GREEN : GateReport::EXIT_RED, $report->exitCode(), $report->render());
            if (!\in_array($mode, ['exact', 'exact-truncated'], true)) {
                self::assertContains($mode === 'wrong-counts' ? FailureClass::RECORD_PROJECTION_MISMATCH : FailureClass::MAP_STALE, $report->failureClasses(), $report->render());
                if (\in_array($mode, ['no-matches-truncated', 'unmatched', 'unmatched-truncated', 'foreign-channel', 'foreign-channel-truncated'], true)) {
                    self::assertContains(FailureClass::SPLIT_UNMAPPED, $report->failureClasses(), $report->render());
                }
                self::assertSame([], (new Gate($options, new GateReport()))->deriveDeclarations());
            }
            self::assertSame($before, self::declarationBytes($root));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideInputRuns(): iterable
    {
        foreach (['exact', 'neighbour', 'unannounced', 'stale', 'unsupported declaration', 'unsupported path'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[Test]
    #[DataProvider('provideInputRuns')]
    public function itHandsThePublicGateOnlyNamedInputsAndNeverDerivesAnUnannouncedNeighbour(string $mode): void
    {
        $specification = SyntheticTree::clean();
        $definition = ['id' => 'alpha', 'description' => 'Named input byte profiles.', 'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'], 'args' => ['--new-flag=2']];
        $candidate = [
            'qmx.yaml' => "# new_key: comment\nnew_key: 1\nnested:\n  new_key: " . ($mode === 'neighbour' ? '99' : '2') . "\n",
            'src/Alpha.php' => "<?php\n/** @qmx-ignore a.new -- a.new reason */\nfunction build(): \\App\\NewName {}\n" . ($mode === 'unsupported declaration' ? "namespace App; class NewName {}\n" : ''),
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\NewName\\\\":"src/"}},"description":"unrelated prose","weight":1.50}',
        ];
        if ($mode === 'unsupported declaration') {
            $candidate['src/Alpha.php'] = "<?php\nnamespace App;\n/** @qmx-ignore a.new -- a.new reason */\nfunction build(): \\App\\NewName {}\nclass NewName {}\n";
        }
        if ($mode === 'unsupported path') {
            $definition['paths'] = ['src/NewName.php'];
            $candidate['src/NewName.php'] = "<?php\n";
        }
        $reference = [
            'qmx.yaml' => "# new_key: comment\nold_key: 1\nnested:\n  new_key: 2\n",
            'src/Alpha.php' => str_replace(['a.new --', '\\App\\NewName'], ['a.old --', '\\App\\OldName'], $candidate['src/Alpha.php']),
            'composer.json' => str_replace('App\\\\NewName\\\\', 'App\\\\OldName\\\\', $candidate['composer.json']),
        ];
        $specification['declarations']['cases/alpha/case.json'] = json_encode($definition, \JSON_THROW_ON_ERROR);
        foreach ($candidate as $path => $bytes) {
            $specification['declarations']['cases/alpha/' . $path] = $bytes;
        }
        if ($mode !== 'unannounced') {
            $specification['maps']['inputs'] = ["--old-flag\t--new-flag\tan exact CLI name", ($mode === 'stale' ? "unused_old:\tunused_new:" : "old_key:\tnew_key:") . "\tan exact root key"];
            $specification['maps']['symbols'] = ["App\\OldName\tApp\\NewName\tan exact symbol in named inputs"];
            $specification['maps']['channels'] = ["a.old\ta.new\tan exact executable directive target"];
        }
        if ($mode === 'stale') {
            $reference['qmx.yaml'] = $candidate['qmx.yaml'];
        }
        if ($mode === 'unsupported path') {
            $reference['src/NewName.php'] = "<?php\n";
        }
        $root = SyntheticTree::create($specification);
        try {
            $binary = Fs::read($root . '/bin/qmx');
            $guard = static fn(array $files, string $flag): string => '<?php $expected = ' . var_export($files, true) . ';'
                    . ' if (str_starts_with((string) getenv("QMX_GATE_INVOCATION"), "case:alpha|") && !in_array($argv[1] ?? "", ["graph:export", "rules"], true)) {'
                    . ' foreach ($expected as $path=>$bytes) { if (@file_get_contents(getcwd()."/".$path) !== $bytes) { fwrite(STDERR,"named input byte mismatch\\n"); exit(3); } }'
                    . ' if (!in_array(' . var_export($flag, true) . ', $argv, true)) { fwrite(STDERR,"named input CLI mismatch\\n"); exit(3); } } ?>';
            Fs::write($root . '/bin/qmx', $guard($reference, '--old-flag=2') . $binary);
            foreach ([['git', 'add', '--', 'bin/qmx'], ['git', '-c', 'user.name=replay', '-c', 'user.email=replay@qmx', 'commit', '--quiet', '-m', 'Reference input contract']] as $command) {
                self::assertSame(0, Process::run($command, $root)['exit']);
            }
            Fs::write($root . '/bin/qmx', $guard($candidate, '--new-flag=2') . $binary);
            $before = self::declarationBytes($root);
            $options = Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--jobs=4'], $root);
            $report = new GateReport();
            $refusal = null;
            try {
                (new Gate($options, $report))->compare();
            } catch (GateError $error) {
                $refusal = $error;
            }
            $unsupported = \in_array($mode, ['unsupported declaration', 'unsupported path'], true);
            $reason = $mode === 'unsupported path' ? 'physical path correspondence' : 'mapped PHP declaration';
            self::assertNull($refusal, $refusal?->getMessage() ?? '');
            self::assertSame($mode === 'exact' ? GateReport::EXIT_GREEN : GateReport::EXIT_RED, $report->exitCode(), $report->render());
            if ($unsupported) {
                self::assertSame([FailureClass::RUN_FAILED], $report->failureClasses(), $report->render());
                self::assertCount(1, $report->raised());
                self::assertSame('reference', $report->raised()[0]['scope']);
                self::assertStringContainsString($reason, $report->raised()[0]['detail']);
            }
            if ($mode !== 'exact') {
                if ($mode === 'stale') {
                    self::assertContains(FailureClass::MAP_STALE, $report->failureClasses(), $report->render());
                }
                $deriveReport = new GateReport();
                self::assertSame([], (new Gate($options, $deriveReport))->deriveDeclarations());
                if ($unsupported) {
                    self::assertSame(GateReport::EXIT_RED, $deriveReport->exitCode(), $deriveReport->render());
                    self::assertSame([FailureClass::RUN_FAILED], $deriveReport->failureClasses());
                    self::assertCount(1, $deriveReport->raised());
                    self::assertSame('reference', $deriveReport->raised()[0]['scope']);
                    self::assertStringContainsString($reason, $deriveReport->raised()[0]['detail']);
                }
            }
            self::assertSame($before, self::declarationBytes($root));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /** @return array<string, string> */
    private static function declarationBytes(string $root): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/finding-gate', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = Fs::read($file->getPathname());
            }
        }
        ksort($files);
        return $files;
    }
}
