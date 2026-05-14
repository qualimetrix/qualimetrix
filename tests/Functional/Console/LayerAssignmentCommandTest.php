<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Discovery\ComposerReader;
use Qualimetrix\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Configuration\Pipeline\Stage\ComposerDiscoveryStage;
use Qualimetrix\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional tests for {@see LayerAssignmentCommand}.
 *
 * Tests cover the exit-code contract documented in `docs/internal/plans/architecture-rules-followup.md`
 * (Step 6): SUCCESS for any informational outcome (including "no layer matches"),
 * INVALID for malformed input, FAILURE for config-load problems.
 *
 * The "command-vs-runtime agreement" regression test pins the key invariant:
 * the command MUST report the same layer assignment the runtime
 * ({@see LayerRegistry::resolveLayer()}) would resolve. Since the command
 * delegates to {@see LayerRegistry::resolveAll()} (whose first entry is the
 * same value `resolveLayer()` returns), they agree by construction — this
 * test guards against future refactors that would re-introduce a parallel
 * matching path inside the command.
 */
#[CoversClass(LayerAssignmentCommand::class)]
final class LayerAssignmentCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-debug-layer-test-' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function classMatchingSingleLayer_reportsUniqueAssignment(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
            ['service', ['App\\Service\\**']],
        ]);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\UserService',
            '--config' => $configPath,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $output = $tester->getDisplay();
        self::assertStringContainsString('Class: App\\Service\\UserService', $output);
        self::assertStringContainsString('Assigned to: service', $output);
        self::assertStringContainsString('Matched by: pattern "App\\Service\\**"', $output);
        self::assertStringContainsString('Would also match (in declaration order):', $output);
        self::assertStringContainsString('(none', $output);
        self::assertStringNotContainsString('Diagnostic hint:', $output);
    }

    #[Test]
    public function classMatchingMultipleLayers_reportsAssignmentAndShadowedLayers(): void
    {
        // any-foo declared first → it captures App\Service\Foo before service has a chance.
        $configPath = $this->writeConfig([
            ['any-foo', ['App\\**\\Foo']],
            ['service', ['App\\Service\\**']],
        ]);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\Foo',
            '--config' => $configPath,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $output = $tester->getDisplay();
        self::assertStringContainsString('Class: App\\Service\\Foo', $output);
        self::assertStringContainsString('Assigned to: any-foo', $output);
        self::assertStringContainsString('Matched by: pattern "App\\**\\Foo"', $output);
        self::assertStringContainsString('Would also match (in declaration order):', $output);
        self::assertStringContainsString('service', $output);
        self::assertStringContainsString("matched by: 'pattern \"App\\Service\\**\"'", $output);
        self::assertStringContainsString('Diagnostic hint:', $output);
        self::assertStringContainsString("would have matched 'service'", $output);
        self::assertStringContainsString('architecture.potential-shadow', $output);
    }

    #[Test]
    public function classMatchingNoLayer_reportsUnclassifiedAndSuggestsCatchAll(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
            ['service', ['App\\Service\\**']],
        ]);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'Other\\Place\\Thing',
            '--config' => $configPath,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $output = $tester->getDisplay();
        self::assertStringContainsString('Class: Other\\Place\\Thing', $output);
        self::assertStringContainsString('Assigned to: (no layer)', $output);
        self::assertStringContainsString("'**'", $output);
        self::assertStringContainsString('catch-all', $output);
    }

    #[Test]
    public function leadingBackslash_isNormalised(): void
    {
        $configPath = $this->writeConfig([
            ['service', ['App\\Service\\**']],
        ]);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => '\\App\\Service\\Foo',
            '--config' => $configPath,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $output = $tester->getDisplay();
        // Header reports the normalised form (no leading backslash).
        self::assertStringContainsString('Class: App\\Service\\Foo', $output);
        self::assertStringNotContainsString('Class: \\App', $output);
        self::assertStringContainsString('Assigned to: service', $output);
    }

    #[Test]
    public function emptyFqn_exitsInvalid(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['fqn' => '']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('must not be empty', $tester->getDisplay());
    }

    #[Test]
    public function whitespaceOnlyFqn_exitsInvalid(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['fqn' => "   \t  "]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('must not be empty', $tester->getDisplay());
    }

    #[Test]
    public function fqnWithEmbeddedSpace_exitsInvalid(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['fqn' => 'App\\Service Foo']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('whitespace', $tester->getDisplay());
    }

    #[Test]
    public function fqnWithInvalidIdentifierCharacter_exitsInvalid(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['fqn' => 'App\\Service-Foo']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('not a valid PHP', $tester->getDisplay());
    }

    #[Test]
    public function nonExistentConfigPath_exitsFailure(): void
    {
        $missing = $this->tempDir . '/does-not-exist.yaml';
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\Foo',
            '--config' => $missing,
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Configuration error', $tester->getDisplay());
        self::assertStringContainsString($missing, $tester->getDisplay());
    }

    #[Test]
    public function globalNamespaceClass_isHandled(): void
    {
        $configPath = $this->writeConfig([
            ['service', ['App\\Service\\**']],
        ]);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'GlobalClass',
            '--config' => $configPath,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $output = $tester->getDisplay();
        self::assertStringContainsString('Class: GlobalClass', $output);
        self::assertStringContainsString('Assigned to: (no layer)', $output);
    }

    #[Test]
    public function noLayersDeclared_reportsAbsenceWithoutCatchAllSuggestion(): void
    {
        // Config file exists but has no architecture section.
        $configPath = $this->tempDir . '/qmx-empty.yaml';
        file_put_contents($configPath, "paths: ['.']\n");

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'Anything\\At\\All',
            '--config' => $configPath,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $output = $tester->getDisplay();
        self::assertStringContainsString('Assigned to: (no layer)', $output);
        self::assertStringContainsString('no layers are declared', $output);
    }

    #[Test]
    public function commandIsDiscoverableInApplication(): void
    {
        // Smoke test: the command can be registered on an Application by name
        // and surfaces under `list` output. This guards against accidental
        // removal of the #[AsCommand] attribute or the command-loader entry.
        $application = new Application();
        $application->addCommand(new LayerAssignmentCommand($this->buildPipeline()));

        self::assertTrue($application->has('debug:layer-assignment'));
        $command = $application->get('debug:layer-assignment');
        self::assertSame('debug:layer-assignment', $command->getName());
        self::assertNotSame('', $command->getDescription());
    }

    /**
     * Regression test for the command-vs-runtime agreement invariant.
     *
     * Builds a {@see LayerRegistry} from the same overlapping configuration
     * the command will load, asks the registry directly what layer
     * `App\Service\Foo` resolves to ({@see LayerRegistry::resolveLayer()} —
     * the value the runtime {@see \Qualimetrix\Rules\Architecture\LayerViolationRule}
     * sees on every dependency edge), runs the command against the same
     * config, and asserts both agree.
     *
     * The command MUST report the layer the rule would observe at runtime —
     * any drift would make `debug:layer-assignment` actively misleading.
     * Because the command delegates to `LayerRegistry::resolveAll()` and
     * `resolveLayer()` reads the first entry from the same shared cache,
     * they cannot disagree without a regression in `LayerRegistry` itself.
     */
    #[Test]
    public function commandAgreesWithRuntimeOnOverlap(): void
    {
        // Two overlapping layers — order matters. Runtime assignment is `any-foo`.
        $layers = [
            new LayerDefinition('any-foo', new MembershipSpec(['App\\**\\Foo'])),
            new LayerDefinition('service', new MembershipSpec(['App\\Service\\**'])),
        ];
        $registry = new LayerRegistry($layers);

        $fqn = 'App\\Service\\Foo';
        $runtimeAssignment = $registry->resolveLayer(SymbolPath::fromClassFqn($fqn));

        // Make sure the runtime invariant we are pinning is itself live —
        // both layers must match, runtime picks the first one in order.
        $allMatches = $registry->resolveAll(SymbolPath::fromClassFqn($fqn));
        self::assertCount(2, $allMatches);
        self::assertSame('any-foo', $runtimeAssignment);
        self::assertSame('any-foo', $allMatches[0]->layerName);
        self::assertSame('service', $allMatches[1]->layerName);

        // Now drive the same config through the command and compare.
        $configPath = $this->writeConfig([
            ['any-foo', ['App\\**\\Foo']],
            ['service', ['App\\Service\\**']],
        ]);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => $fqn,
            '--config' => $configPath,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $output = $tester->getDisplay();
        self::assertStringContainsString(
            'Assigned to: ' . $runtimeAssignment,
            $output,
            'Command-reported assignment must match runtime LayerRegistry::resolveLayer().',
        );
    }

    private function newTester(): CommandTester
    {
        $command = new LayerAssignmentCommand($this->buildPipeline());

        return new CommandTester($command);
    }

    private function buildPipeline(): ConfigurationPipeline
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new DefaultsStage());
        $pipeline->addStage(new ComposerDiscoveryStage(new ComposerReader()));
        $pipeline->addStage(new ConfigFileStage(new YamlConfigLoader()));
        $pipeline->addStage(new CliStage());

        return $pipeline;
    }

    /**
     * Writes a qmx.yaml with the given ordered list of layers to the temp
     * directory and returns its absolute path.
     *
     * Uses the post-Step-0 schema: `architecture.layers` is an ordered list
     * of `{name, patterns}` entries (declaration order matters), with a
     * matching `allow: { name: [] }` map and `coverage: ignore`.
     *
     * @param list<array{0: string, 1: list<string>}> $layers Ordered list of
     *                                                        `[layerName, [patterns…]]` tuples.
     */
    private function writeConfig(array $layers): string
    {
        $layerYaml = '';
        $allowYaml = '';
        foreach ($layers as [$name, $patterns]) {
            $patternList = implode(', ', array_map(static fn(string $p): string => "'{$p}'", $patterns));
            $layerYaml .= \sprintf("    - name: %s\n      patterns: [%s]\n", $name, $patternList);
            $allowYaml .= \sprintf("    %s: []\n", $name);
        }

        $yaml = "architecture:\n  layers:\n{$layerYaml}  allow:\n{$allowYaml}  coverage: ignore\n";

        $path = $this->tempDir . '/qmx-' . uniqid() . '.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
