<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Routes 13 and 18 (`m6-routes-merged.md`): an `ArchitecturePreparationException`
 * class no longer exists in this tree — {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage}
 * throws {@see \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal}
 * directly when the `architecture.max_expanded_layers` ceiling is exceeded
 * while expanding a template layer, and `CheckCommand` / `BaselineGenerateCommand`
 * catch that one carrier the same way every other configuration mistake is
 * caught. M6 recorded this as two distinct routes — exit 3 on `check`, exit 1
 * on `baseline:*` — because at measurement time the two commands each had
 * their own bespoke catch clause for the (then-separate) preparation
 * exception; both are exit 3 now, through the unified `ConfigurationRefusal`
 * clause, which is the round's own finding rather than a gap: the round
 * collapsed the ladder to "configuration input -> 3, internal defect -> 1",
 * and this is that collapse actually landing on the one route that used to
 * disagree between commands.
 *
 * The fixture reuses {@see \Qualimetrix\Tests\Analysis\Policy\Architecture\Integration\LayerTemplateExpansionIntegrationTest}'s
 * `TemplateSample` tree (3 modules) with the ceiling set to 1, which that
 * test already proves throws {@see \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal}
 * at the pipeline level; this file's job is only the CLI-boundary fact —
 * which exit code and which stream each command turns that throw into.
 */
#[CoversClass(CheckCommand::class)]
#[CoversClass(BaselineGenerateCommand::class)]
final class ArchitectureCeilingRefusalTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../../../Analysis/Policy/Architecture/Fixtures/TemplateSample';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-arch-ceiling-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $files = glob($this->tempDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function itRefusesAnExceededTemplateExpansionCeilingOnCheck(): void
    {
        $config = $this->writeCeilingConfig();

        $container = (new ContainerFactory())->create();
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [self::FIXTURE_PATH],
            '--config' => $config,
            '--format' => 'json',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode());
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertSame(3, $envelope['exit_code']);
        self::assertStringContainsString('architecture.max_expanded_layers ceiling of 1', (string) $envelope['error']);
    }

    /**
     * The route M6 recorded as exit 1 on stdout: this command's own ladder
     * has since collapsed onto the same `ConfigurationRefusal` clause as
     * `check`, so the live outcome is exit 3, not the historical 1.
     */
    #[Test]
    public function itRefusesAnExceededTemplateExpansionCeilingOnBaselineGenerate(): void
    {
        $config = $this->writeCeilingConfig();
        $baseline = $this->tempDir . '/baseline.json';

        $container = (new ContainerFactory())->create();
        $command = $container->get(BaselineGenerateCommand::class);
        self::assertInstanceOf(BaselineGenerateCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute([
            'baseline' => $baseline,
            'paths' => [self::FIXTURE_PATH],
            '--config' => $config,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString(
            'architecture.max_expanded_layers ceiling of 1',
            $tester->getErrorOutput(),
        );
        self::assertFalse(is_file($baseline), 'A refused run must not write a baseline file.');
    }

    /**
     * Ceiling = 1 against a 3-module fixture: {@see \Qualimetrix\Tests\Analysis\Policy\Architecture\Integration\LayerTemplateExpansionIntegrationTest::itFailsFastWhenTheCeilingIsBelowTheObservedCount()}
     * already proves this throws at the pipeline level; the config here is
     * the same shape as that test's `baseTemplateConfig()`, expressed as YAML
     * for the CLI boundary.
     */
    private function writeCeilingConfig(): string
    {
        $path = $this->tempDir . '/qmx.yaml';
        file_put_contents($path, <<<'YAML'
            architecture:
              layers:
                - name: shared
                  patterns: ['Fixtures\TemplateSample\Shared\**']
                - name: domain-{module}
                  patterns: ['Fixtures\TemplateSample\Module\{module}\Domain\**']
              allow:
                domain-*: ['shared']
                shared: []
              coverage-gap: ignore
              max_expanded_layers: 1
            disabled_rules: ['coupling.*', 'health.*', 'maintainability.*', 'complexity.*', 'code-smell.*', 'design.*', 'cohesion.*', 'security.*', 'duplication.*']
            YAML);

        return $path;
    }
}
