<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Exercises Architecture-owned warning semantics over normalized YAML contributions. */
#[CoversClass(ArchitecturePolicy::class)]
final class ArchitectureConfigurationWarningIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-architecture-warning-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    #[Test]
    public function wildcardSelfAllowWarningIsOwnedByArchitectureConfiguration(): void
    {
        file_put_contents($this->tempDir . '/qmx.yaml', <<<'YAML'
architecture:
  layers:
    - name: domain-orders
      patterns: ['App\Domain\Orders\**']
  allow:
    domain-*: ['domain-*']
YAML);

        $document = $this->createPipeline()->resolve(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->tempDir)),
        );
        self::assertCount(1, $document->contributions('architecture'));

        $policy = new ArchitecturePolicy();
        $resolved = $policy->resolve($document);
        $policy->replace($resolved);
        $warnings = $resolved->warnings();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('wildcard-self-allow', $warnings[0]->message);
        self::assertStringContainsString("'domain-*'", $warnings[0]->message);
    }

    #[Test]
    public function cleanConfigurationProducesNoArchitectureWarnings(): void
    {
        file_put_contents($this->tempDir . '/qmx.yaml', <<<'YAML'
architecture:
  layers:
    - name: controller
      patterns: ['App\Controller']
    - name: service
      patterns: ['App\Service']
  allow:
    controller: ['service']
YAML);

        $document = $this->createPipeline()->resolve(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->tempDir)),
        );
        self::assertCount(1, $document->contributions('architecture'));

        $policy = new ArchitecturePolicy();
        $resolved = $policy->resolve($document);
        $policy->replace($resolved);

        self::assertSame([], $resolved->warnings());
    }

    #[Test]
    public function itRejectsAForeignResolvedTokenBeforeChangingInstalledState(): void
    {
        $policy = new ArchitecturePolicy();
        $resolved = $policy->resolve($this->createPipeline()->resolve(
            new ConfigurationResolutionRequest(AbsolutePath::fromString($this->tempDir)),
        ));
        $policy->replace($resolved);
        $policy->prepare(self::createStub(DependencyGraphInterface::class), []);
        $installed = $policy->getPreparedConfiguration();
        $warnings = $resolved->warnings();

        $foreign = new class implements ResolvedArchitecturePolicyInterface {
            public function warnings(): array
            {
                return [];
            }
        };

        try {
            $policy->replace($foreign);
            self::fail('A foreign Architecture resolution token must be rejected.');
        } catch (LogicException $exception) {
            self::assertSame(
                'ArchitecturePolicy accepts only a policy resolved by its Architecture factory.',
                $exception->getMessage(),
            );
        }

        self::assertSame($installed, $policy->getPreparedConfiguration());
        self::assertSame($warnings, $resolved->warnings());
    }

    private function createPipeline(): ConfigurationPipeline
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage(new ComposerReader()));
        $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
        $pipeline->addStage(new CliStage());

        return $pipeline;
    }

    private function removeTempDir(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $fileInfo) {
            $delete = $fileInfo->isDir() ? 'rmdir' : 'unlink';
            $delete($fileInfo->getRealPath());
        }
        rmdir($this->tempDir);
    }
}
