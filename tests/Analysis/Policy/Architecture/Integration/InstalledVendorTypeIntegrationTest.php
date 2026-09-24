<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Layer\KnownTypes;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A layer criterion naming a type only a vendor chain reaches: the analysed
 * repository extends a vendor base class, the base class implements the
 * vendor interface the layer names, and no analysed code names that interface.
 *
 * The run cannot answer the criterion about the repository — its chain leaves
 * the analysed paths — so what keeps the layer from being called empty is
 * whether the named type is one the run met at all. The analysed project's
 * own composer install places it, read as data, which tells it apart from a
 * mistyped name that nothing places.
 */
#[CoversClass(KnownTypes::class)]
#[CoversClass(LayerDeclarationValidator::class)]
final class InstalledVendorTypeIntegrationTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-installed-vendor-type-' . bin2hex(random_bytes(6));

        $this->write('composer.json', json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR));
        $this->write('vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'vend/orm',
                'install-path' => '../vend/orm',
                'autoload' => ['psr-4' => ['Vend\\Orm\\' => 'src/']],
            ]],
        ], \JSON_THROW_ON_ERROR));
        $this->write('vendor/vend/orm/src/ObjectRepository.php', "<?php\n\nnamespace Vend\\Orm;\n\ninterface ObjectRepository {}\n");
        $this->write('vendor/vend/orm/src/EntityRepository.php', "<?php\n\nnamespace Vend\\Orm;\n\nabstract class EntityRepository implements ObjectRepository {}\n");
        $this->write('vendor/vend/orm/src/ServiceRepository.php', "<?php\n\nnamespace Vend\\Orm;\n\nabstract class ServiceRepository extends EntityRepository {}\n");
        $this->write('src/Repository/UserRepository.php', "<?php\n\nnamespace Sample\\Repository;\n\nuse Vend\\Orm\\ServiceRepository;\n\nfinal class UserRepository extends ServiceRepository {}\n");
        $this->write('src/Web/Controller.php', "<?php\n\nnamespace Sample\\Web;\n\nuse Sample\\Repository\\UserRepository;\n\nfinal class Controller\n{\n    public function __construct(private UserRepository \$users) {}\n}\n");
    }

    protected function tearDown(): void
    {
        self::remove($this->fixture);
    }

    #[Test]
    public function itDoesNotCallALayerEmptyWhenTheInstallDeclaresTheTypeItNames(): void
    {
        $tester = $this->check('Vend\Orm\ObjectRepository');

        self::assertSame([], $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));

        $doubt = $this->findingsOn($tester, LayerViolationRule::DOUBTED_ASSIGNMENT_NAME);
        self::assertCount(1, $doubt, 'The layer kept out of the error is named where the doubt is.');
        self::assertStringContainsString('"repositories"', (string) ($doubt[0]['message'] ?? ''));
    }

    /**
     * The other half: a mistyped name the install does not place is still a
     * layer that matches nothing, and the finding says the install was asked.
     */
    #[Test]
    public function itStillReportsAMistypedTypeTheInstallDoesNotPlace(): void
    {
        $tester = $this->check('Vend\Orm\ObjectRepositry');

        $unreachable = $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('Vend\Orm\ObjectRepositry', (string) ($unreachable[0]['message'] ?? ''));
        self::assertStringContainsString('placed by the analysed project\'s composer install', (string) ($unreachable[0]['message'] ?? ''));
    }

    /** A name that differs only in case is not the declared type. */
    #[Test]
    public function itStillReportsATypeNamedInTheWrongCase(): void
    {
        $tester = $this->check('Vend\Orm\Objectrepository');

        self::assertCount(1, $this->findingsOn($tester, LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
    }

    private function check(string $implements): CommandTester
    {
        $this->write('qmx.yaml', <<<YAML
            architecture:
              layers:
                - name: repositories
                  implements: ['{$implements}']
                - name: app
                  patterns: ['Sample\\**']
              allow:
                app: [repositories]
              coverage-gap: ignore
            YAML . "\n");

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
                    '--no-cache' => true,
                    '--disable-rule' => ['coupling.*'],
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
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

    private function write(string $relative, string $contents): void
    {
        $path = $this->fixture . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o755, true);
        }
        file_put_contents($path, $contents);
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }

        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
