<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\UnmatchedTypeDiagnostic;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(UnmatchedTypeDiagnostic::class)]
final class UnmatchedTypeIntegrationTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-unmatched-layer-type-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/One', 0o755, true);
        mkdir($this->fixture . '/src/Two', 0o755, true);
        file_put_contents($this->fixture . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR));
        file_put_contents($this->fixture . '/src/One/Service.php', '<?php namespace Sample\One; final class Service {}');
        file_put_contents($this->fixture . '/src/Two/Service.php', '<?php namespace Sample\Two; final class Service {}');
    }

    protected function tearDown(): void
    {
        foreach (['/src/One/Service.php', '/src/Two/Service.php', '/composer.json', '/qmx.yaml'] as $path) {
            @unlink($this->fixture . $path);
        }
        foreach (['/src/One', '/src/Two', '/src', ''] as $path) {
            @rmdir($this->fixture . $path);
        }
    }

    #[Test]
    public function itPublishesSeparatePositiveAndExcludePositionsFromTheConfiguration(): void
    {
        $findings = $this->check(<<<'YAML'
            architecture:
              layers:
                - name: typed
                  implements: ['Sample\One\Service', 'Vendor\Missing']
                  exclude:
                    extends: ['Vendor\Missing']
                - name: rest
                  patterns: ['Sample\**']
              coverage-gap: ignore
            YAML);

        self::assertCount(2, $findings);
        self::assertStringContainsString('architecture.layers[0].implements[1]', $findings[0]['message']);
        self::assertStringContainsString('architecture.layers[0].exclude.extends[0]', $findings[1]['message']);
        foreach ($findings as $finding) {
            self::assertStringContainsString('qmx.yaml', $finding['message']);
            self::assertStringContainsString('Vendor\Missing', $finding['message']);
            self::assertSame('warning', $finding['severity']);
        }
    }

    #[Test]
    public function itReportsOneAuthoredExcludeTypeAcrossExpandedTemplateInstances(): void
    {
        $findings = $this->check(<<<'YAML'
            architecture:
              layers:
                - name: 'module-{module}'
                  patterns: ['Sample\{module}\**']
                  exclude:
                    extends: ['Vendor\Missing']
              coverage-gap: ignore
            YAML);

        self::assertCount(1, $findings);
        self::assertStringContainsString('architecture.layers[0].exclude.extends[0]', $findings[0]['message']);
    }

    /** @return list<array{message: string, severity: string}> */
    private function check(string $yaml): array
    {
        file_put_contents($this->fixture . '/qmx.yaml', $yaml . "\n");
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
        self::assertIsList($payload['violations']);
        $findings = [];
        foreach ($payload['violations'] as $finding) {
            self::assertIsArray($finding);
            if (($finding['rule'] ?? null) !== 'architecture.unmatched-type') {
                continue;
            }
            self::assertArrayHasKey('message', $finding);
            self::assertArrayHasKey('severity', $finding);
            self::assertIsString($finding['message']);
            self::assertIsString($finding['severity']);
            $findings[] = ['message' => $finding['message'], 'severity' => $finding['severity']];
        }

        return $findings;
    }
}
