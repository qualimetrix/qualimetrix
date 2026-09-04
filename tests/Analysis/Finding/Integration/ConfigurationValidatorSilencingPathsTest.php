<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The four ways a run can stop a configuration diagnostic from being
 * reported, held fixed while the diagnostics moved out of their rule classes
 * and into configuration validators.
 *
 * Moving them changed who declares and who emits them; it must change nothing
 * about who can silence them. Each of the eight is addressable by its own
 * name and by its producer's, in both directions of selection — `--disable-rule`
 * and `only_rules`, which resolve a selector through two different mechanisms —
 * `exclude_paths` keyed by the producer reaches
 * the three that carry a file and none of the five that do not,
 * `exclude_namespaces` reaches none of them, and the producer's `enabled`
 * option switches the whole family off. All four are behaviour of the
 * producer name, which is exactly the binding
 * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface::producerRuleName()}
 * carries — remove it and this test goes red.
 *
 * The eight are enumerated, not sampled, and the enumeration is checked
 * against the registry's own answer so a ninth diagnostic cannot appear
 * without a row here.
 *
 * The gate cannot see any of this: no corpus case uses `--disable-rule`,
 * `only_rules` or a non-empty `exclude_paths`.
 */
final class ConfigurationValidatorSilencingPathsTest extends TestCase
{
    /** The five that report on the layer declaration; none carries a file. */
    private const array LAYER_DIAGNOSTICS = [
        LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME,
        LayerPolicyPreparationInterface::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
        LayerPolicyPreparationInterface::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME,
        LayerPolicyPreparationInterface::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
        LayerPolicyPreparationInterface::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
    ];

    /** The three that report on an inline directive; each carries its file. */
    private const array DIRECTIVE_DIAGNOSTICS = [
        InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME,
        InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME,
        InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME,
    ];

    private const string LAYER_PRODUCER = LayerPolicyPreparationInterface::PRODUCER_RULE_NAME;

    private const string DIRECTIVE_PRODUCER = InlineDirectivePolicyInterface::PRODUCER_RULE_NAME;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-silencing-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src/Controller', 0777, true);
        mkdir($this->tempDir . '/src/Repository', 0777, true);
        mkdir($this->tempDir . '/src/Orphan', 0777, true);

        // A class outside every layer feeds `architecture.coverage`.
        file_put_contents($this->tempDir . '/src/Orphan/Loner.php', <<<'PHP'
            <?php

            namespace Silencing\Orphan;

            class Loner
            {
            }
            PHP);

        // Matched by both `controller` and the narrower `controller-exact`
        // declared after it (potential shadow) and by the pending `future`.
        file_put_contents($this->tempDir . '/src/Controller/UserController.php', <<<'PHP'
            <?php

            namespace Silencing\Controller;

            class UserController
            {
                public function run(): void
                {
                }
            }
            PHP);

        // The three broken directives, all on one file.
        file_put_contents($this->tempDir . '/src/Repository/Directives.php', <<<'PHP'
            <?php

            namespace Silencing\Repository;

            class Directives
            {
                /**
                 * @qmx-ignore no.such.rule -- names a rule that does not exist
                 */
                public function unresolved(): void
                {
                }

                /**
                 * @qmx-threshold annotation.directive warning=1 error=2 -- retunes a rule with no threshold
                 */
                public function unsupported(): void
                {
                }

                /**
                 * @qmx-threshold complexity.cyclomatic warning=notanumber -- unparseable value
                 */
                public function invalid(): void
                {
                }
            }
            PHP);

        file_put_contents($this->tempDir . '/qmx.yaml', <<<'YAML'
            architecture:
              layers:
                - name: controller
                  patterns: ['Silencing\Controller\**']
                - name: controller-exact
                  patterns: ['Silencing\Controller\User*']
                - name: repository
                  patterns: ['Silencing\Repository\**']
                - name: nowhere
                  patterns: ['Silencing\NoSuchPlace\**']
                - name: future
                  patterns: ['Silencing\Controller\*']
                  pending: true
                - name: 'mod-{m}'
                  patterns: ['Silencing\NoTemplate\{m}\**']
              allow:
                controller: []
                controller-exact: []
                repository: []
              coverage: error
            YAML);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    /**
     * The enumeration below is the whole point of this file, so it is checked
     * against the registry rather than trusted: a diagnostic the container
     * classifies as a configuration error and this file does not list would
     * otherwise be silently untested.
     */
    #[Test]
    public function theEnumeratedEightAreExactlyTheConfigurationErrorChannels(): void
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        self::assertInstanceOf(ChannelDeclarationRegistryInterface::class, $registry);

        $fromRegistry = [];
        foreach ($registry->staticDeclarations() as $key => $declaration) {
            if ($declaration->isConfigurationError()) {
                $fromRegistry[] = new FindingChannel($key)->code;
            }
        }
        sort($fromRegistry);

        $enumerated = self::allDiagnostics();
        sort($enumerated);

        self::assertSame($fromRegistry, $enumerated);
    }

    /**
     * The binding itself, read off the assembled universe.
     *
     * Every one of the eight resolves to the producer that owns it, and that
     * one answer is what `--disable-rule`, `exclude_paths`, the channel
     * description, the documentation page and the remediation estimate are
     * all looked up through. A validator that named a different producer
     * would move all five at once, silently.
     */
    /**
     * @param list<string> $owned
     */
    #[Test]
    #[DataProvider('provideProducers')]
    public function everyDiagnosticResolvesToTheProducerThatOwnsIt(string $producer, array $owned): void
    {
        $universe = (new ContainerFactory())->create()->get(ChannelIdentityInterface::class);
        self::assertInstanceOf(ChannelIdentityInterface::class, $universe);

        foreach ($owned as $diagnostic) {
            self::assertSame($producer, $universe->producerOf($diagnostic), $diagnostic);
        }
    }

    #[Test]
    public function everyDiagnosticIsReportedWhenNothingSilencesIt(): void
    {
        self::assertSame(self::allDiagnostics(), $this->diagnosticsFrom([]));
    }

    /**
     * Path 1: the diagnostic's own name. It is a channel selector, so it
     * reaches exactly one of the eight.
     */
    #[Test]
    #[DataProvider('provideDiagnostics')]
    public function eachDiagnosticIsSilencedBySelectingItsOwnNameOff(string $diagnostic): void
    {
        $remaining = $this->diagnosticsFrom(['--disable-rule' => [$diagnostic]]);

        self::assertNotContains($diagnostic, $remaining);
        self::assertSame(
            array_values(array_diff(self::allDiagnostics(), [$diagnostic])),
            $remaining,
            'Disabling one diagnostic must not disturb its siblings.',
        );
    }

    /**
     * Path 2: the producer's name. This is the binding a validator declares,
     * and disabling the producer takes its validator with it.
     */
    /**
     * @param list<string> $owned
     */
    #[Test]
    #[DataProvider('provideProducers')]
    public function selectingAProducerOffSilencesEveryDiagnosticItOwns(string $producer, array $owned): void
    {
        self::assertSame(
            array_values(array_diff(self::allDiagnostics(), $owned)),
            $this->diagnosticsFrom(['--disable-rule' => [$producer]]),
        );
    }

    /**
     * The other direction of path 1, and the one whose mechanism the
     * extraction actually moved.
     *
     * `--disable-rule` compares the selector against the producer name alone;
     * `only_rules` resolves it through `channelsProducedBy()`, i.e. through
     * the very map the validator's channels are newly written into. An
     * implementation that registered them under some identity of the
     * validator's own would keep every negative-selection assertion in this
     * file green and still break `only_rules: [architecture.coverage]`.
     *
     * The remaining set is asserted exactly, not by presence: a selection that
     * left a sibling standing is the failure this closes.
     */
    #[Test]
    #[DataProvider('provideDiagnostics')]
    public function selectingOneDiagnosticOnLeavesExactlyThatOne(string $diagnostic): void
    {
        self::assertSame(
            [$diagnostic],
            $this->diagnosticsFrom(['--only-rule' => [$diagnostic]]),
            'Positive selection by a diagnostic\'s own name must reach it and nothing else.',
        );
    }

    /**
     * The other direction of path 2. Selecting the producer on selects every
     * diagnostic it owns — the rule's own and its validator's alike, because
     * both are registered under the one producer name.
     *
     * @param list<string> $owned
     */
    #[Test]
    #[DataProvider('provideProducers')]
    public function selectingAProducerOnLeavesExactlyTheDiagnosticsItOwns(string $producer, array $owned): void
    {
        self::assertSame(
            $owned,
            $this->diagnosticsFrom(['--only-rule' => [$producer]]),
            'Positive selection by producer name must reach every diagnostic that producer owns, and no'
            . ' diagnostic of the other producer.',
        );
    }

    /**
     * Path 3: `exclude_paths` keyed by the producer. Live for the three
     * directive diagnostics, which carry the file the annotation is written
     * in; inert for the five layer diagnostics, whose location is
     * `Location::none()`.
     */
    #[Test]
    public function pathExclusionReachesTheDirectiveDiagnosticsAndNotTheLayerOnes(): void
    {
        self::assertSame(
            self::LAYER_DIAGNOSTICS,
            $this->diagnosticsFrom(['--rule-opt' => [self::DIRECTIVE_PRODUCER . ':exclude_paths=**']]),
            'A directive diagnostic carries its file, so a path exclusion keyed by its producer reaches it.',
        );

        self::assertSame(
            self::allDiagnostics(),
            $this->diagnosticsFrom(['--rule-opt' => [self::LAYER_PRODUCER . ':exclude_paths=**']]),
            'A layer diagnostic has no file, so a path exclusion cannot reach it. This is today\'s behaviour,'
            . ' pinned so the move out of the rule class does not change it by accident.',
        );
    }

    /**
     * Path 4: `exclude_namespaces` keyed by the producer — inert for all
     * eight, because a project-level and a file-level subject carry no
     * namespace for the filter to compare.
     */
    #[Test]
    public function namespaceExclusionReachesNoneOfTheEight(): void
    {
        self::assertSame(
            self::allDiagnostics(),
            $this->diagnosticsFrom(['--rule-opt' => [
                self::LAYER_PRODUCER . ':exclude_namespaces=Silencing',
                self::DIRECTIVE_PRODUCER . ':exclude_namespaces=Silencing',
            ]]),
        );
    }

    /**
     * Path 5 by count, fourth by kind: the producer's own `enabled` option.
     * The validator answers to the producer's options, so switching the
     * producer off switches its diagnostics off — which is what the rule
     * class used to do with an early return.
     */
    /**
     * @param list<string> $owned
     */
    #[Test]
    #[DataProvider('provideProducers')]
    public function disablingAProducerByOptionSilencesEveryDiagnosticItOwns(string $producer, array $owned): void
    {
        self::assertSame(
            array_values(array_diff(self::allDiagnostics(), $owned)),
            $this->diagnosticsFrom(['--rule-opt' => [$producer . ':enabled=false']]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDiagnostics(): iterable
    {
        foreach (self::allDiagnostics() as $diagnostic) {
            yield $diagnostic => [$diagnostic];
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideProducers(): iterable
    {
        yield self::LAYER_PRODUCER => [self::LAYER_PRODUCER, self::LAYER_DIAGNOSTICS];
        yield self::DIRECTIVE_PRODUCER => [self::DIRECTIVE_PRODUCER, self::DIRECTIVE_DIAGNOSTICS];
    }

    /**
     * @return list<string>
     */
    private static function allDiagnostics(): array
    {
        return [...self::LAYER_DIAGNOSTICS, ...self::DIRECTIVE_DIAGNOSTICS];
    }

    /**
     * The diagnostics a run reports, in the enumeration's own order so a
     * comparison names what is missing rather than what moved.
     *
     * @param array<string, mixed> $extraOptions
     *
     * @return list<string>
     */
    private function diagnosticsFrom(array $extraOptions): array
    {
        /** @var CheckCommand $command */
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($command);

        $tester->execute([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $this->tempDir . '/qmx.yaml',
            '--format' => 'json',
            '--workers' => 0,
            '--no-cache' => true,
            '--no-progress' => true,
            '--fail-on' => 'none',
            ...$extraOptions,
        ]);

        $report = json_decode(self::extractJsonObject($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['violations'] ?? null);

        $reported = [];
        foreach ($report['violations'] as $finding) {
            $reported[] = new FindingChannel($finding['channel'])->code;
        }

        return array_values(array_filter(
            self::allDiagnostics(),
            static fn(string $diagnostic): bool => \in_array($diagnostic, $reported, true),
        ));
    }

    /**
     * Configuration warnings may precede the document on the same stream.
     */
    private static function extractJsonObject(string $output): string
    {
        foreach (str_split($output) as $offset => $char) {
            if ($char !== '{') {
                continue;
            }

            $candidate = substr($output, $offset);
            json_decode($candidate, true);
            if (json_last_error() === \JSON_ERROR_NONE) {
                return $candidate;
            }
        }

        return $output;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        foreach (array_diff($entries === false ? [] : $entries, ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
