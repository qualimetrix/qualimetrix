<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * `check`'s `projectionOptions()` decodes `--suppress-path`, `--suppress-namespace`
 * and `--baseline` — a KIND:VALUE selector parse and a command-line-spelling
 * type check, neither of which reads an analysis result. It used to run after
 * {@see CheckCommand::runAnalysis()}, so a malformed selector or a wrong-typed
 * value (an embedder's {@see \Symfony\Component\Console\Input\ArrayInput} can
 * hand any PHP value to a single-valued option) was refused only once the run
 * had already done its work.
 *
 * {@see CountingAnalysisPipeline} counts `analyze()` calls: the DoD's evidence
 * for "refused before analysis runs" is that count, not a timing comparison.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandProjectionOptionsBeforeAnalysisTest extends TestCase
{
    private const string FIXTURE = 'tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php';

    /** @return iterable<string, array{string, mixed, string}> */
    public static function provideMalformedValues(): iterable
    {
        yield '--baseline of the wrong shape (array instead of a scalar)' => [
            'baseline',
            ['a', 'b'],
            'Invalid --baseline value of type array',
        ];
        yield '--suppress-path with a wrong-typed element' => [
            'suppress-path',
            [true],
            'Invalid --suppress-path value of type bool',
        ];
        yield '--suppress-path without a KIND: prefix' => [
            'suppress-path',
            ['no-colon-here'],
            'must use KIND:VALUE',
        ];
        yield '--suppress-namespace without a KIND: prefix' => [
            'suppress-namespace',
            ['no-colon-here'],
            'must use KIND:VALUE',
        ];
        yield '--suppress-namespace with an unknown selector kind' => [
            'suppress-namespace',
            ['weirdkind:App\\Service'],
            'Unknown selector kind',
        ];
    }

    #[Test]
    #[DataProvider('provideMalformedValues')]
    public function itRefusesAMalformedProjectionOptionBeforeAnalysis(string $option, mixed $value, string $expectedMessage): void
    {
        [$command, $pipeline] = $this->createCommand();
        $tester = new CommandTester($command);

        $exit = $tester->execute(
            ['paths' => [self::FIXTURE], '--format' => 'json', '--' . $option => $value],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $exit, $tester->getDisplay());
        self::assertSame(
            0,
            $pipeline->calls,
            'The analysis pipeline ran before ' . $option . ' was refused: ' . $tester->getDisplay(),
        );
        self::assertStringContainsString($expectedMessage, $tester->getDisplay());
    }

    /**
     * The legitimate neighbour: a well-formed selector still runs the
     * analysis and suppresses what it names — the parse moved earlier, but the
     * `PathPattern`/`NamespacePattern` it decodes still reaches the same
     * filter afterward. `parses_with_no_findings.php` cannot witness this — it
     * has nothing to suppress — so this uses a fixture with one findable
     * violation and asserts the count drops to zero, not just "not refused".
     */
    #[Test]
    public function itStillSuppressesByAWellFormedSelector(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-suppress-path-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $config = $dir . '/qmx.yaml';
        // Isolated from the repository's own qmx.yaml, whose whitelists could
        // otherwise absorb the fixture's one finding before either run sees it.
        file_put_contents($config, "onlyRules: ['code-smell.error-suppression']\n");
        $fixture = $dir . '/Fixture.php';
        file_put_contents($fixture, <<<'PHP'
            <?php

            class Fixture
            {
                public function run(): void
                {
                    echo @$this->missing();
                }
            }
            PHP);

        $previous = getcwd();
        self::assertNotFalse($previous);
        chdir($dir);
        try {
            [$withoutSuppression] = $this->createCommand();
            $withoutTester = new CommandTester($withoutSuppression);
            $withoutTester->execute(
                ['paths' => [$dir], '--format' => 'json', '--config' => $config],
                ['capture_stderr_separately' => true],
            );
            /** @var array{summary: array{violationCount: int}, violations: list<array{file: string}>} $withoutPayload */
            $withoutPayload = json_decode($withoutTester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(1, $withoutPayload['summary']['violationCount'], $withoutTester->getDisplay());
            // The relative path a real run reports, not a guessed one: project
            // root resolution (no composer.json above a temp directory) is not
            // this test's concern.
            $reportedPath = $withoutPayload['violations'][0]['file'];

            [$withSuppression, $pipeline] = $this->createCommand();
            $withTester = new CommandTester($withSuppression);
            $exit = $withTester->execute(
                [
                    'paths' => [$dir],
                    '--format' => 'json',
                    '--config' => $config,
                    '--suppress-path' => ['exact:' . $reportedPath],
                ],
                ['capture_stderr_separately' => true],
            );
            self::assertNotSame(3, $exit, $withTester->getDisplay());
            self::assertSame(1, $pipeline->calls);
            /** @var array{summary: array{violationCount: int}} $withPayload */
            $withPayload = json_decode($withTester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(0, $withPayload['summary']['violationCount'], $withTester->getDisplay());
        } finally {
            chdir($previous);
            unlink($fixture);
            unlink($config);
            (new Filesystem())->remove($dir . '/.qmx-cache');
            rmdir($dir);
        }
    }

    #[Test]
    public function itSuppressesAFilelessNamespaceByNamespaceRatherThanByPath(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-namespace-suppression-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $config = $dir . '/qmx.yaml';
        file_put_contents($dir . '/A.php', <<<'PHP'
            <?php

            namespace App\Aggregate;

            final class A
            {
                public function dependency(): \External\B
                {
                    return new \External\B();
                }
            }
            PHP);
        $base = <<<'YAML'
            only_rules: [coupling.cbo]
            rules:
              coupling.cbo:
                class: {enabled: false}
                namespace: {warning: 1, error: 100, min_class_count: 1}
            YAML;
        $previous = getcwd();
        self::assertNotFalse($previous);
        chdir($dir);

        try {
            foreach (['' => 1, "\nsuppress_paths: [{regex: '.*'}]\n" => 1, "\nsuppress_namespaces: [{exact: 'App\\Aggregate'}]\n" => 0] as $suppression => $count) {
                file_put_contents($config, $base . $suppression);
                [$command] = $this->createCommand();
                $tester = new CommandTester($command);
                $exit = $tester->execute(
                    ['paths' => [$dir], '--config' => $config, '--format' => 'json', '--no-cache' => true, '--workers' => '0'],
                    ['capture_stderr_separately' => true],
                );
                self::assertSame(0, $exit, $tester->getDisplay() . $tester->getErrorOutput());
                /** @var array{violations: list<array{file: ?string, subject: string, code: string}>} $payload */
                $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertCount($count, $payload['violations'], $suppression);
                if ($count === 1) {
                    self::assertNull($payload['violations'][0]['file']);
                    self::assertSame('ns:App\\Aggregate', $payload['violations'][0]['subject']);
                    self::assertSame('coupling.cbo', $payload['violations'][0]['code']);
                }
            }
        } finally {
            chdir($previous);
            (new Filesystem())->remove($dir);
        }
    }

    /**
     * A well-formed `--baseline` naming a file that does not exist is a
     * question the file system answers, not the option parser — yet it needs
     * no analysis result either, so it is refused before the pipeline runs,
     * like the malformed values above.
     */
    #[Test]
    public function itRefusesAMissingBaselineFileBeforeAnalysis(): void
    {
        [$command, $pipeline] = $this->createCommand();
        $tester = new CommandTester($command);

        $exit = $tester->execute(
            [
                'paths' => [self::FIXTURE],
                '--format' => 'json',
                '--baseline' => sys_get_temp_dir() . '/qmx-does-not-exist-' . bin2hex(random_bytes(6)) . '.json',
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $exit, $tester->getDisplay());
        self::assertSame(
            0,
            $pipeline->calls,
            'The analysis pipeline ran before the missing baseline file was refused: ' . $tester->getDisplay(),
        );
        self::assertStringContainsString('Baseline file not found', $tester->getDisplay());
    }

    /** @return array{CheckCommand, CountingAnalysisPipeline} */
    private function createCommand(): array
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $original */
        $original = $container->get(CheckCommand::class);

        $reflection = new ReflectionClass(CheckCommand::class);
        $property = fn(string $name) => $reflection->getProperty($name)->getValue($original);

        $pipeline = new CountingAnalysisPipeline($property('analyzer'));

        $command = new CheckCommand(
            $pipeline,
            $property('findingFilterOrchestrator'),
            $property('runtimeConfigurator'),
            $property('resultPresenter'),
            $property('ruleInputValidator'),
            $property('checkScopeResolver'),
            $property('configurationInputAdapter'),
            $property('configurationResolvers'),
            $property('runTargetSession'),
        );

        return [$command, $pipeline];
    }
}

/**
 * Counts `analyze()` calls without changing what it returns — see
 * {@see CheckCommandProjectionOptionsBeforeAnalysisTest} for why a count is
 * the evidence and not a timing comparison.
 *
 * @internal
 */
final class CountingAnalysisPipeline implements AnalysisPipelineInterface
{
    public int $calls = 0;

    public function __construct(private readonly AnalysisPipelineInterface $delegate) {}

    public function analyze(RunConfiguration $configuration): AnalysisResult
    {
        ++$this->calls;

        return $this->delegate->analyze($configuration);
    }
}
