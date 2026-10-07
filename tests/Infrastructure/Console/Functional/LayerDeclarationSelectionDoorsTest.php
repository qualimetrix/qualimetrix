<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ArchitectureConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ArchitectureConfigurator::class)]
#[CoversClass(CheckCommand::class)]
final class LayerDeclarationSelectionDoorsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-layer-doors-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/src', 0777, true);
        file_put_contents($this->directory . '/composer.json', '{"autoload":{"psr-4":{"Doors\\\\":"src/"}}}');
        file_put_contents($this->directory . '/src/Example.php', '<?php namespace Doors; class Example { public function run(): void {} }');
        file_put_contents($this->directory . '/qmx.yaml', <<<'YAML'
            architecture:
              layers:
                - name: nowhere
                  patterns: ['Missing\**']
              coverage-gap: error
            YAML);
    }

    protected function tearDown(): void
    {
        unlink($this->directory . '/src/Example.php');
        rmdir($this->directory . '/src');
        unlink($this->directory . '/qmx.yaml');
        unlink($this->directory . '/composer.json');
        rmdir($this->directory);
    }

    /** @param array<string, mixed> $arguments */
    #[Test]
    #[TestWith([['--only-rule' => ['complexity.ccn']]])]
    #[TestWith([['--disable-rule' => ['architecture.layer-violation']]])]
    #[TestWith([[], true])]
    public function itKeepsDeclarationErrorsWhenTheOldProducerIsFilteredOrDisabled(array $arguments, bool $yamlDisabled = false): void
    {
        if ($yamlDisabled) {
            file_put_contents($this->directory . '/qmx.yaml', "\nrules:\n  architecture.layer-violation:\n    enabled: false\n", \FILE_APPEND);
        }
        [$exit, $report, $display] = $this->check($arguments);
        self::assertSame(2, $exit, $display);
        self::assertContains('architecture.coverage-gap', array_column($report['violations'], 'rule'));
        self::assertContains('architecture.unreachable-layer', array_column($report['violations'], 'rule'));
    }

    #[Test]
    public function itBuildsDeclarationEvidenceWhenBothOtherConsumersAreDisabled(): void
    {
        file_put_contents($this->directory . '/qmx.yaml', "\nrules:\n  architecture.layer-violation:\n    enabled: false\n  architecture.unassigned-class:\n    enabled: false\n", \FILE_APPEND);
        [$exit, $report, $display] = $this->check([]);
        self::assertSame(2, $exit, $display);
        self::assertContains('architecture.coverage-gap', array_column($report['violations'], 'rule'));
        self::assertContains('architecture.unreachable-layer', array_column($report['violations'], 'rule'));
    }

    /** @param array<string, mixed> $arguments */
    #[Test]
    #[TestWith([['--disable-rule' => ['architecture.layer-declaration']]])]
    #[TestWith([['--disable-rule' => ['architecture.*']]])]
    #[TestWith([[], true])]
    public function itHonoursTheDeclarationProducersOwnOffSwitch(array $arguments, bool $yamlDisabled = false): void
    {
        if ($yamlDisabled) {
            file_put_contents($this->directory . '/qmx.yaml', "\nrules:\n  architecture.layer-declaration:\n    enabled: false\n", \FILE_APPEND);
        }
        [$exit, $report, $display] = $this->check($arguments);
        self::assertSame(0, $exit, $display);
        self::assertNotContains('architecture.coverage-gap', array_column($report['violations'], 'rule'));
        self::assertNotContains('architecture.unreachable-layer', array_column($report['violations'], 'rule'));
    }

    #[Test]
    public function itKeepsOtherValidatorsUnderOnlyAndHonoursExplicitDiagnosticDisables(): void
    {
        [$exit, $report, $display] = $this->check(['--only-rule' => ['architecture.coverage-gap']]);
        self::assertSame(2, $exit, $display);
        self::assertContains('architecture.unreachable-layer', array_column($report['violations'], 'rule'));
        [$exit, $report, $display] = $this->check([
            '--only-rule' => ['architecture.coverage-gap'],
            '--disable-rule' => ['architecture.unreachable-layer', 'architecture.potential-shadow', 'architecture.empty-template', 'architecture.pending-layer-matched'],
        ]);
        self::assertSame(2, $exit, $display);
        self::assertSame(['architecture.coverage-gap'], array_column($report['violations'], 'rule'));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{int, array<string, mixed>, string}
     */
    private function check(array $arguments): array
    {
        $before = getcwd();
        self::assertNotFalse($before);
        try {
            chdir($this->directory);
            $command = (new ContainerFactory())->create()->get(CheckCommand::class);
            self::assertInstanceOf(CheckCommand::class, $command);
            $tester = new CommandTester($command);
            $exit = $tester->execute([
                'paths' => [$this->directory . '/src'],
                '--config' => $this->directory . '/qmx.yaml',
                '--format' => 'json', '--workers' => 0, '--no-cache' => true,
                '--no-progress' => true, '--fail-on' => 'none', ...$arguments,
            ]);
            $display = $tester->getDisplay();
            $start = strpos($display, '{');
            self::assertNotFalse($start, $display);
            return [$exit, json_decode(substr($display, $start), true, 512, \JSON_THROW_ON_ERROR), $display];
        } finally {
            chdir($before);
        }
    }
}
