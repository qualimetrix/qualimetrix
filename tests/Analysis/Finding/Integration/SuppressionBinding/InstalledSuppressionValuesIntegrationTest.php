<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration\SuppressionBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A suppression value that only a file Composer installed wrote is not judged,
 * and the same value written by the project is, with its writers named.
 *
 * Run through the real command: the writers are read from the resolved
 * document, and a carrier built by hand would show none of the places where
 * they could be lost on the way to the finding.
 */
#[CoversClass(UnboundSuppressionAudit::class)]
final class InstalledSuppressionValuesIntegrationTest extends TestCase
{
    private const string VENDOR = 'configuration file "vendor/acme/std/base.yaml" (imported by configuration file "qmx.yaml")';

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-installed-suppression-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        mkdir($this->fixture . '/vendor/acme/std', 0o755, true);
        mkdir($this->fixture . '/config', 0o755, true);
        file_put_contents($this->fixture . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR));
        file_put_contents($this->fixture . '/src/Service.php', "<?php\n\nnamespace Sample;\n\nclass Service\n{\n    public function value(): int\n    {\n        return 1;\n    }\n}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->fixture));
    }

    #[Test]
    public function itDoesNotJudgeGlobalValuesOnlyAnInstalledFileWrote(): void
    {
        $this->put('vendor/acme/std/base.yaml', "suppress_paths: [{subtree: src/Gone}]\nsuppress_namespaces: [{exact: Sample\\Gone}]\n");

        $tester = $this->check("imports: [vendor/acme/std/base.yaml]\n");
        $scope = $this->projectScope($tester);

        self::assertSame([], $this->onChannel($tester, UnboundSuppressionOptions::UNMATCHED_PATH));
        self::assertSame([], $this->onChannel($tester, UnboundSuppressionOptions::UNMATCHED_NAMESPACE));
        self::assertContains(['channel' => UnboundSuppressionOptions::UNMATCHED_PATH, 'option' => 'suppress_paths', 'pattern' => 'subtree:src/Gone'], $scope['unjudgedValues'] ?? []);
        self::assertContains(['channel' => UnboundSuppressionOptions::UNMATCHED_NAMESPACE, 'option' => 'suppress_namespaces', 'pattern' => 'exact:Sample\\Gone'], $scope['unjudgedValues'] ?? []);
        $reasons = array_filter($scope['reasons'] ?? [], static fn(array $reason): bool => ($reason['kind'] ?? null) === 'installed-configuration');
        self::assertSame([self::VENDOR], array_values(array_column($reasons, 'source')));
    }

    #[Test]
    public function itJudgesTheSameValueWhereverTheProjectWroteIt(): void
    {
        $this->put('config/base.yaml', "suppress_paths: [{subtree: src/Gone}]\n");

        $cases = [
            'a project file' => [$this->check("imports: [config/base.yaml]\n"), 'configuration file "config/base.yaml" (imported by configuration file "qmx.yaml")'],
            'the root' => [$this->check("suppress_paths: [{subtree: src/Gone}]\n"), 'configuration file "qmx.yaml"'],
            'the command line' => [$this->check("cache:\n  enabled: false\n", ['--suppress-path' => ['subtree:src/Gone']]), 'option --suppress-path'],
        ];

        foreach ($cases as $label => [$tester, $writer]) {
            $findings = $this->onChannel($tester, UnboundSuppressionOptions::UNMATCHED_PATH);
            self::assertCount(1, $findings, $label);
            self::assertStringEndsWith(' Written by ' . $writer . '.', (string) $findings[0]['message'], $label);
            self::assertNotContains('installed-configuration', array_column($this->projectScope($tester)['reasons'] ?? [], 'kind'), $label);
        }
    }

    #[Test]
    public function itJudgesAValueAnInstalledFileAndTheProjectBothWrote(): void
    {
        $this->put('vendor/acme/std/base.yaml', "suppress_paths: [{subtree: src/Gone}]\n");

        $tester = $this->check("imports: [vendor/acme/std/base.yaml]\nsuppress_paths: [{subtree: src/Gone}]\n");

        $findings = $this->onChannel($tester, UnboundSuppressionOptions::UNMATCHED_PATH);
        self::assertCount(1, $findings);
        self::assertStringEndsWith(' Written by ' . self::VENDOR . ' and configuration file "qmx.yaml".', (string) $findings[0]['message']);
    }

    #[Test]
    public function itKeepsJudgingPerRuleValuesAnInstalledFileWrote(): void
    {
        $this->put('vendor/acme/std/base.yaml', <<<'YAML'
            rules:
              size.class-count:
                suppress_paths: [{subtree: src/Gone}]
                suppress_namespaces: [{exact: Sample\Gone}]
                suppress_namespace_channels:
                  size.class-count:namespace: [{exact: Sample\Other}]
            YAML);

        $tester = $this->check("imports: [vendor/acme/std/base.yaml]\n");

        $findings = $this->onChannel($tester, UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER);
        self::assertCount(3, $findings);
        foreach ($findings as $finding) {
            self::assertStringEndsWith(' Written by ' . self::VENDOR . '.', (string) $finding['message']);
        }
    }

    /** The exclude walk has its own outcome for an installed writer, and the report counts it as not judged. */
    #[Test]
    public function itReportsAnInstalledExcludeSelectorAsNotJudged(): void
    {
        $this->put('vendor/acme/std/base.yaml', "exclude: [{subtree: legacy}]\n");

        $scope = $this->projectScope($this->check("imports: [vendor/acme/std/base.yaml]\n"));

        self::assertContains(['channel' => 'discovery.unmatched-exclude', 'option' => 'exclude', 'pattern' => 'subtree:legacy'], $scope['unjudgedValues'] ?? []);
        self::assertContains('discovery.unmatched-exclude', $scope['unjudgedChannels'] ?? []);
    }

    private function put(string $path, string $contents): void
    {
        file_put_contents($this->fixture . '/' . $path, $contents);
    }

    /** @return list<array<string, mixed>> */
    private function onChannel(CommandTester $tester, string $channel): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $violations = $payload['violations'] ?? [];
        self::assertIsList($violations);

        return array_values(array_filter($violations, static fn(mixed $violation): bool => \is_array($violation) && ($violation['rule'] ?? null) === $channel));
    }

    /** @return array<string, mixed> */
    private function projectScope(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $scope = $payload['projectScope'] ?? null;
        self::assertIsArray($scope);

        return $scope;
    }

    /** @param array<string, mixed> $options */
    private function check(string $yaml, array $options = []): CommandTester
    {
        $this->put('qmx.yaml', $yaml);
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);

        $previous = (string) getcwd();
        chdir($this->fixture);
        try {
            $tester->execute(['paths' => ['src'], '--workers' => '0', '--format' => 'json', '--fail-on' => 'none', ...$options], ['capture_stderr_separately' => true]);
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}
