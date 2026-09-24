<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\CboRule;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Which namespaces `coupling.cbo` judges must not depend on what else the run
 * holds; the value judged, like any coupling measure, counts the dependencies
 * the run analysed. `App\Svc` declares three classes coupled to fifteen
 * namespaces and has one sub-namespace, `App\Svc\Exception`, holding a single
 * class. Whether the sub-namespace is in the run decides whether `App\Svc` is
 * a region of one namespace or of two; the coupling of the classes `App\Svc`
 * declares is the same either way, and so is the finding. A class outside the
 * run that depends on `App\Svc` is a dependency the run never read.
 */
#[CoversClass(CboRule::class)]
final class NamespaceCboScopeIntegrationTest extends TestCase
{
    private const array FILES = ['Svc/S1.php', 'Svc/S2.php', 'Svc/S3.php', 'Svc/Exception/Oops.php', 'Svc/Handler/H.php'];

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-namespace-cbo-scope-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/Svc/Exception', 0o755, true);

        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );

        $parameters = '';
        for ($i = 1; $i <= 15; $i++) {
            $parameters .= \sprintf("    public function use%d(\\Dep%d\\X \$x): void {}\n", $i, $i);
        }
        foreach ([1, 2, 3] as $i) {
            file_put_contents(
                $this->fixture . \sprintf('/src/Svc/S%d.php', $i),
                \sprintf("<?php\n\nnamespace App\\Svc;\n\nclass S%d\n{\n%s    public function fail(): void\n    {\n        throw new Exception\\Oops();\n    }\n}\n", $i, $parameters),
            );
        }
        file_put_contents(
            $this->fixture . '/src/Svc/Exception/Oops.php',
            "<?php\n\nnamespace App\\Svc\\Exception;\n\nclass Oops extends \\RuntimeException {}\n",
        );
    }

    protected function tearDown(): void
    {
        foreach (self::FILES as $file) {
            @unlink($this->fixture . '/src/' . $file);
        }
        @unlink($this->fixture . '/composer.json');

        foreach (['/src/Svc/Exception', '/src/Svc/Handler', '/src/Svc', '/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    #[Test]
    public function itJudgesANamespaceWithASubNamespaceOnItsOwnClasses(): void
    {
        self::assertSame(['App\Svc' => 16.0], self::namespaceFindings($this->check(['src'])));
    }

    #[Test]
    public function itReachesTheSameVerdictWhenTheSubNamespaceIsLeftOutOfTheRun(): void
    {
        self::assertSame(
            self::namespaceFindings($this->check(['src'])),
            self::namespaceFindings($this->check(['src/Svc/S1.php', 'src/Svc/S2.php', 'src/Svc/S3.php'])),
        );
    }

    /**
     * `App\Svc\Handler\H` extends a class of `App\Svc` that does not know
     * it. Left out of the run, its dependency is never read: `App\Svc` is
     * still judged, on one namespace fewer, and the run says it was narrowed.
     */
    #[Test]
    public function itJudgesTheSameNamespaceOnFewerCouplingsWhenADependentIsLeftOutOfTheRun(): void
    {
        mkdir($this->fixture . '/src/Svc/Handler');
        file_put_contents(
            $this->fixture . '/src/Svc/Handler/H.php',
            "<?php\n\nnamespace App\\Svc\\Handler;\n\nclass H extends \\App\\Svc\\S1 {}\n",
        );

        $whole = $this->check(['src']);
        $narrowed = $this->check(['src/Svc/S1.php', 'src/Svc/S2.php', 'src/Svc/S3.php', 'src/Svc/Exception/Oops.php']);

        self::assertSame(['App\Svc' => 17.0], self::namespaceFindings($whole));
        self::assertSame(['App\Svc' => 16.0], self::namespaceFindings($narrowed));
        self::assertSame('covered', $whole['projectScope']['state'] ?? null);
        self::assertSame('narrowed', $narrowed['projectScope']['state'] ?? null);
    }

    /**
     * @param list<string> $paths
     *
     * @return array<mixed> the JSON report
     */
    private function check(array $paths): array
    {
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
                    '--only-rule' => [CboRule::NAME],
                    '--no-cache' => true,
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsList($payload['violations'] ?? null, $tester->getDisplay() . $tester->getErrorOutput());

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, float> the namespace-level findings, by namespace, with the value judged
     */
    private static function namespaceFindings(array $payload): array
    {
        self::assertIsList($payload['violations'] ?? null);

        $findings = [];
        foreach ($payload['violations'] as $violation) {
            self::assertIsArray($violation);
            if (str_starts_with((string) ($violation['subject'] ?? ''), 'declaration:')) {
                continue;
            }
            $findings[(string) $violation['symbol']] = (float) $violation['metricValue'];
        }

        return $findings;
    }
}
