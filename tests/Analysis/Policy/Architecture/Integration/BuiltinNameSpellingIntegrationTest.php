<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\LayerCriterionNormalizer;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PHP class names are case-insensitive, so `\exception` is `\Exception`, in
 * the source and in a layer criterion alike. Membership must not depend on
 * which spelling either side used: a criterion that the run counts as naming a
 * type it met must also match the classes of that type, or a layer loses them
 * while `architecture.unreachable-layer` is kept quiet by the same name.
 *
 * `App\Web\C` depends on every other class and `web` may depend on nothing, so
 * each edge's violation names the layer its target was assigned to — the
 * witness of membership. `App\Model\Vendored` extends a class the run never
 * read, which is what turned the old miss into a doubt instead of an error.
 */
#[CoversClass(LayerCriterionNormalizer::class)]
#[CoversClass(ClassContextFactory::class)]
final class BuiltinNameSpellingIntegrationTest extends TestCase
{
    private const array CLASSES = [
        'Web/C.php' => "namespace App\\Web;\n\nclass C\n{\n    public function use(\\App\\Model\\Upper \$u, \\App\\Model\\Lower \$l, \\App\\Model\\Items \$i, \\App\\Model\\Vendored \$v): void {}\n}\n",
        'Model/Upper.php' => "namespace App\\Model;\n\nclass Upper extends \\Exception {}\n",
        'Model/Lower.php' => "namespace App\\Model;\n\nclass Lower extends \\runtimeexception {}\n",
        'Model/Items.php' => "namespace App\\Model;\n\nclass Items implements \\iteratoraggregate\n{\n    public function getIterator(): \\Iterator\n    {\n        return new \\ArrayIterator([]);\n    }\n}\n",
        'Model/Vendored.php' => "namespace App\\Model;\n\nclass Vendored extends \\Vendor\\Base {}\n",
    ];

    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-builtin-spelling-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/Web', 0o755, true);
        mkdir($this->fixture . '/src/Model', 0o755, true);

        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );

        foreach (self::CLASSES as $path => $code) {
            file_put_contents($this->fixture . '/src/' . $path, "<?php\n\n" . $code);
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys(self::CLASSES) as $path) {
            @unlink($this->fixture . '/src/' . $path);
        }

        foreach (['/composer.json', '/qmx.yaml'] as $file) {
            @unlink($this->fixture . $file);
        }

        foreach (['/src/Web', '/src/Model', '/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCriterionSpellings(): iterable
    {
        yield 'criteria in the registry spelling' => ['\Exception', '\IteratorAggregate'];
        yield 'criteria in lower case' => ['\exception', '\iteratoraggregate'];
    }

    #[Test]
    #[DataProvider('provideCriterionSpellings')]
    public function itAssignsABuiltinSubtypeWhateverCaseEitherSideSpellsTheName(string $extends, string $implements): void
    {
        $tester = $this->check($extends, $implements);

        self::assertSame(
            [
                'App\Model\Items' => 'agg',
                'App\Model\Lower' => 'errors',
                'App\Model\Upper' => 'errors',
            ],
            $this->targetLayers($tester),
            $tester->getDisplay(),
        );
        self::assertSame([], $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
    }

    /**
     * @return array<string, string> the target of each forbidden edge out of `web`, by the layer it was assigned to
     */
    private function targetLayers(CommandTester $tester): array
    {
        $targets = [];
        foreach ($this->findingsOn($tester, LayerViolationRule::NAME) as $violation) {
            $message = (string) ($violation['message'] ?? '');
            if (preg_match('/^Layer "web" must not depend on layer "([^"]+)" \(App\\\\Web\\\\C → ([^,]+),/u', $message, $matches) !== 1) {
                self::fail('Unexpected layer-violation message: ' . $message);
            }
            $targets[$matches[2]] = $matches[1];
        }
        ksort($targets);

        return $targets;
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

    private function check(string $extends, string $implements): CommandTester
    {
        file_put_contents($this->fixture . '/qmx.yaml', \sprintf(<<<'YAML'
            architecture:
              layers:
                - name: web
                  patterns: ['App\Web\**']
                - name: errors
                  extends: ['%s']
                - name: agg
                  implements: ['%s']
              allow:
                web: []
              coverage-gap: ignore
            YAML, $extends, $implements) . "\n");

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
                    '--fail-on' => 'none',
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
