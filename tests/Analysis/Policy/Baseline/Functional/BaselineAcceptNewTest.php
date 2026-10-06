<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\FileTarget\TargetPath;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FindingFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BaselineUpdateCommand::class)]
#[CoversClass(BaselineRun::class)]
#[CoversClass(BaselineUpdater::class)]
final class BaselineAcceptNewTest extends TestCase
{
    private string $root;
    private string $cwd;
    private string $path;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->root = TempDirectory::create('qmx-accept-new-');
        $this->path = $this->root . '/baseline.json';
        mkdir($this->root . '/src/Domain', 0777, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->root . '/src/Foo.php', '<?php namespace App; class Foo { public function bar(): object { return new \\App\\Domain\\Bar(); } }');
        file_put_contents($this->root . '/src/Domain/Bar.php', '<?php namespace App\\Domain; class Bar {}');
        file_put_contents($this->root . '/qmx.yaml', <<<'YAML'
            architecture:
              layers:
                - name: app
                  patterns: ['App\Foo']
                - name: domain
                  patterns: ['App\Domain\**']
              allow:
                app: []
                domain: []
              coverage-gap: ignore
            YAML);
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        TempDirectory::remove($this->root);
    }

    #[Test]
    public function itCarriesTheMigrationWithoutTighteningOldAcceptance(): void
    {
        foreach ([false, true] as $human) {
            $deltas = [];
            foreach ([false, true] as $onlyRule) {
                $entry = new BaselineEntry(BaselineIdentity::forFinding(FindingFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 20)), [40, 50], 2, BaselineEntryMode::Suppress);
                $baseline = new Baseline(new DateTimeImmutable('2000-01-01'), ['src'], [$entry], new RecordedExclusions([], GeneratedFilePolicy::Exclude));
                (new BaselineWriter())->write($baseline, TargetPath::resolve($this->path), AbsolutePath::fromString($this->root));
                if ($human) {
                    $payload = json_decode((string) file_get_contents($this->path), true, flags: \JSON_THROW_ON_ERROR);
                    $payload['entries'][$entry->identity->subjectKey][0] = ['mode' => 'suppress', 'magnitudes' => [50.123456789, 40.987654321], 'channel' => 'complexity.ccn'];
                    $payload['entries']['file:src/Held.php'] = [['mode' => 'unknown', 'count' => 1, 'channel' => 'code-smell.goto']];
                    file_put_contents($this->path, json_encode($payload, \JSON_THROW_ON_ERROR));
                }
                $before = (string) file_get_contents($this->path);
                [$tester, $pipeline, $loader] = $this->command();
                $loaded = $loader->load(BaselineLoader::preflight($this->path));
                $tester->execute(['baseline' => $this->path, 'paths' => ['src'], '--accept-new' => ['architecture.layer-violation'], '--only-rule' => $onlyRule ? ['architecture.layer-violation'] : [], '--no-progress' => true], ['capture_stderr_separately' => true]);
                self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
                self::assertSame(1, $pipeline->calls);
                self::assertStringContainsString('accepted', $tester->getDisplay());
                $after = $loader->load(BaselineLoader::preflight($this->path));
                $retained = $after->findByIdentity($entry->identity);
                self::assertNotNull($retained);
                self::assertSame($loaded->entries[0]->toArray(), $retained->toArray());
                self::assertSame($loaded->scope, $after->scope);
                self::assertTrue($loaded->exclusions->equals($after->exclusions));
                self::assertSame(array_map(static fn($inert) => $inert->raw, $loaded->inertEntries), array_map(static fn($inert) => $inert->raw, $after->inertEntries));
                self::assertNotEquals($loaded->generated, $after->generated);
                $added = array_values(array_filter($after->entries, static fn($added) => $added->identity->channel->code === 'architecture.layer-violation'));
                self::assertNotEmpty($added);
                $deltas[] = array_map(static fn($added) => [$added->identity->key(), $added->toArray()], $added);
                if (!$human) {
                    $oldLine = array_values(array_filter(explode("\n", $before), static fn(string $line): bool => str_contains($line, '"channel":"complexity.ccn"')));
                    self::assertCount(1, $oldLine);
                    self::assertStringContainsString($oldLine[0], (string) file_get_contents($this->path));
                }
                unlink($this->path);
            }
            self::assertSame($deltas[0], $deltas[1]);
        }
    }

    #[Test]
    public function itRefusesAcceptNewWhenTheRunDoesNotCoverTheRecordedScope(): void
    {
        $this->emptyBaseline();
        $before = (string) file_get_contents($this->path);
        [$tester, $pipeline] = $this->command();

        $tester->execute([
            'baseline' => $this->path,
            'paths' => ['src/Foo.php'],
            '--accept-new' => ['architecture.layer-violation'],
            '--no-progress' => true,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(1, $pipeline->calls);
        self::assertStringContainsString('does not cover', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame($before, file_get_contents($this->path));
    }

    #[Test]
    public function itLetsForceBypassOnlyTheScopeGuardWithoutInventingAcceptance(): void
    {
        $this->emptyBaseline();
        $before = (string) file_get_contents($this->path);
        [$tester, $pipeline] = $this->command();

        $tester->execute([
            'baseline' => $this->path,
            'paths' => ['src/Foo.php'],
            '--accept-new' => ['architecture.layer-violation'],
            '--force' => true,
            '--no-progress' => true,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(1, $pipeline->calls);
        self::assertStringContainsString('outside-coverage', $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->path));
    }

    #[Test]
    public function itRefusesInvalidAcceptanceChannelsBeforeAnalysis(): void
    {
        $this->emptyBaseline();
        $before = (string) file_get_contents($this->path);
        foreach ([['--accept-new' => ['unknown.channel']], ['--accept-new' => ['architecture.*']], ['--accept-new' => ['architecture.layer-violation:class']], ['--accept-new' => ['architecture.coverage-gap']], ['--accept-new' => ['baseline.unused-entry']], ['--accept-new' => ['complexity.ccn'], '--record-exclusions' => true]] as $options) {
            [$tester, $pipeline] = $this->command();
            $tester->execute(['baseline' => $this->path, 'paths' => ['src'], '--no-progress' => true, ...$options], ['capture_stderr_separately' => true]);
            self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
            self::assertSame(0, $pipeline->calls, 'Admission must precede the real pipeline.');
            self::assertSame($before, file_get_contents($this->path));
        }
        file_put_contents($this->root . '/qmx.yaml', "computed_metrics:\n  computed.mine:\n    formula: '4'\n    warning: 3\n");
        [$tester, $pipeline] = $this->command();
        $tester->execute(['baseline' => $this->path, 'paths' => ['src'], '--accept-new' => ['computed.mine', 'code-smell.goto'], '--no-progress' => true], ['capture_stderr_separately' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(1, $pipeline->calls, 'The configured computed channel is declared before admission.');
    }

    #[Test]
    public function itLeavesTheFileUntouchedWhenTheNamedChannelWasNotMeasured(): void
    {
        $this->emptyBaseline();
        $before = (string) file_get_contents($this->path);
        foreach ([['--disable-rule' => ['code-smell.goto']], ['--only-rule' => ['complexity.ccn']], []] as $options) {
            [$tester] = $this->command();
            $tester->execute(['baseline' => $this->path, 'paths' => ['src'], '--accept-new' => ['code-smell.goto'], '--no-progress' => true, ...$options], ['capture_stderr_separately' => true]);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
            self::assertStringContainsString($options === [] ? '(no-finding)' : '(not-measured)', $tester->getDisplay());
            self::assertSame($before, file_get_contents($this->path));
            self::assertFileDoesNotExist($this->path . '.lock');
        }
    }

    private function emptyBaseline(): void
    {
        (new BaselineWriter())->write(new Baseline((new FixedClock())->now(), ['src'], [], new RecordedExclusions([], GeneratedFilePolicy::Exclude)), TargetPath::resolve($this->path), AbsolutePath::fromString($this->root));
        unlink($this->path . '.lock');
    }

    /** @return array{CommandTester, object{calls: int}&AnalysisPipelineInterface, BaselineLoader} */
    private function command(): array
    {
        $container = (new ContainerFactory())->create();
        $original = $container->get(BaselineUpdateCommand::class);
        \assert($original instanceof BaselineUpdateCommand);
        $property = static fn(object $object, string $name): mixed => (new ReflectionProperty($object, $name))->getValue($object);
        $run = $property($original, 'baselineRun');
        \assert($run instanceof BaselineRun);
        $measured = $property($run, 'measuredFindingSet');
        \assert($measured instanceof MeasuredFindingSet);
        $delegate = $property($measured, 'analyzer');
        \assert($delegate instanceof AnalysisPipelineInterface);
        $pipeline = new class ($delegate) implements AnalysisPipelineInterface {
            public int $calls = 0;
            public function __construct(private readonly AnalysisPipelineInterface $delegate) {}
            public function analyze(RunConfiguration $configuration): AnalysisResult
            {
                ++$this->calls;
                return $this->delegate->analyze($configuration);
            }
        };
        $run = new BaselineRun($property($run, 'runtimeConfigurator'), new MeasuredFindingSet($pipeline, $property($measured, 'projector')), $property($run, 'ruleInputValidator'), $property($run, 'configurationInputAdapter'), $property($run, 'runConfigurationPreparation'), $property($run, 'findingExclusionsResolver'), $property($run, 'errorStream'), $property($run, 'projectTree'), $property($run, 'composerReader'));
        $loader = $property($original, 'loader');
        \assert($loader instanceof BaselineLoader);
        $command = new BaselineUpdateCommand($run, $loader, $property($original, 'updater'), $property($original, 'writer'), $property($original, 'ruleCoverage'));
        $command->setRefusalPresenter((new ReflectionProperty(BaselineCommand::class, 'refusalPresenter'))->getValue($original));
        return [new CommandTester($command), $pipeline, $loader];
    }
}
