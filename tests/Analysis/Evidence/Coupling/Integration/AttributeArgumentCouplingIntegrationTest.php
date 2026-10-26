<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\ClassRank\ClassRankCollector;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingCollector;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A reference written in an attribute argument is recorded as
 * `attribute_argument`, where it used to be the `class_const_fetch` the same
 * reference gets in code. Coupling counts distinct targets, not edge kinds, so
 * the run must measure the attribute form exactly as it measures the code
 * form — the pair is the before/after of the retyped edge.
 */
#[CoversClass(CouplingCollector::class)]
#[CoversClass(ClassRankCollector::class)]
final class AttributeArgumentCouplingIntegrationTest extends TestCase
{
    private const string ATTRIBUTE_FORM = <<<'PHP'
        <?php
        namespace App;

        #[\Attribute]
        final class Entity { public function __construct(public string $repositoryClass) {} }

        #[Entity(repositoryClass: Repo::class)]
        final class E {}

        final class Repo { public function find(): E { return new E(); } }
        PHP;

    private const string CODE_FORM = <<<'PHP'
        <?php
        namespace App;

        #[\Attribute]
        final class Entity { public function __construct(public string $repositoryClass) {} }

        #[Entity(repositoryClass: 'App\Repo')]
        final class E { private const REPOSITORY = Repo::class; }

        final class Repo { public function find(): E { return new E(); } }
        PHP;

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-attribute-argument-coupling-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->fixture . '/src/a.php');
        @unlink($this->fixture . '/composer.json');
        @rmdir($this->fixture . '/src');
        @rmdir($this->fixture);
    }

    #[Test]
    public function itMeasuresAnAttributeArgumentReferenceAsTheSameReferenceInCode(): void
    {
        $attribute = $this->couplingMetrics(self::ATTRIBUTE_FORM);
        $code = $this->couplingMetrics(self::CODE_FORM);

        self::assertSame($code, $attribute);
        self::assertSame(1, $attribute['class App\E']['coupling.ca']);
        self::assertSame(2, $attribute['class App\E']['coupling.ce']);
        self::assertSame(2, $attribute['class App\E']['coupling.cbo']);
        self::assertSame(1, $attribute['class App\Repo']['coupling.ca']);
        self::assertSame(1, $attribute['class App\Repo']['coupling.ce']);
        self::assertGreaterThan(
            $attribute['class App\Repo']['coupling.class-rank'],
            $attribute['class App\E']['coupling.class-rank'],
        );
    }

    /**
     * @return array<string, array<string, int|float>> coupling metrics by "type name" of every symbol
     */
    private function couplingMetrics(string $source): array
    {
        file_put_contents($this->fixture . '/src/a.php', $source);

        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);

        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(
                ['paths' => ['src'], '--workers' => '0', '--format' => 'metrics', '--fail-on' => 'none', '--no-cache' => true],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsList($payload['symbols'] ?? null, $tester->getErrorOutput());

        $metrics = [];
        foreach ($payload['symbols'] as $symbol) {
            self::assertIsArray($symbol);
            self::assertIsArray($symbol['metrics'] ?? null);
            $coupling = array_filter(
                $symbol['metrics'],
                static fn(string $name): bool => str_starts_with($name, 'coupling.'),
                \ARRAY_FILTER_USE_KEY,
            );
            ksort($coupling);
            $metrics[$symbol['type'] . ' ' . $symbol['name']] = $coupling;
        }
        ksort($metrics);

        return $metrics;
    }
}
