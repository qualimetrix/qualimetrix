<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\Channel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Every channel that says a configured value bound to nothing must ask first
 * whether this run could tell — and this is the test that will not let the
 * seventh such channel be added without one.
 *
 * A per-channel unit test cannot prove every scope-conditioned channel asks
 * the question: each one can pass on its own fixture while a new channel lacks
 * the precondition. This guard derives the population from the product.
 *
 * **The population is read from the product, not typed here.** Every channel
 * whose declared name contains `unmatched` is scope-conditioned; that is the
 * naming convention ADR 0061 fixes and this file guards, and
 * {@see itDeclaresTheScopeConditionedPopulation} fails when the product's set
 * and the list below drift apart in either direction — a new channel not
 * listed, or a listed channel gone.
 *
 * **What the silent half does not cover.** Two of the six place no subject of
 * their own — `coupling.unmatched-framework-namespace` names code outside the
 * project, and a rule cannot see the run's paths — so they answer the
 * project-wide question alone. They are gated here, and
 * {@see itJudgesOnlyTheValuesWhoseSubjectTheRunAnalysed} deliberately covers
 * only the four that can place a subject.
 *
 * **The pair is the proof.** One fixture, several `composer.json` files. On a
 * run whose paths cover everything the manifest declares production — through
 * `psr-4`, `classmap` or `files` alike — the product can judge and every one
 * of the six channels speaks; on the same tree and the same configuration
 * under a manifest declaring a production target the run never looked at, or
 * under one declaring no production autoload at all, every one of the six must
 * be silent. Without the speaking half, silence would not distinguish a
 * working gate from a fixture that cannot produce the channel at all; without
 * the silent half, a channel with no gate passes.
 *
 * Production targets declared through `classmap`, `psr-0` or `files` are
 * judged like PSR-4 targets. Silence is earned only by a target outside the
 * run or by a manifest that declares no readable production autoload.
 */
final class ScopeConditionedChannelGuardTest extends TestCase
{
    /**
     * The channels under test, spelled out so the assertion has two sides.
     * Their producers live in five different owners, which is why the list
     * cannot be read off one class.
     */
    private const array SCOPE_CONDITIONED = [
        'architecture.unmatched-exclude',
        'coupling.unmatched-framework-namespace',
        'discovery.unmatched-exclude',
        'suppression.unmatched-namespace',
        'suppression.unmatched-path',
        'suppression.unmatched-rule-ledger',
    ];

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-scope-conditioned-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        mkdir($this->fixture . '/tests', 0o755, true);
        mkdir($this->fixture . '/bootstrap', 0o755, true);

        // Production code the run over `src` never looks at. It exists on
        // disk on purpose: a declared target that does not resolve is skipped
        // by the measurement, so a phantom one would leave the gate open and
        // the silent half would pass without proving anything.
        file_put_contents($this->fixture . '/bootstrap/helpers.php', "<?php\n\nfunction sample_helper(): int\n{\n    return 1;\n}\n");

        file_put_contents($this->fixture . '/src/Service.php', <<<'PHP'
            <?php

            namespace Sample;

            class Service
            {
                public function value(): int
                {
                    return 1;
                }
            }
            PHP);

        // A second class depending on the first: without an edge the
        // dependency graph classifies no name, and the coupling channel is
        // silent for a reason that has nothing to do with scope.
        file_put_contents($this->fixture . '/src/Client.php', <<<'PHP'
            <?php

            namespace Sample;

            class Client
            {
                public function __construct(private Service $service) {}

                public function value(): int
                {
                    return $this->service->value();
                }
            }
            PHP);

        file_put_contents($this->fixture . '/tests/ServiceTest.php', <<<'PHP'
            <?php

            namespace Sample\Tests;

            class ServiceTest
            {
                public function value(): int
                {
                    return 1;
                }
            }
            PHP);

        // Every one of the six channels is armed by a value that binds to
        // nothing anywhere in this tree — the shape each channel exists to
        // report, so silence below can only come from the gate.
        file_put_contents($this->fixture . '/qmx.yaml', <<<'YAML'
            cache:
              enabled: false
            exclude:
              - subtree: gone-dir
            suppress_paths:
              - subtree: src/Gone
            suppress_namespaces:
              - subtree: 'Sample\Gone'
            coupling:
              frameworkNamespaces:
                - subtree: Nowhere
            architecture:
              coverage-gap: ignore
              layers:
                - name: domain
                  patterns: ['Sample\**']
                  exclude:
                    suffix: ['NothingLikeThis']
            rules:
              complexity.ccn:
                suppress_paths:
                  - subtree: src/AlsoGone
            YAML);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->fixture));
    }

    /**
     * The denominator: what the product declares against what this file
     * guards. A seventh channel named `*.unmatched-*` fails here until it is
     * listed — and listing it puts it into the two runs below, which is where
     * its gate is actually measured.
     */
    #[Test]
    public function itDeclaresTheScopeConditionedPopulation(): void
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        self::assertInstanceOf(ChannelDeclarationRegistryInterface::class, $registry);

        $declared = array_values(array_filter(
            array_keys($registry->staticDeclarations()),
            static fn(string $channel): bool => str_contains($channel, 'unmatched'),
        ));
        sort($declared);

        self::assertSame(
            self::SCOPE_CONDITIONED,
            $declared,
            'A channel about a value that bound to nothing must be listed here and gated in the run below.',
        );
    }

    /**
     * The speaking half: on a run that can judge, every one of the six fires —
     * whichever autoload mechanism the manifest used to declare what the run
     * covered.
     *
     * @param array<string, mixed> $autoload
     * @param list<string> $paths the run's paths
     */
    #[Test]
    #[DataProvider('provideManifestsTheRunCovers')]
    public function itSpeaksOnEveryScopeConditionedChannelWhenTheRunCanJudge(array $autoload, array $paths = ['src']): void
    {
        $spoke = $this->channelsOf($this->check($autoload, $paths));

        self::assertSame(
            self::SCOPE_CONDITIONED,
            $spoke,
            'The fixture must be able to produce every channel, or the silent half proves nothing.',
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideManifestsTheRunCovers(): iterable
    {
        yield 'psr-4' => [['autoload' => ['psr-4' => ['Sample\\' => 'src/']]]];

        yield 'classmap alone, which used to silence the row' => [['autoload' => ['classmap' => ['src/']]]];

        yield 'a files entry inside the analysed directory, beside psr-4' => [[
            'autoload' => ['psr-4' => ['Sample\\' => 'src/'], 'files' => ['src/Service.php']],
        ]];

        yield 'psr-0 alone' => [['autoload' => ['psr-0' => ['Sample_' => 'src/']]]];

        // The discriminating shape: the gate opens because the run names the
        // directory holding the declared file, not because the run happens to
        // be the project root or to equal the declared target.
        yield 'a files entry outside src, with a run that reaches it' => [
            ['autoload' => ['psr-4' => ['Sample\\' => 'src/'], 'files' => ['bootstrap/helpers.php']]],
            ['src', 'bootstrap'],
        ];
    }

    /**
     * The silent half, first shape: the same tree and the same configuration
     * under a manifest declaring production code the run never analysed. The
     * run is a slice, so no channel may accuse anyone — and it
     * makes no difference which section declared the part left out.
     *
     * @param array<string, mixed> $autoload
     */
    #[Test]
    #[DataProvider('provideManifestsWithProductionOutsideTheRun')]
    public function itStaysSilentOnEveryScopeConditionedChannelWhenTheRunIsASlice(array $autoload): void
    {
        $spoke = $this->channelsOf($this->check($autoload));

        self::assertSame([], $spoke, 'A run that did not cover the declared production code may judge no value.');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideManifestsWithProductionOutsideTheRun(): iterable
    {
        yield 'a files entry outside the analysed directory' => [[
            'autoload' => ['psr-4' => ['Sample\\' => 'src/'], 'files' => ['bootstrap/helpers.php']],
        ]];

        yield 'a classmap entry outside the analysed directory' => [[
            'autoload' => ['psr-4' => ['Sample\\' => 'src/'], 'classmap' => ['bootstrap/']],
        ]];

        yield 'a psr-4 root outside the analysed directory' => [[
            'autoload' => ['psr-4' => ['Sample\\' => 'src/', 'Sample\\Bootstrap\\' => 'bootstrap/']],
        ]];

        yield 'a classmap-only project checked by one of its two entries' => [[
            'autoload' => ['classmap' => ['src/', 'bootstrap/']],
        ]];
    }

    /**
     * The silent half, second shape and the only remaining "cannot judge":
     * the manifest declares no production autoload this product can read at
     * all. There is no denominator, so there is no coverage verdict, so no
     * channel may accuse anyone.
     *
     * @param ?string $manifest raw `composer.json` content, or null for no manifest at all
     */
    #[Test]
    #[DataProvider('provideManifestsThatDeclareNoProductionAutoload')]
    public function itStaysSilentOnEveryScopeConditionedChannelWhenNothingDeclaresProduction(?string $manifest): void
    {
        $spoke = $this->channelsOf($this->checkWithRawManifest($manifest));

        self::assertSame([], $spoke, 'A run with nothing to measure coverage against may judge no configured value.');
    }

    /** @return iterable<string, array{?string}> */
    public static function provideManifestsThatDeclareNoProductionAutoload(): iterable
    {
        yield 'no composer.json at all' => [null];
        yield 'a composer.json that does not parse' => ['{ "autoload": { "psr-4": '];
        yield 'a manifest with no autoload section' => ['{"name":"acme/demo"}'];
        yield 'only a dev section' => ['{"autoload-dev":{"psr-4":{"Sample\\\\Tests\\\\":"tests/"}}}'];
    }

    /**
     * The subject half of the same question, which coverage alone cannot
     * answer: `qmx check src/` covers this project's production autoload, and
     * an entry naming `tests/` is still correct configuration it never looked
     * at. Its stale twin inside `src/` is reported by the same run, so the
     * silence is about the subject and not about the channel being off.
     */
    #[Test]
    public function itJudgesOnlyTheValuesWhoseSubjectTheRunAnalysed(): void
    {
        file_put_contents($this->fixture . '/qmx.yaml', <<<'YAML'
            cache:
              enabled: false
            exclude:
              - subtree: tests
            suppress_paths:
              - subtree: tests/Gone
              - subtree: src/Gone
            suppress_namespaces:
              - subtree: 'Sample\Tests\Gone'
              - subtree: 'Sample\Gone'
            YAML);

        $tester = $this->check([
            'autoload' => ['psr-4' => ['Sample\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Sample\\Tests\\' => 'tests/']],
        ]);

        $messages = implode(' | ', array_map(
            static fn(array $violation): string => (string) ($violation['message'] ?? ''),
            $this->violations($tester),
        ));

        self::assertStringContainsString('src/Gone', $messages);
        self::assertStringContainsString('Sample\\Gone', $messages);
        self::assertStringNotContainsString('tests/Gone', $messages);
        self::assertStringNotContainsString('Sample\\Tests\\Gone', $messages);
        self::assertStringNotContainsString('"tests"', $messages);
    }

    /**
     * @return list<string> the scope-conditioned channels this run reported, sorted
     */
    private function channelsOf(CommandTester $tester): array
    {
        $spoke = [];
        foreach ($this->violations($tester) as $violation) {
            $rule = (string) ($violation['rule'] ?? '');
            if (\in_array($rule, self::SCOPE_CONDITIONED, true) && !\in_array($rule, $spoke, true)) {
                $spoke[] = $rule;
            }
        }

        sort($spoke);

        return $spoke;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function violations(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('violations', $payload, $tester->getErrorOutput());
        $violations = $payload['violations'];
        self::assertIsList($violations);

        $rows = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            $rows[] = $violation;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $autoload
     * @param list<string> $paths
     */
    private function check(array $autoload, array $paths = ['src']): CommandTester
    {
        return $this->checkWithRawManifest(json_encode($autoload, \JSON_THROW_ON_ERROR), $paths);
    }

    /**
     * @param ?string $manifest raw manifest content, or null to leave the fixture without one
     * @param list<string> $paths
     */
    private function checkWithRawManifest(?string $manifest, array $paths = ['src']): CommandTester
    {
        if ($manifest !== null) {
            file_put_contents($this->fixture . '/composer.json', $manifest);
        }

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
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}
