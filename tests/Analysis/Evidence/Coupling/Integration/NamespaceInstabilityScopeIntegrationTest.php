<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingCollector;
use Qualimetrix\Analysis\Evidence\Coupling\Instability\InstabilityRule;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `App\Ctx` is the root of a bounded context that declares no type of its
 * own: its subtree folds in every outward dependency of `App\Ctx\Impl` while
 * one client reaches it through `App\Ctx\Contract`, so its subtree
 * instability is 0.86 by construction. The other namespaces separate the own
 * scope from the subtree: `App\Svc` is unstable itself but stable as a
 * subtree, `App\Port` declares only interfaces, and `App\Lone` is reached
 * only through its sub-namespace.
 */
#[CoversClass(InstabilityRule::class)]
#[CoversClass(CouplingCollector::class)]
final class NamespaceInstabilityScopeIntegrationTest extends TestCase
{
    private const string CONTEXT = <<<'PHP'
        <?php
        namespace App\Ext { final class E1 {} final class E2 {} final class E3 {} final class E4 {} final class E5 {} final class E6 {} }
        namespace App\Ctx\Contract { interface Port {} interface Port2 {} interface Port3 {} }
        namespace App\Ctx\Impl {
            use App\Ext;
            final class S1 implements \App\Ctx\Contract\Port { public function a(Ext\E1 $a, Ext\E2 $b): void {} }
            final class S2 { public function a(Ext\E3 $a, Ext\E4 $b): void {} }
            final class S3 { public function a(Ext\E5 $a, Ext\E6 $b): void {} }
        }
        namespace App\Client { final class C { public function a(\App\Ctx\Contract\Port $p): void {} } }
        PHP;

    private const string SCOPES = <<<'PHP'
        <?php
        namespace App\Svc {
            use App\Ext;
            final class S1 { public function a(Ext\E1 $a, Ext\E2 $b): void {} }
            final class S2 { public function a(Ext\E3 $a, Ext\E4 $b): void {} }
            final class S3 { public function a(Ext\E5 $a, Ext\E6 $b): void {} }
        }
        namespace App\Svc\Model { final class M1 {} final class M2 {} final class M3 {} }
        namespace App\Port {
            use App\Ext;
            interface P1 { public function a(Ext\E1 $a, Ext\E2 $b, Ext\E3 $c, Ext\E4 $d, Ext\E5 $e, Ext\E6 $f): void; }
            interface P2 {}
            interface P3 {}
        }
        namespace App\Lone {
            use App\Ext;
            final class L1 { public function a(Ext\E1 $a, Ext\E2 $b): void {} }
            final class L2 { public function a(Ext\E3 $a, Ext\E4 $b): void {} }
            final class L3 { public function a(Ext\E5 $a, Ext\E6 $b): void {} }
        }
        namespace App\Lone\Api { final class Thing {} }
        namespace App\Use {
            final class U1 { public function a(\App\Svc\S1 $s, \App\Port\P1 $p, \App\Lone\Api\Thing $t): void {} }
            final class U2 { public function a(\App\Svc\Model\M1 $m): void {} }
            final class U3 { public function a(\App\Svc\Model\M1 $m): void {} }
            final class U4 { public function a(\App\Svc\Model\M1 $m): void {} }
            final class U5 { public function a(\App\Svc\Model\M1 $m): void {} }
            final class U6 { public function a(\App\Svc\Model\M1 $m): void {} }
            final class U7 { public function a(\App\Svc\Model\M1 $m): void {} }
        }
        PHP;

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-namespace-instability-scope-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);

        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->fixture . '/src/Context.php', self::CONTEXT);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->fixture)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->fixture);
    }

    #[Test]
    public function itDoesNotJudgeAContextRootThatDeclaresNoTypeOfItsOwn(): void
    {
        self::assertSame([], self::namespaceFindings($this->check(['src'])));
    }

    #[Test]
    public function itPublishesNoOwnInstabilityForANamespaceDeclaringNoType(): void
    {
        $context = $this->namespaceMetrics()['App\Ctx'];

        self::assertArrayNotHasKey('coupling.instability-own', $context);
        self::assertEqualsWithDelta(6 / 7, $context['coupling.instability'] ?? null, 0.0001);
        self::assertSame(0, $context['coupling.ca-own'] ?? null);
        self::assertSame(0, $this->namespaceMetrics()['App\Ctx\Contract']['coupling.instability-own'] ?? null);
    }

    /**
     * `App\Svc` is judged on its own Ca and Ce although its subtree, which
     * the six users of its model reach, is stable; `App\Port` counts its
     * interfaces towards the type minimum; `App\Lone` is left out because
     * nothing depends on what it declares itself.
     */
    #[Test]
    public function itJudgesEveryNamespaceOnTheCouplingOfItsOwnTypes(): void
    {
        file_put_contents($this->fixture . '/src/Scopes.php', self::SCOPES);

        $payload = $this->check(['src']);

        self::assertEqualsWithDelta(['App\Port' => 6 / 7, 'App\Svc' => 6 / 7], self::namespaceFindings($payload), 0.0001);
        foreach (self::namespaceMessages($payload) as $message) {
            self::assertStringContainsString('(Ca=1, Ce=6)', $message);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('retiredClassCountKeys')]
    public function itRefusesTheRetiredClassCountKeyByName(?string $config, array $input): void
    {
        if ($config !== null) {
            file_put_contents($this->fixture . '/qmx.yaml', $config);
            $input['--config'] = 'qmx.yaml';
        }

        [$status, $payload] = $this->runCheck(['paths' => ['src'], ...$input]);

        self::assertSame(3, $status);
        self::assertStringContainsString('min_class_count', (string) ($payload['error'] ?? ''));
    }

    /** @return iterable<string, array{?string, array<string, mixed>}> */
    public static function retiredClassCountKeys(): iterable
    {
        yield 'configuration file' => ["rules:\n  coupling.instability:\n    namespace:\n      min_class_count: 3\n", []];
        yield 'rule option' => [null, ['--rule-opt' => ['coupling.instability:namespace.min_class_count=3']]];
    }

    /**
     * A formula reading the own instability bare gives a namespace that
     * declares no type no value instead of a fabricated 0, and says so.
     */
    #[Test]
    public function itLeavesAFormulaOverOwnInstabilityWithoutValueOnANamespaceDeclaringNoType(): void
    {
        file_put_contents(
            $this->fixture . '/qmx.yaml',
            "computed_metrics:\n  computed.own-instability:\n    formula: 'm[\"coupling.instability-own\"]'\n    levels: [namespace]\n    warning: 0.95\n    error: 0.99\n",
        );

        [$status, $payload] = $this->runCheck([
            'paths' => ['src'],
            '--config' => 'qmx.yaml',
            '--only-rule' => ['computed.own-instability'],
            '--fail-on' => 'none',
        ]);

        self::assertSame(0, $status);
        self::assertIsList($payload['computedMetricOutcomes'] ?? null);
        self::assertCount(1, $payload['computedMetricOutcomes']);
        $outcome = $payload['computedMetricOutcomes'][0];
        self::assertIsArray($outcome);
        self::assertSame(['coupling.instability-own'], $outcome['missingKeys'] ?? null);
        self::assertSame(['ns:App', 'ns:App\Ctx'], $outcome['subjects'] ?? null);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<mixed> the JSON report
     */
    private function check(array $paths): array
    {
        [, $payload] = $this->runCheck([
            'paths' => $paths,
            '--fail-on' => 'none',
            '--only-rule' => [InstabilityRule::NAME],
        ]);
        self::assertIsList($payload['violations'] ?? null);

        return $payload;
    }

    /**
     * @return array<string, array<string, mixed>> namespace metric bags, by namespace
     */
    private function namespaceMetrics(): array
    {
        [, $payload] = $this->runCheck(['paths' => ['src'], '--format' => 'metrics', '--fail-on' => 'none']);
        self::assertIsList($payload['symbols'] ?? null);

        $bags = [];
        foreach ($payload['symbols'] as $symbol) {
            self::assertIsArray($symbol);
            if (($symbol['type'] ?? null) === 'namespace') {
                self::assertIsArray($symbol['metrics']);
                $bags[(string) $symbol['name']] = $symbol['metrics'];
            }
        }

        return $bags;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, array<mixed>} the exit status and the decoded report
     */
    private function runCheck(array $input): array
    {
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);

        $tester = new CommandTester($command);

        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $status = $tester->execute(
                ['--workers' => '0', '--format' => 'json', '--no-cache' => true, ...$input],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload, $tester->getDisplay() . $tester->getErrorOutput());

        return [$status, $payload];
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, float> the namespace-level findings, by namespace, with the value judged
     */
    private static function namespaceFindings(array $payload): array
    {
        $findings = [];
        foreach (self::namespaceViolations($payload) as $violation) {
            $findings[(string) $violation['symbol']] = (float) $violation['metricValue'];
        }
        ksort($findings);

        return $findings;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<string>
     */
    private static function namespaceMessages(array $payload): array
    {
        return array_map(static fn(array $violation): string => (string) $violation['message'], self::namespaceViolations($payload));
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array<mixed>>
     */
    private static function namespaceViolations(array $payload): array
    {
        self::assertIsList($payload['violations'] ?? null);

        $violations = [];
        foreach ($payload['violations'] as $violation) {
            self::assertIsArray($violation);
            if (!str_starts_with((string) ($violation['subject'] ?? ''), 'declaration:')) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }
}
