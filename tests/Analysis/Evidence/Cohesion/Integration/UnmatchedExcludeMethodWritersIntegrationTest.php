<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomExcludedMethods;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The finding names the sources that wrote `exclude-methods`, through the real
 * command: the carrier is built from the configuration document, and a
 * `LcomOptions` built by hand has no writers to name.
 */
#[CoversClass(LcomExcludedMethods::class)]
#[CoversClass(LcomOptions::class)]
final class UnmatchedExcludeMethodWritersIntegrationTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-unmatched-exclude-method-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->fixture . '/src/Service.php', <<<'PHP'
            <?php

            namespace Sample;

            class Service
            {
                public function run(): int
                {
                    return 1;
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['/src/Service.php', '/composer.json', '/qmx.yaml'] as $file) {
            @unlink($this->fixture . $file);
        }
        foreach (['/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    #[Test]
    public function itNamesTheConfigurationFileThatWroteTheList(): void
    {
        $messages = $this->messages($this->check("rules:\n  cohesion.lcom:\n    exclude-methods: [nope]\n"));

        self::assertCount(1, $messages);
        self::assertStringEndsWith('matched no method declared in this project. Written by configuration file "qmx.yaml".', $messages[0]);
    }

    /** @return list<string> */
    private function messages(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $violations = $payload['violations'] ?? null;
        self::assertIsList($violations);

        $messages = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            if (($violation['rule'] ?? null) === 'cohesion.unmatched-exclude-method') {
                $messages[] = (string) ($violation['message'] ?? '');
            }
        }

        return $messages;
    }

    private function check(string $yaml): CommandTester
    {
        file_put_contents($this->fixture . '/qmx.yaml', $yaml);

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
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}
