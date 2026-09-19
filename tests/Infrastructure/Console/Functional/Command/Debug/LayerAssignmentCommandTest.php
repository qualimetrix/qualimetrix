<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\LayerAssignmentResolver;
use Qualimetrix\Infrastructure\Console\Refusal\ConsoleExitCode;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional tests for {@see LayerAssignmentCommand}.
 *
 * Tests cover the exit-code contract: SUCCESS for any informational outcome
 * about an analysed class
 * (including "no layer matches"), `ConsoleExitCode::Refusal` (3) for
 * malformed input, an FQN naming no analysed class, or a configuration-load
 * error recognised as the user's to fix, FAILURE for anything else the
 * configuration step throws.
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
#[CoversClass(LayerAssignmentResolver::class)]
final class LayerAssignmentCommandTest extends TestCase
{
    private string $tempDir;

    private string $originalMemoryLimit = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-debug-layer-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
        $current = \ini_get('memory_limit');
        $this->originalMemoryLimit = $current !== false ? $current : '-1';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        // Restore the original memory_limit so tests that exercise the
        // RuntimeConfigurator hook don't leak ini state into subsequent tests.
        ini_set('memory_limit', $this->originalMemoryLimit);
    }

    #[Test]
    public function itReportsAUniqueAssignmentForAClassMatchingOneLayer(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
            ['service', ['App\\Service\\**']],
        ]);
        $this->declareClasses(['App\\Service\\UserService']);

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
    public function itReportsTheAssignmentAndShadowedLayersForAClassMatchingMultipleLayers(): void
    {
        // any-foo declared first → it captures App\Service\Foo before service has a chance.
        $configPath = $this->writeConfig([
            ['any-foo', ['App\\**\\Foo']],
            ['service', ['App\\Service\\**']],
        ]);
        $this->declareClasses(['App\\Service\\Foo']);

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
    public function itReportsAClassMatchingNoLayerAsUnclassifiedAndSuggestsACatchAllPattern(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
            ['service', ['App\\Service\\**']],
        ]);
        $this->declareClasses(['Other\\Place\\Thing']);

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

    /**
     * The pair the cure is about: two facts that used to share one form.
     *
     * `Other\Place\Thing` was analysed and matched no layer — an informational
     * answer, exit 0, and the report is the one it always was.
     * `Zzz\Nope\Missing` was never analysed — nothing can be said about its
     * layer, so it is refused with exit 3. Both run against one configuration
     * inside one test, because the defect was that the two were
     * indistinguishable, not that either one was wrong on its own.
     */
    #[Test]
    public function itRefusesAnUnanalysedFqnWhileStillReportingNoLayerForAnAnalysedOne(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
        ]);
        $this->declareClasses(['Other\\Place\\Thing']);

        $analysed = $this->newTester();
        $analysedExit = $analysed->execute([
            'fqn' => 'Other\\Place\\Thing',
            '--config' => $configPath,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $analysedExit);
        self::assertStringContainsString('Assigned to: (no layer)', $analysed->getDisplay());
        self::assertSame('', $analysed->getErrorOutput());

        $unanalysed = $this->newTester();
        $unanalysedExit = $unanalysed->execute([
            'fqn' => 'Zzz\\Nope\\Missing',
            '--config' => $configPath,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(ConsoleExitCode::Refusal->value, $unanalysedExit);
        self::assertSame('', $unanalysed->getDisplay());
        self::assertStringContainsString('Configuration error:', $unanalysed->getErrorOutput());
        self::assertStringContainsString('Zzz\\Nope\\Missing', $unanalysed->getErrorOutput());
        self::assertStringContainsString('is not among the', $unanalysed->getErrorOutput());
        // The refusal must not be reported as the informational answer it
        // replaces — that spelling is exactly what made the two facts one.
        self::assertStringNotContainsString('(no layer)', $unanalysed->getErrorOutput());
    }

    /**
     * Same mechanism as {@see self::itKeepsTheEmptyFqnRefusalVisibleUnderQuiet()},
     * for the new unanalysed-class route: it is raised deep in the resolver
     * rather than at the command's front door, so its survival under `-q` is
     * worth its own probe.
     */
    #[Test]
    public function itKeepsTheUnanalysedFqnRefusalVisibleUnderQuiet(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
        ]);
        $this->declareClasses(['Other\\Place\\Thing']);

        $tester = $this->newTester();
        $exit = $tester->execute(
            ['fqn' => 'Zzz\\Nope\\Missing', '--config' => $configPath],
            ['verbosity' => OutputInterface::VERBOSITY_QUIET, 'capture_stderr_separately' => true],
        );

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('is not among the', $tester->getErrorOutput());
    }

    #[Test]
    public function itReturnsAnErrorEnvelopeForAnUnanalysedFqnInJsonFormat(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
        ]);
        $this->declareClasses(['Other\\Place\\Thing']);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'Zzz\\Nope\\Missing',
            '--config' => $configPath,
            '--format' => 'json',
        ]);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $decoded);
        self::assertStringContainsString('Zzz\\Nope\\Missing', $decoded['error']);
        self::assertSame(ConsoleExitCode::Refusal->value, $decoded['exit_code']);
        // An agent reading `--format=json` must not find an assignment
        // document for a class the run never analysed.
        self::assertArrayNotHasKey('assigned', $decoded);
    }

    /**
     * A leading backslash is normalised before anything else, so the refusal
     * is no more sensitive to the spelling than the report was: the same FQN
     * written both ways gets the same verdict and the same normalised name in
     * the message.
     */
    #[Test]
    public function itRefusesAnUnanalysedFqnIdenticallyWithAndWithoutALeadingBackslash(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
        ]);
        $this->declareClasses(['Other\\Place\\Thing']);

        $bare = $this->newTester();
        $bareExit = $bare->execute(
            ['fqn' => 'Zzz\\Nope\\Missing', '--config' => $configPath],
            ['capture_stderr_separately' => true],
        );
        $prefixed = $this->newTester();
        $prefixedExit = $prefixed->execute(
            ['fqn' => '\\Zzz\\Nope\\Missing', '--config' => $configPath],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(ConsoleExitCode::Refusal->value, $bareExit);
        self::assertSame($bareExit, $prefixedExit);
        self::assertSame($bare->getErrorOutput(), $prefixed->getErrorOutput());
        self::assertStringNotContainsString('"\\Zzz', $prefixed->getErrorOutput());
    }

    /**
     * Membership folds ASCII case the way PHP folds class names, so a real
     * class spelled in another case is still found — and keeps the answer it
     * had before the refusal existed. Layer *matching* stays case-sensitive:
     * the lower-cased spelling matches no pattern, so the answer is the
     * informational `(no layer)`, not a refusal and not an assignment.
     */
    #[Test]
    public function itFindsAnAnalysedClassSpelledInAnotherCase(): void
    {
        $configPath = $this->writeConfig([
            ['service', ['App\\Service\\**']],
        ]);
        $this->declareClasses(['App\\Service\\UserService']);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'app\\service\\userservice',
            '--config' => $configPath,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Assigned to: (no layer)', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    /**
     * The analysed set is class-level in the metric sense — interfaces, traits
     * and enums included. Pinned because narrowing it to `class` declarations
     * would turn every interface, trait and enum the user asks about into a
     * refusal.
     */
    #[Test]
    public function itAcceptsAnAnalysedInterfaceTraitAndEnum(): void
    {
        $configPath = $this->writeConfig([
            ['service', ['App\\Service\\**']],
        ]);
        file_put_contents(
            $this->sourcePath() . '/Kinds.php',
            "<?php\n\nnamespace App\\Service;\n\ninterface Contract {}\ntrait Helper {}\nenum Mode { case On; }\n",
        );

        foreach (['App\\Service\\Contract', 'App\\Service\\Helper', 'App\\Service\\Mode'] as $fqn) {
            $tester = $this->newTester();
            $exit = $tester->execute([
                'fqn' => $fqn,
                '--config' => $configPath,
            ], ['capture_stderr_separately' => true]);

            self::assertSame(Command::SUCCESS, $exit, $tester->getErrorOutput());
            self::assertStringContainsString('Assigned to: service', $tester->getDisplay());
        }
    }

    #[Test]
    public function itNormalisesALeadingBackslashInTheFqnArgument(): void
    {
        $configPath = $this->writeConfig([
            ['service', ['App\\Service\\**']],
        ]);
        $this->declareClasses(['App\\Service\\Foo']);

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
    public function itExitsInvalidForAnEmptyFqn(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['fqn' => '']);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('must not be empty', $tester->getDisplay());
    }

    /**
     * The empty-FQN check used to write through the command's own
     * `writeln()`/`reportError()`
     * at the default verbosity, which `-q` (`VERBOSITY_QUIET`) suppresses —
     * exit 3, zero bytes on both streams. Now routed through
     * `RefusalPresenter::fallbackRefusal()`, whose write survives `-q` like
     * every other refusal in the tool.
     */
    #[Test]
    public function itKeepsTheEmptyFqnRefusalVisibleUnderQuiet(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(
            ['fqn' => ''],
            ['verbosity' => OutputInterface::VERBOSITY_QUIET, 'capture_stderr_separately' => true],
        );

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('must not be empty', $tester->getErrorOutput());
    }

    /**
     * Same mechanism as {@see self::itKeepsTheEmptyFqnRefusalVisibleUnderQuiet()},
     * for the unknown-`--format` route.
     */
    #[Test]
    public function itKeepsTheUnknownFormatRefusalVisibleUnderQuiet(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(
            ['fqn' => 'App\\Service\\Foo', '--format' => 'yaml'],
            ['verbosity' => OutputInterface::VERBOSITY_QUIET, 'capture_stderr_separately' => true],
        );

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('Unknown format', $tester->getErrorOutput());
    }

    #[Test]
    public function itExitsInvalidForAWhitespaceOnlyFqn(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['fqn' => "   \t  "]);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('must not be empty', $tester->getDisplay());
    }

    #[Test]
    public function itExitsInvalidForAnFqnWithAnEmbeddedSpace(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['fqn' => 'App\\Service Foo']);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('whitespace', $tester->getDisplay());
    }

    #[Test]
    public function itExitsInvalidForAnFqnWithAnInvalidIdentifierCharacter(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute(['fqn' => 'App\\Service-Foo']);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('not a valid PHP', $tester->getDisplay());
    }

    #[Test]
    public function itExitsFailureForANonExistentConfigPath(): void
    {
        $missing = $this->tempDir . '/does-not-exist.yaml';
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\Foo',
            '--config' => $missing,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        // This route is the `ConfigurationRefusal` carrier caught by the
        // command's own clause and handed to `RefusalPresenter::refusal()`,
        // which writes the human form to stderr. The shared presenter also
        // handles the early-return routes above, so stdout is empty here for
        // the same reason it is empty on those.
        self::assertSame('', $tester->getDisplay());
        // Pin the presenter's exact frame here: this command has its own
        // refusal route and must not rely on coverage from another command.
        self::assertStringContainsString('Configuration error:', $tester->getErrorOutput());
        self::assertStringContainsString($missing, $tester->getErrorOutput());
    }

    #[Test]
    public function itHandlesAClassInTheGlobalNamespace(): void
    {
        $configPath = $this->writeConfig([
            ['service', ['App\\Service\\**']],
        ]);
        $this->declareClasses(['GlobalClass']);

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
    public function itReportsNoLayerWithoutACatchAllSuggestionWhenNoLayersAreDeclared(): void
    {
        // Config file exists but has no architecture section. The source tree
        // holds only the class under test, so the command's full Discovery +
        // Collection phases run in milliseconds.
        $emptyPath = $this->sourcePath();
        $this->declareClasses(['Anything\\At\\All']);
        $configPath = $this->tempDir . '/qmx-empty.yaml';
        file_put_contents($configPath, "paths: ['{$emptyPath}']\n");

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
    public function itIsRegisteredAndDiscoverableOnAConsoleApplication(): void
    {
        // Smoke test: the command can be registered on an Application by name
        // and surfaces under `list` output. This guards against accidental
        // removal of the #[AsCommand] attribute or the command-loader entry.
        $application = new Application();
        $application->addCommand($this->buildCommand());

        self::assertTrue($application->has('debug:layer-assignment'));
        $command = $application->get('debug:layer-assignment');
        self::assertSame('debug:layer-assignment', $command->getName());
        self::assertNotSame('', $command->getDescription());

        $commandConstructor = (new ReflectionClass(LayerAssignmentCommand::class))->getConstructor();
        $resolverConstructor = (new ReflectionClass(LayerAssignmentResolver::class))->getConstructor();
        self::assertNotNull($commandConstructor);
        self::assertNotNull($resolverConstructor);
        self::assertCount(3, $commandConstructor->getParameters());
        self::assertCount(6, $resolverConstructor->getParameters());
    }

    /**
     * Regression test for the command-vs-runtime agreement invariant.
     *
     * Builds a {@see LayerRegistry} from the same overlapping configuration
     * the command will load, asks the registry directly what layer
     * `App\Service\Foo` resolves to ({@see LayerRegistry::resolveLayer()} —
     * the value the runtime {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule}
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
    public function itAgreesWithTheRuntimeLayerRegistryOnAnOverlappingAssignment(): void
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
        $this->declareClasses([$fqn]);

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

    /**
     * The command must apply
     * `paths.excludes` AND filter `@generated` files during its own Discovery
     * phase, exactly like {@see \Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline::analyze()}.
     *
     * Without these filters the command's class set drifts from `qmx check`'s,
     * which silently changes template-layer expansion: excluded/generated
     * classes spawn extra layers, breaking byte-for-byte parity with the
     * analysis command.
     *
     * The probe uses a template layer (`name: 'mod-{module}'` with
     * `pattern: 'App\\{module}\\**'`) so layer existence is observable per
     * input class: a class is only assigned a layer when its module was
     * present in the discovered class set. Excluded and generated files MUST
     * NOT contribute to that set — and, since they contribute nothing, asking
     * about one of them is asking about a class the run never analysed, which
     * the command refuses rather than reports as unclassified.
     */
    #[Test]
    public function itExcludesConfiguredAndGeneratedFilesFromDiscoveryBeforeAssignment(): void
    {
        $sourceRoot = $this->tempDir . '/src';
        mkdir($sourceRoot . '/Service', 0o755, true);
        mkdir($sourceRoot . '/Excluded', 0o755, true);
        mkdir($sourceRoot . '/Generated', 0o755, true);

        file_put_contents(
            $sourceRoot . '/Service/Foo.php',
            "<?php\nnamespace App\\Service;\nfinal class Foo {}\n",
        );
        file_put_contents(
            $sourceRoot . '/Excluded/Bar.php',
            "<?php\nnamespace App\\Excluded;\nfinal class Bar {}\n",
        );
        file_put_contents(
            $sourceRoot . '/Generated/Gen.php',
            "<?php\n\n// @generated by tests\n\nnamespace App\\Generated;\nfinal class Gen {}\n",
        );

        // Template layer + exclude. The template captures every observed
        // top-level module under `App\`, so each discovered namespace becomes
        // its own layer. Whether `App\Excluded\Bar` and `App\Generated\Gen`
        // get layers expanded is the direct probe for discovery filtering.
        // YAML single-quoted strings pass backslashes through literally, so
        // `'App\\{module}\\**'` here (one PHP escape per backslash) writes the
        // YAML scalar `App\{module}\**`, which is the pattern format the
        // matcher expects.
        $configPath = $this->tempDir . '/qmx-discovery.yaml';
        file_put_contents(
            $configPath,
            "paths: ['{$sourceRoot}']\n"
            . "exclude: ['Excluded']\n"
            . "architecture:\n"
            . "  layers:\n"
            . "    - name: 'mod-{module}'\n"
            . "      patterns: ['App\\{module}\\**']\n"
            . "  allow:\n"
            . "    'mod-{module}': []\n"
            . "  coverage-gap: ignore\n",
        );

        // 1. Regular class — its module should have been discovered and the
        //    template should have expanded a matching `mod-Service` layer.
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\Foo',
            '--config' => $configPath,
        ]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Assigned to: mod-Service', $tester->getDisplay());

        // 2. Class inside an excluded directory — file MUST NOT have entered
        //    the class set. The file exists on disk, so the answer is about
        //    the analysed set and not about the filesystem: a refusal, with
        //    no `mod-Excluded` layer expanded anywhere in it.
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Excluded\\Bar',
            '--config' => $configPath,
        ], ['capture_stderr_separately' => true]);
        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('is not among the', $tester->getErrorOutput());
        self::assertStringNotContainsString('mod-Excluded', $tester->getErrorOutput());

        // 3. Class in a file with `@generated` annotation — same: MUST NOT
        //    have entered the class set, so the FQN names nothing analysed.
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Generated\\Gen',
            '--config' => $configPath,
        ], ['capture_stderr_separately' => true]);
        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('is not among the', $tester->getErrorOutput());
        self::assertStringNotContainsString('mod-Generated', $tester->getErrorOutput());

        file_put_contents(
            $configPath,
            file_get_contents($configPath) . "include_generated: true\n",
        );
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Generated\\Gen',
            '--config' => $configPath,
        ]);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Assigned to: mod-Generated', $tester->getDisplay());
    }

    /**
     * `debug:layer-assignment` runs a full Discovery + Collection
     * pass (matching `qmx check` byte-for-byte), but the per-run
     * {@see \Qualimetrix\Infrastructure\Console\RuntimeConfigurator::configure()}
     * hook — which applies the YAML `memory_limit` to the PHP runtime before
     * the parallel-worker pool spins up — was wired only into `CheckCommand`.
     * On any non-trivial codebase the workers exhausted the default 128MB
     * limit and crashed mid-collection.
     *
     * Functional smoke verified the symptom on qualimetrix self, symfony-demo,
     * and composer; the workaround
     * `php -d memory_limit=1G bin/qmx debug:layer-assignment …` confirmed the
     * root cause was the missing `configure()` call.
     *
     * The test pins the fix by configuring an unusual `memory_limit` via
     * `qmx.yaml` and asserting that `\ini_get('memory_limit')` reflects it
     * after the command runs. Without
     * `RuntimeConfigurator::configure()` being invoked from
     * {@see LayerAssignmentCommand::resolveLayerMatches()} the ini value
     * stays at its pre-run default, so the assertion fails — that's the
     * regression guard.
     */
    #[Test]
    public function itAppliesTheConfiguredMemoryLimitBeforeCollectionRuns(): void
    {
        // Pick an obvious sentinel that ini_get() will return verbatim and
        // that is well below any realistic PHP test runner default (so the
        // assertion can't pass by coincidence on a wildly different baseline).
        $sentinelLimit = '513M';
        self::assertNotSame(
            $sentinelLimit,
            \ini_get('memory_limit'),
            'Pre-condition: chosen memory_limit must differ from current ini value to prove the configurator ran.',
        );

        // A source tree of one class, so the command's Discovery + Collection
        // pass is all but a no-op — we are exercising the configurator hook,
        // not the collector machinery. The class has to be there: the command
        // refuses an FQN it never analysed, and the refusal returns before the
        // assertion below could observe the ini value.
        $emptyPath = $this->sourcePath();
        $this->declareClasses(['Anything\\At\\All']);
        $configPath = $this->tempDir . '/qmx-memory.yaml';
        file_put_contents(
            $configPath,
            "paths: ['{$emptyPath}']\nmemory_limit: '{$sentinelLimit}'\n",
        );

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'Anything\\At\\All',
            '--config' => $configPath,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(
            $sentinelLimit,
            \ini_get('memory_limit'),
            'LayerAssignmentCommand must invoke RuntimeConfigurator::configure() so the YAML memory_limit reaches PHP before Collection.',
        );
    }

    #[Test]
    public function itValidatesDynamicComputedSelectorsFromYamlBeforeResolvingAssignment(): void
    {
        foreach (['computed', 'health.complexity', 'health.*'] as $selector) {
            $configPath = $this->writeConfigWithComputedSelector($selector);
            $this->declareClasses(['App\\Service\\UserService']);
            $tester = $this->newTester();
            $exit = $tester->execute([
                'fqn' => 'App\\Service\\UserService',
                '--config' => $configPath,
            ]);

            self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
            self::assertStringContainsString('Assigned to: service', $tester->getDisplay());
        }

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\UserService',
            '--config' => $this->writeConfigWithComputedSelector('computed.stale'),
        ], ['capture_stderr_separately' => true]);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        // Thrown as the `ConfigurationRefusal` carrier and presented on
        // stderr — see the note in `itExitsFailureForANonExistentConfigPath`.
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('does not match any registered', $tester->getErrorOutput());
    }

    /**
     * Pins the JSON schema: exact field set, nullability of `assigned` on a
     * shadowed (non-empty) match, and that `shadowed` preserves declaration
     * order. Any field addition/removal/rename must update this literal
     * expected array, keeping the schema change visible in review.
     */
    #[Test]
    public function itReturnsTheFixedJsonSchemaWithShadowedEntriesWhenLayersOverlap(): void
    {
        // Same overlap as itReportsTheAssignmentAndShadowedLayersForAClassMatchingMultipleLayers:
        // any-foo declared first shadows service for App\Service\Foo.
        $configPath = $this->writeConfig([
            ['any-foo', ['App\\**\\Foo']],
            ['service', ['App\\Service\\**']],
        ]);
        $this->declareClasses(['App\\Service\\Foo']);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\Foo',
            '--config' => $configPath,
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'fqn' => 'App\\Service\\Foo',
            'assigned' => [
                'layer' => 'any-foo',
                'criteria' => ['pattern "App\\**\\Foo"'],
            ],
            'shadowed' => [
                [
                    'layer' => 'service',
                    'criteria' => ['pattern "App\\Service\\**"'],
                ],
            ],
            'hasLayers' => true,
        ], $decoded);
    }

    /**
     * Structural parity check (DoD #5): drives the *same* configuration
     * through both projectors and asserts the JSON payload names every fact
     * the text report names — assigned layer, its matched criterion, the
     * shadowed layer, and its criterion — without parsing the text output
     * into structured data. Both assertions are made against the config's
     * own known layer names/patterns, so agreement between them proves the
     * two projections read one `resolve()` result rather than diverging
     * logic.
     */
    #[Test]
    public function itCoversEveryFactTheTextReportPrintsInTheJsonPayload(): void
    {
        $configPath = $this->writeConfig([
            ['any-foo', ['App\\**\\Foo']],
            ['service', ['App\\Service\\**']],
        ]);
        $fqn = 'App\\Service\\Foo';
        $this->declareClasses([$fqn]);

        $textTester = $this->newTester();
        $textTester->execute(['fqn' => $fqn, '--config' => $configPath]);
        $textOutput = $textTester->getDisplay();

        $jsonTester = $this->newTester();
        $jsonTester->execute(['fqn' => $fqn, '--config' => $configPath, '--format' => 'json']);
        $decoded = json_decode($jsonTester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        // Facts the text report prints (pinned by
        // itReportsTheAssignmentAndShadowedLayersForAClassMatchingMultipleLayers).
        self::assertStringContainsString('Assigned to: any-foo', $textOutput);
        self::assertStringContainsString('pattern "App\\**\\Foo"', $textOutput);
        self::assertStringContainsString('service', $textOutput);
        self::assertStringContainsString('pattern "App\\Service\\**"', $textOutput);

        // Same facts, read from the JSON payload.
        self::assertSame('any-foo', $decoded['assigned']['layer']);
        self::assertSame(['pattern "App\\**\\Foo"'], $decoded['assigned']['criteria']);
        self::assertSame('service', $decoded['shadowed'][0]['layer']);
        self::assertSame(['pattern "App\\Service\\**"'], $decoded['shadowed'][0]['criteria']);
    }

    #[Test]
    public function itReturnsNullAssignedAndEmptyShadowedWhenNothingMatches(): void
    {
        $configPath = $this->writeConfig([
            ['controller', ['App\\Controller\\**']],
        ]);
        $this->declareClasses(['Other\\Place\\Thing']);

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'Other\\Place\\Thing',
            '--config' => $configPath,
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'fqn' => 'Other\\Place\\Thing',
            'assigned' => null,
            'shadowed' => [],
            'hasLayers' => true,
        ], $decoded);
    }

    #[Test]
    public function itReportsHasLayersFalseWhenNoLayersAreDeclared(): void
    {
        $emptyPath = $this->sourcePath();
        $this->declareClasses(['Anything\\At\\All']);
        $configPath = $this->tempDir . '/qmx-empty-json.yaml';
        file_put_contents($configPath, "paths: ['{$emptyPath}']\n");

        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'Anything\\At\\All',
            '--config' => $configPath,
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertFalse($decoded['hasLayers']);
        self::assertNull($decoded['assigned']);
        self::assertSame([], $decoded['shadowed']);
    }

    #[Test]
    public function itMatchesExplicitTextFormatWhenTheFormatOptionIsOmitted(): void
    {
        $configPath = $this->writeConfig([
            ['service', ['App\\Service\\**']],
        ]);
        $this->declareClasses(['App\\Service\\Foo']);

        $default = $this->newTester();
        $default->execute(['fqn' => 'App\\Service\\Foo', '--config' => $configPath]);

        $explicit = $this->newTester();
        $explicit->execute(['fqn' => 'App\\Service\\Foo', '--config' => $configPath, '--format' => 'text']);

        self::assertSame($default->getDisplay(), $explicit->getDisplay());
        self::assertStringNotContainsString('{', $default->getDisplay());
    }

    #[Test]
    public function itExitsInvalidWithoutRunningResolutionForAnUnknownFormat(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\Foo',
            '--format' => 'yaml',
        ]);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        self::assertStringContainsString('Unknown format "yaml"', $tester->getDisplay());
        self::assertStringContainsString('text, json', $tester->getDisplay());
    }

    #[Test]
    public function itReturnsAnErrorEnvelopeForAnInvalidFqnInJsonFormat(): void
    {
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => '',
            '--format' => 'json',
        ]);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $decoded);
        self::assertStringContainsString('must not be empty', $decoded['error']);
        self::assertSame(ConsoleExitCode::Refusal->value, $decoded['exit_code']);
    }

    #[Test]
    public function itReturnsAnErrorEnvelopeWithTheFailureExitCodeForAConfigErrorInJsonFormat(): void
    {
        $missing = $this->tempDir . '/does-not-exist.yaml';
        $tester = $this->newTester();
        $exit = $tester->execute([
            'fqn' => 'App\\Service\\Foo',
            '--config' => $missing,
            '--format' => 'json',
        ]);

        self::assertSame(ConsoleExitCode::Refusal->value, $exit);
        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $decoded);
        self::assertStringContainsString('Configuration error', $decoded['error']);
        self::assertStringContainsString($missing, $decoded['error']);
        self::assertSame(ConsoleExitCode::Refusal->value, $decoded['exit_code']);
    }

    private function newTester(): CommandTester
    {
        return new CommandTester($this->buildCommand());
    }

    private function buildCommand(): LayerAssignmentCommand
    {
        $container = (new ContainerFactory())->create();
        $command = $container->get(LayerAssignmentCommand::class);
        \assert($command instanceof LayerAssignmentCommand);

        return $command;
    }

    /**
     * Writes a qmx.yaml with the given ordered list of layers to the temp
     * directory and returns its absolute path.
     *
     * Uses the current schema: `architecture.layers` is an ordered list
     * of `{name, patterns}` entries (declaration order matters), with a
     * matching `allow: { name: [] }` map and `coverage-gap: ignore`.
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

        // A dedicated tiny source tree, not the repository: the per-class match
        // is independent of the file set, so the tree holds only the classes a
        // test declares through `declareClasses()` and discovery stays fast.
        $sourcePath = $this->sourcePath();
        $yaml = "paths: ['{$sourcePath}']\narchitecture:\n  layers:\n{$layerYaml}  allow:\n{$allowYaml}  coverage-gap: ignore\n";

        $path = $this->tempDir . '/qmx-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    /**
     * The source tree every config in this class points `paths` at.
     */
    private function sourcePath(): string
    {
        $sourcePath = $this->tempDir . '/source';
        if (!is_dir($sourcePath)) {
            mkdir($sourcePath, 0o755, true);
        }

        return $sourcePath;
    }

    /**
     * Materialises one PHP file per FQN in the source tree.
     *
     * The command answers for the set it analysed, so a test asking about a
     * class must first make that class exist — an FQN naming no analysed
     * declaration is refused, not reported (`refuseUnknownClass()`).
     *
     * @param list<string> $fqns
     */
    private function declareClasses(array $fqns): void
    {
        foreach ($fqns as $fqn) {
            $position = strrpos($fqn, '\\');
            $namespace = $position === false ? null : substr($fqn, 0, $position);
            $shortName = $position === false ? $fqn : substr($fqn, $position + 1);
            $body = "<?php\n"
                . ($namespace === null ? '' : "\nnamespace {$namespace};\n")
                . "\nfinal class {$shortName} {}\n";
            file_put_contents(
                $this->sourcePath() . '/' . str_replace('\\', '_', $fqn) . '.php',
                $body,
            );
        }
    }

    private function writeConfigWithComputedSelector(string $selector): string
    {
        $path = $this->writeConfig([
            ['service', ['App\\Service\\**']],
        ]);
        file_put_contents(
            $path,
            (string) file_get_contents($path)
            . "computed_metrics:\n"
            . "  computed.local:\n"
            . "    formula: '1'\n"
            . "    levels: [class]\n"
            . "only_rules: ['{$selector}']\n",
        );

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
