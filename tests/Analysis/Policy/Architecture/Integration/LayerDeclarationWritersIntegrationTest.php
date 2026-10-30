<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The layers a finding is about are written by one source per run — the last
 * configuration layer that writes `architecture.layers` — and the three
 * channels that judge the declaration name it. Run through the real command,
 * because a carrier that loses the writers on the way (the factory, template
 * expansion) leaves a finding that is still correct and only silent.
 */
#[CoversClass(ArchitectureConfigurationFactory::class)]
final class LayerDeclarationWritersIntegrationTest extends TestCase
{
    private const string LAYERS = <<<'YAML'
        architecture:
          layers:
            - name: emptied
              patterns: ['Sample\Controller\**']
              exclude:
                patterns: ['Sample\Controller\**']
            - name: controller
              patterns: ['Sample\Controller\**']
              exclude:
                patterns: ['Sample\Controller\NoSuch\**']
            - name: nothing
              patterns: ['Sample\NoSubject\**']
            - name: 'module-{module}'
              patterns: ['Sample\NoModule\{module}\**']
          coverage-gap: ignore
        YAML;

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-layer-writers-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/Controller', 0o755, true);
        file_put_contents($this->fixture . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR));
        file_put_contents($this->fixture . '/src/Controller/UserController.php', '<?php namespace Sample\Controller; class UserController {}');
    }

    protected function tearDown(): void
    {
        foreach (['/src/Controller/UserController.php', '/composer.json', '/qmx.yaml', '/layers.yaml'] as $path) {
            @unlink($this->fixture . $path);
        }
        foreach (['/src/Controller', '/src', ''] as $path) {
            @rmdir($this->fixture . $path);
        }
    }

    #[Test]
    public function itNamesTheRootFileOnEachOfTheThreeChannels(): void
    {
        $findings = $this->check(['qmx.yaml' => self::LAYERS]);

        foreach ($findings as $finding) {
            self::assertStringEndsWith(' Written by configuration file "qmx.yaml".', $finding['message']);
        }
        self::assertCount(1, $this->on($findings, ArchitectureChannels::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME));
        self::assertCount(1, $this->on($findings, ArchitectureChannels::EMPTY_TEMPLATE_DIAGNOSTIC_NAME));
        self::assertCount(2, $this->on($findings, ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
    }

    #[Test]
    public function itDiffersByTheSentenceAloneBetweenARootAndAnImportedLayersList(): void
    {
        $root = $this->check(['qmx.yaml' => self::LAYERS]);
        $imported = $this->check(['qmx.yaml' => "imports: [layers.yaml]\n", 'layers.yaml' => self::LAYERS]);

        self::assertCount(\count($root), $imported);
        foreach ($root as $index => $finding) {
            self::assertSame($finding['rule'], $imported[$index]['rule']);
            self::assertStringEndsWith(' Written by configuration file "layers.yaml" (imported by configuration file "qmx.yaml").', $imported[$index]['message']);
            self::assertSame(
                substr($finding['message'], 0, -\strlen(' Written by configuration file "qmx.yaml".')),
                substr($imported[$index]['message'], 0, -\strlen(' Written by configuration file "layers.yaml" (imported by configuration file "qmx.yaml").')),
            );
            self::assertSame($finding['occurrence'], $imported[$index]['occurrence'], $finding['rule']);
        }

        $exclude = $this->on($imported, ArchitectureChannels::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME);
        self::assertIsString($exclude[0]['occurrence']);
        foreach ([ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME, ArchitectureChannels::EMPTY_TEMPLATE_DIAGNOSTIC_NAME] as $channel) {
            foreach ($this->on($imported, $channel) as $finding) {
                self::assertNull($finding['occurrence']);
            }
        }
    }

    #[Test]
    public function itNamesTheSourceOnTheMessageOfALayerWhoseClassesItsOwnExcludeRemoved(): void
    {
        $findings = $this->on($this->check(['qmx.yaml' => self::LAYERS]), ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);

        $emptied = array_values(array_filter($findings, static fn(array $finding): bool => str_contains($finding['message'], '"emptied"')));
        self::assertCount(1, $emptied);
        self::assertStringContainsString('removed by its own "exclude"', $emptied[0]['message']);
        self::assertStringEndsWith(' Written by configuration file "qmx.yaml".', $emptied[0]['message']);
    }

    /**
     * @param array<string, string> $files
     *
     * @return list<array{rule: string, message: string, occurrence: ?string}>
     */
    private function check(array $files): array
    {
        foreach ($files as $name => $content) {
            file_put_contents($this->fixture . '/' . $name, $content . "\n");
        }

        $previous = (string) getcwd();
        chdir($this->fixture);
        try {
            $command = (new ContainerFactory())->create()->get(CheckCommand::class);
            self::assertInstanceOf(CheckCommand::class, $command);
            $tester = new CommandTester($command);
            $tester->execute(['paths' => ['src'], '--workers' => '0', '--no-cache' => true, '--format' => 'json', '--fail-on' => 'none'], ['capture_stderr_separately' => true]);
        } finally {
            chdir($previous);
        }

        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('violations', $payload, $tester->getDisplay() . $tester->getErrorOutput());
        $wanted = [
            ArchitectureChannels::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME,
            ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
            ArchitectureChannels::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
        ];
        $findings = [];
        foreach ($payload['violations'] as $violation) {
            self::assertIsArray($violation);
            if (!\in_array($violation['rule'] ?? null, $wanted, true)) {
                continue;
            }
            $findings[] = ['rule' => $violation['rule'], 'message' => (string) $violation['message'], 'occurrence' => isset($violation['occurrence']) ? (string) $violation['occurrence'] : null];
        }
        usort($findings, static fn(array $a, array $b): int => [$a['rule'], $a['message']] <=> [$b['rule'], $b['message']]);

        return $findings;
    }

    /**
     * @param list<array{rule: string, message: string, occurrence: ?string}> $findings
     *
     * @return list<array{rule: string, message: string, occurrence: ?string}>
     */
    private function on(array $findings, string $channel): array
    {
        return array_values(array_filter($findings, static fn(array $finding): bool => $finding['rule'] === $channel));
    }
}
