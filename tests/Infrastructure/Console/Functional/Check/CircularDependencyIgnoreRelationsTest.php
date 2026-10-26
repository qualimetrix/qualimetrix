<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\CircularDependency\CircularDependencyConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `ignore-relations` reaches cycle preparation through the composed container:
 * the option is resolved with the run's rule options and read by the
 * preparation, not handed to it by the run.
 */
#[CoversClass(CircularDependencyAnalysis::class)]
#[CoversClass(CircularDependencyConfigurator::class)]
final class CircularDependencyIgnoreRelationsTest extends TestCase
{
    /** The entity names its repository only inside a mapping attribute. */
    private const string ATTRIBUTE_MAPPED_REPOSITORY = <<<'PHP'
        <?php
        namespace App;

        #[\Attribute]
        final class Entity { public function __construct(public string $repositoryClass) {} }

        #[Entity(repositoryClass: Repo::class)]
        final class E {}

        final class Repo { public function find(): E { return new E(); } }
        PHP;

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-cycle-ignore-relations-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->fixture . '/src/a.php', self::ATTRIBUTE_MAPPED_REPOSITORY);
    }

    protected function tearDown(): void
    {
        @unlink($this->fixture . '/src/a.php');
        @unlink($this->fixture . '/composer.json');
        @unlink($this->fixture . '/qmx.yaml');
        @rmdir($this->fixture . '/src');
        @rmdir($this->fixture);
    }

    #[Test]
    public function itReportsTheAttributeMappedCycleWithoutTheOption(): void
    {
        [$exit, $report] = $this->check();

        self::assertSame(2, $exit);
        self::assertSame(['Circular dependency (2 classes): E → Repo → E'], self::cycleMessages($report));
    }

    #[Test]
    public function itDropsTheCycleWhenAttributeArgumentsAreIgnoredInTheConfigurationFile(): void
    {
        [$exit, $report] = $this->check("rules:\n  architecture.circular-dependency:\n    ignore_relations: [attribute_argument]\n");

        self::assertSame(0, $exit);
        self::assertSame([], self::cycleMessages($report));
    }

    #[Test]
    public function itDropsTheCycleWhenAttributeArgumentsAreIgnoredOnTheCommandLine(): void
    {
        [$exit, $report] = $this->check(null, ['--rule-opt' => ['architecture.circular-dependency:ignore-relations=[attribute_argument]']]);

        self::assertSame(0, $exit);
        self::assertSame([], self::cycleMessages($report));
    }

    #[Test]
    public function itKeepsTheCycleWhenOnlyUnrelatedKindsAreIgnored(): void
    {
        [$exit, $report] = $this->check("rules:\n  architecture.circular-dependency:\n    ignore_relations: [inheritance, attribute]\n");

        self::assertSame(2, $exit);
        self::assertSame(['Circular dependency (2 classes): E → Repo → E'], self::cycleMessages($report));
    }

    #[Test]
    public function itReportsAnEmptyListExactlyAsAnAbsentOne(): void
    {
        [$absentExit, $absent] = $this->check();
        [$emptyExit, $empty] = $this->check("rules:\n  architecture.circular-dependency:\n    ignore_relations: []\n");

        self::assertSame($absentExit, $emptyExit);
        self::assertSame(self::withoutRunClock($absent), self::withoutRunClock($empty));
    }

    #[Test]
    public function itRefusesAnUnknownRelationInTheConfigurationFileByName(): void
    {
        file_put_contents($this->fixture . '/qmx.yaml', "rules:\n  architecture.circular-dependency:\n    ignore_relations: [attribute_args]\n");
        $tester = $this->execute([]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('"rules.architecture.circular-dependency.ignore_relations[0]"', $tester->getErrorOutput());
        self::assertStringContainsString('got "attribute_args"', $tester->getErrorOutput());
    }

    #[Test]
    public function itRefusesAnUnknownRelationOnTheCommandLineByName(): void
    {
        $tester = $this->execute(['--rule-opt' => ['architecture.circular-dependency:ignore-relations=[attribute_args]']]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('--rule-opt', $tester->getErrorOutput());
        self::assertStringContainsString('got "attribute_args"', $tester->getErrorOutput());
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{int, array<mixed>}
     */
    private function check(?string $configuration = null, array $options = []): array
    {
        if ($configuration !== null) {
            file_put_contents($this->fixture . '/qmx.yaml', $configuration);
        }
        $tester = $this->execute(['--format' => 'json', ...$options]);
        @unlink($this->fixture . '/qmx.yaml');

        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report, $tester->getErrorOutput());

        return [$tester->getStatusCode(), $report];
    }

    /** @param array<string, mixed> $options */
    private function execute(array $options): CommandTester
    {
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);

        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(
                [
                    'paths' => ['src'],
                    '--workers' => '0',
                    '--no-cache' => true,
                    '--no-progress' => true,
                    '--only-rule' => ['architecture.circular-dependency'],
                    ...$options,
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }

    /**
     * @param array<mixed> $report
     *
     * @return list<string>
     */
    private static function cycleMessages(array $report): array
    {
        self::assertIsList($report['violations'] ?? null);
        $messages = [];
        foreach ($report['violations'] as $violation) {
            self::assertIsArray($violation);
            if (($violation['rule'] ?? null) === 'architecture.circular-dependency') {
                self::assertIsString($violation['message']);
                $messages[] = $violation['message'];
            }
        }

        return $messages;
    }

    /**
     * @param array<mixed> $report
     *
     * @return array<mixed>
     */
    private static function withoutRunClock(array $report): array
    {
        self::assertIsArray($report['meta'] ?? null);
        self::assertIsArray($report['summary'] ?? null);
        unset($report['meta']['timestamp'], $report['summary']['duration']);

        return $report;
    }
}
