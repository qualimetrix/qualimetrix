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
 * The verdict of `coupling.cbo` on a namespace must not depend on what else
 * the run holds. `App\Svc` declares three classes coupled to fifteen
 * namespaces and has one sub-namespace, `App\Svc\Exception`, holding a single
 * class. Whether the sub-namespace is in the run decides whether `App\Svc` is
 * a region of one namespace or of two; the coupling of the classes `App\Svc`
 * declares is the same either way, and so is the finding.
 */
#[CoversClass(CboRule::class)]
final class NamespaceCboScopeIntegrationTest extends TestCase
{
    private const array FILES = ['Svc/S1.php', 'Svc/S2.php', 'Svc/S3.php', 'Svc/Exception/Oops.php'];

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

        foreach (['/src/Svc/Exception', '/src/Svc', '/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    #[Test]
    public function itJudgesANamespaceWithASubNamespaceOnItsOwnClasses(): void
    {
        self::assertSame(['App\Svc' => 16.0], $this->namespaceFindings(['src']));
    }

    #[Test]
    public function itReachesTheSameVerdictWhenTheSubNamespaceIsLeftOutOfTheRun(): void
    {
        self::assertSame(
            $this->namespaceFindings(['src']),
            $this->namespaceFindings(['src/Svc/S1.php', 'src/Svc/S2.php', 'src/Svc/S3.php']),
        );
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, float> the namespace-level findings, by namespace, with the value judged
     */
    private function namespaceFindings(array $paths): array
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
