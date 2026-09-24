<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerShadowing;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Layer loading accepts a pattern repeated behind a layer that does not take
 * every class it names — the carve-out behind an `exclude:`, and the residue
 * behind a `match: all` layer. A configuration it loads must not then fail
 * every run with `architecture.potential-shadow` for that repetition alone,
 * while a layer that really cannot win in its own area is still reported.
 */
#[CoversClass(LayerShadowing::class)]
final class RepeatedPatternShadowIntegrationTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-repeated-pattern-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/Repository', 0o755, true);

        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        foreach (['/src/Repository/PlainRepo.php', '/src/Repository/UserRepo.php', '/composer.json', '/qmx.yaml'] as $file) {
            @unlink($this->fixture . $file);
        }

        foreach (['/src/Repository', '/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /** The carve-out exactly as the documentation writes it. */
    #[Test]
    public function itDoesNotReportTheRecipientOfACarveOutAsShadowed(): void
    {
        $this->writeRepositories(withDoctrineSubclass: true);

        $tester = $this->check(<<<'YAML'
            architecture:
              layers:
                - name: repo-plain
                  patterns: ['App\Repository\**']
                  exclude:
                    extends: ['Doctrine\ORM\EntityRepository']
                - name: repo-doctrine
                  patterns: ['App\Repository\**']
            YAML);

        self::assertSame([], $this->findingsOn($tester, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
    }

    #[Test]
    public function itDoesNotReportTheLayerReceivingTheResidueOfAMatchAllLayer(): void
    {
        $this->writeRepositories(withDoctrineSubclass: true);

        $tester = $this->check(<<<'YAML'
            architecture:
              layers:
                - name: repo-doctrine
                  match: all
                  patterns: ['App\Repository\**']
                  extends: ['Doctrine\ORM\EntityRepository']
                - name: repo-plain
                  patterns: ['App\Repository\**']
            YAML);

        self::assertSame([], $this->findingsOn($tester, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
    }

    /**
     * The trace a carve-out that removes nothing still leaves: its recipient
     * owns no class, and that is reported, not hidden with the shadow.
     */
    #[Test]
    public function itStillReportsARecipientTheCarveOutGivesNothing(): void
    {
        $this->writeRepositories(withDoctrineSubclass: false);

        $tester = $this->check(<<<'YAML'
            architecture:
              layers:
                - name: repo-plain
                  patterns: ['App\Repository\**']
                  exclude:
                    extends: ['Doctrine\ORM\EntityRepository']
                - name: repo-doctrine
                  patterns: ['App\Repository\**']
            YAML);

        $unreachable = $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable, $tester->getDisplay());
        self::assertStringContainsString('Layer "repo-doctrine"', (string) ($unreachable[0]['message'] ?? ''));
    }

    /**
     * An `exclude:` makes room only for the pattern it repeats: a narrower
     * layer behind a broad one still never wins the classes the broad one
     * keeps.
     */
    #[Test]
    public function itStillReportsANarrowerLayerBehindABroadLayerWithAnExclude(): void
    {
        $this->writeRepositories(withDoctrineSubclass: false);

        $tester = $this->check(<<<'YAML'
            architecture:
              layers:
                - name: app
                  patterns: ['App\**']
                  exclude:
                    patterns: ['App\Legacy\**']
                - name: repository
                  patterns: ['App\Repository\**']
            YAML);

        $shadows = $this->findingsOn($tester, LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME);
        self::assertCount(1, $shadows, $tester->getDisplay());
        self::assertStringContainsString('shadows layer "repository"', (string) ($shadows[0]['message'] ?? ''));
    }

    private function writeRepositories(bool $withDoctrineSubclass): void
    {
        file_put_contents(
            $this->fixture . '/src/Repository/PlainRepo.php',
            "<?php\n\nnamespace App\\Repository;\n\nclass PlainRepo\n{\n    public function find(): void {}\n}\n",
        );

        if ($withDoctrineSubclass) {
            file_put_contents(
                $this->fixture . '/src/Repository/UserRepo.php',
                "<?php\n\nnamespace App\\Repository;\n\nclass UserRepo extends \\Doctrine\\ORM\\EntityRepository\n{\n    public function find(): void {}\n}\n",
            );
        }
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

    private function check(string $yaml): CommandTester
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
                    'paths' => ['src'],
                    '--workers' => '0',
                    '--format' => 'json',
                    '--only-rule' => ['architecture.*'],
                    '--no-cache' => true,
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}
