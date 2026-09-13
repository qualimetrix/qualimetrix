<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--format-opt` with a key no formatter reads, through the real container.
 *
 * A unit test of {@see FormatterContextFactory} proves the refusal but not that
 * it runs: the factory was registered with no arguments at all, so a constructor
 * dependency added without the matching DI line would leave `bin/qmx check`
 * exactly as silent as before while every unit test stayed green. Hence the real
 * `ContainerFactory` here, and hence the passing halves in the same file — a
 * refusal that also refuses a real key would be the same defect wearing the
 * opposite sign.
 */
#[CoversClass(FormatterContextFactory::class)]
#[CoversClass(CheckCommand::class)]
final class FormatOptionKeyRefusalTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-format-opt-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        // Without it the run reports an unrelated configuration warning, whose exit
        // code 2 would drown the 0-vs-3 distinction every case here rests on.
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );
        // Two branchy classes, so `violations=1` has something to cut down to.
        foreach (['One', 'Two'] as $name) {
            $branches = '';
            for ($i = 1; $i <= 30; ++$i) {
                $branches .= \sprintf("        if (\$x > %d) {\n            \$y += %d;\n        }\n", $i, $i);
            }

            file_put_contents(
                \sprintf('%s/src/%s.php', $this->fixture, $name),
                \sprintf(
                    "<?php\n\nnamespace Sample;\n\nclass %s\n{\n    public function run(int \$x): int\n    {\n        \$y = 0;\n%s\n        return \$y;\n    }\n}\n",
                    $name,
                    $branches,
                ),
            );
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->fixture . '/src/One.php');
        @unlink($this->fixture . '/src/Two.php');
        @unlink($this->fixture . '/composer.json');
        @rmdir($this->fixture . '/src');
        @rmdir($this->fixture);
    }

    #[Test]
    public function itRefusesAFormatOptKeyNoFormatterReads(): void
    {
        $tester = $this->execute(['--format-opt' => ['zzz=1']]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Unknown --format-opt key "zzz"', $tester->getErrorOutput());
    }

    #[Test]
    public function itNamesEveryKnownKeyInTheRefusal(): void
    {
        $tester = $this->execute(['--format-opt' => ['zzz=1']]);

        self::assertStringContainsString(
            'Known keys: contributors, limit, project-name, rank-by, top, violations.',
            $tester->getErrorOutput(),
        );
    }

    /**
     * One option set can run through several formats; a key another formatter
     * reads must pass rather than be refused as a typo.
     */
    #[Test]
    public function itAcceptsAKeyReadOnlyByAnotherFormatter(): void
    {
        $tester = $this->execute(['--format' => 'json', '--format-opt' => ['contributors=3']]);

        self::assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function itStillAppliesAKeyTheSelectedFormatterReads(): void
    {
        $unbounded = $this->decodeViolations($this->execute(['--format' => 'json']));
        $bounded = $this->decodeViolations($this->execute(['--format' => 'json', '--format-opt' => ['violations=1']]));

        self::assertGreaterThan(1, \count($unbounded), 'The fixture must produce more than one finding for this pair to mean anything.');
        self::assertCount(1, $bounded);
    }

    /** `--all` writes `violations` itself, after the check; it must not refuse its own key. */
    #[Test]
    public function itAcceptsTheAllFlag(): void
    {
        $tester = $this->execute(['--all' => true]);

        self::assertSame(0, $tester->getStatusCode());
    }

    /** @return list<mixed> */
    private function decodeViolations(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('violations', $payload);
        $violations = $payload['violations'];
        self::assertIsList($violations);

        return $violations;
    }

    /** @param array<string, mixed> $options */
    private function execute(array $options): CommandTester
    {
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);

        $tester = new CommandTester($command);

        // The project root comes from the process working directory, exactly as
        // `--working-dir` sets it on the real binary. Left at the repository, the
        // run reports uncovered autoload entries and exits 2, which would hide
        // the 0-vs-3 distinction every case here rests on.
        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(
                [
                    'paths' => ['src'],
                    '--workers' => '0',
                    '--fail-on' => 'none',
                    ...$options,
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}
