<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `architecture.unreachable-layer` and `architecture.empty-template` say that
 * no class in the project matches a declaration. A run over one directory
 * cannot say that about code it did not analyse, and both channels fail the
 * run, so on such a run they must stay silent — while a whole-project run over
 * the same tree still reports a layer or template that matches nothing.
 */
#[CoversClass(LayerDeclarationValidator::class)]
final class NarrowedRunDeclarationVerdictIntegrationTest extends TestCase
{
    private const string CONFIG = <<<'YAML'
        architecture:
          layers:
            - name: 'domain-{module}'
              patterns: ['Sample\Module\{module}\Domain\**']
            - name: web
              patterns: ['Sample\Web\**']
            - name: rest
              patterns: ['Sample\**']
          allow:
            web: [rest]
          coverage-gap: ignore
        YAML;

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-narrowed-declaration-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/Web', 0o755, true);
        mkdir($this->fixture . '/src/Module/Billing/Domain', 0o755, true);
        mkdir($this->fixture . '/src/Shared', 0o755, true);

        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );

        $this->writeClass('Web/Controller.php', 'Sample\\Web', 'Controller');
        $this->writeClass('Module/Billing/Domain/Invoice.php', 'Sample\\Module\\Billing\\Domain', 'Invoice');
        $this->writeClass('Shared/Clock.php', 'Sample\\Shared', 'Clock');
    }

    protected function tearDown(): void
    {
        foreach (['/src/Web/Controller.php', '/src/Module/Billing/Domain/Invoice.php', '/src/Shared/Clock.php', '/composer.json', '/qmx.yaml'] as $file) {
            @unlink($this->fixture . $file);
        }

        foreach (['/src/Web', '/src/Module/Billing/Domain', '/src/Module/Billing', '/src/Module', '/src/Shared', '/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /**
     * `rest` and the template own code outside `src/Web`, which this run did
     * not read; neither is empty.
     */
    #[Test]
    public function itDoesNotCallALayerOrTemplateEmptyOnARunNarrowedBelowTheAutoloadRoots(): void
    {
        $tester = $this->check(self::CONFIG, ['src/Web']);

        self::assertSame([], $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
        self::assertSame([], $this->findingsOn($tester, LayerDeclarationValidator::EMPTY_TEMPLATE_DIAGNOSTIC_NAME));
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
    }

    /**
     * On stderr the warning that the run is a slice says it; the declaration
     * verdicts are withheld on exactly the runs it names. The document says
     * it too — see the test below.
     */
    #[Test]
    public function itLeavesTheNarrowedRunsScopeWarningAsTheTrace(): void
    {
        $tester = $this->check(self::CONFIG, ['src/Web']);

        self::assertStringContainsString('Analyzed paths do not cover all autoload entries', $tester->getErrorOutput());
    }

    /**
     * The warning is stderr, which neither `-q` nor a machine format keeps;
     * the document itself says the run was narrowed and what it did not judge.
     */
    #[Test]
    public function itPublishesTheNarrowedScopeAndTheUnjudgedChannelsInTheDocument(): void
    {
        $scope = $this->projectScope($this->check(self::CONFIG, ['src/Web']));

        self::assertSame('narrowed', $scope['state'] ?? null);
        self::assertSame(['src'], $scope['uncoveredAutoloadTargets'] ?? null);
        self::assertIsList($scope['unjudgedChannels'] ?? null);
        self::assertContains(LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME, $scope['unjudgedChannels']);
        self::assertContains(LayerDeclarationValidator::EMPTY_TEMPLATE_DIAGNOSTIC_NAME, $scope['unjudgedChannels']);
    }

    /**
     * Without a manifest there is no project beyond the paths the user named,
     * so the paths are judged as the project: a typo in a layer is an error
     * again, and the document says the scope was taken from the paths.
     */
    #[Test]
    public function itJudgesAProjectWithoutAManifestAgainstTheAnalysedPaths(): void
    {
        unlink($this->fixture . '/composer.json');
        $yaml = str_replace("'Sample\Web\**'", "'Sample\Webb\**'", self::CONFIG);

        $tester = $this->check($yaml, ['src']);

        $unreachable = $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('Layer "web"', (string) ($unreachable[0]['message'] ?? ''));
        $scope = $this->projectScope($tester);
        self::assertSame('unknown', $scope['state'] ?? null);
        self::assertSame([], $scope['uncoveredAutoloadTargets'] ?? null);
        self::assertIsList($scope['unjudgedChannels'] ?? null);
        self::assertNotContains(LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME, $scope['unjudgedChannels']);
        self::assertNotContains(LayerDeclarationValidator::EMPTY_TEMPLATE_DIAGNOSTIC_NAME, $scope['unjudgedChannels']);
    }

    /** The other half of the pair: the same tree, run whole, with a real typo in each. */
    #[Test]
    public function itStillReportsAnEmptyLayerAndTemplateOnAWholeProjectRun(): void
    {
        $yaml = str_replace(
            ["'Sample\\Module\\{module}\\Domain\\**'", "'Sample\\Web\\**'"],
            ["'Sample\\Modul\\{module}\\Domain\\**'", "'Sample\\Webb\\**'"],
            self::CONFIG,
        );

        $tester = $this->check($yaml, ['src']);

        $unreachable = $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('Layer "web"', (string) ($unreachable[0]['message'] ?? ''));
        self::assertCount(1, $this->findingsOn($tester, LayerDeclarationValidator::EMPTY_TEMPLATE_DIAGNOSTIC_NAME));
    }

    /**
     * A shadow is drawn from classes the run did read, so a slice that shows
     * one still reports it.
     */
    #[Test]
    public function itStillReportsAShadowSeenInsideTheSlice(): void
    {
        $yaml = <<<'YAML'
            architecture:
              layers:
                - name: rest
                  patterns: ['Sample\**']
                - name: web
                  patterns: ['Sample\Web\**']
              coverage-gap: ignore
            YAML;

        $tester = $this->check($yaml, ['src/Web']);

        self::assertCount(1, $this->findingsOn($tester, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
    }

    /** @return array<mixed> */
    private function projectScope(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['projectScope'] ?? null, $tester->getDisplay() . $tester->getErrorOutput());

        return $payload['projectScope'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findingsOn(CommandTester $tester, string $channel): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('violations', $payload, $tester->getDisplay() . $tester->getErrorOutput());
        $violations = $payload['violations'];
        self::assertIsList($violations);

        $matched = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            if (($violation['rule'] ?? null) === $channel) {
                $matched[] = $violation;
            }
        }

        return $matched;
    }

    /**
     * @param list<string> $paths
     */
    private function check(string $yaml, array $paths): CommandTester
    {
        file_put_contents($this->fixture . '/qmx.yaml', $yaml . "\n");

        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);

        $tester = new CommandTester($command);

        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(
                [
                    'paths' => $paths,
                    '--workers' => '0',
                    '--format' => 'json',
                    '--fail-on' => 'none',
                    '--disable-rule' => ['coupling.*'],
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }

    private function writeClass(string $relativePath, string $namespace, string $class): void
    {
        file_put_contents(
            $this->fixture . '/src/' . $relativePath,
            \sprintf("<?php\n\nnamespace %s;\n\nclass %s\n{\n    public function run(): void {}\n}\n", $namespace, $class),
        );
    }
}
