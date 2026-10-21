<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\GitProcessEnvironment;
use Symfony\Component\Process\Process;

#[CoversClass(GitProcessEnvironment::class)]
final class SuiteGitEnvironmentTest extends TestCase
{
    #[Test]
    public function itExportsAutomaticMaintenanceConfigurationToEveryProcessChannel(): void
    {
        $count = (int) getenv('GIT_CONFIG_COUNT');
        self::assertGreaterThanOrEqual(2, $count);
        $configuration = [];

        for ($index = 0; $index < $count; ++$index) {
            foreach (['GIT_CONFIG_KEY_' . $index, 'GIT_CONFIG_VALUE_' . $index] as $name) {
                $value = getenv($name);
                self::assertIsString($value);
                self::assertSame($value, $_ENV[$name] ?? null);
                self::assertSame($value, $_SERVER[$name] ?? null);
            }
            $configuration[(string) getenv('GIT_CONFIG_KEY_' . $index)] = (string) getenv('GIT_CONFIG_VALUE_' . $index);
        }

        self::assertSame('false', $configuration['maintenance.auto'] ?? null);
        self::assertSame('0', $configuration['gc.auto'] ?? null);
        self::assertSame((string) $count, $_ENV['GIT_CONFIG_COUNT'] ?? null);
        self::assertSame((string) $count, $_SERVER['GIT_CONFIG_COUNT'] ?? null);

        $child = new Process([
            \PHP_BINARY,
            '-r',
            'echo json_encode([getenv("GIT_CONFIG_COUNT"), getenv("GIT_CONFIG_KEY_0"), getenv("GIT_CONFIG_VALUE_0")], JSON_THROW_ON_ERROR);',
        ]);
        $child->mustRun();
        $inherited = json_decode($child->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame((string) $count, $inherited[0] ?? null);
        self::assertIsString($inherited[1] ?? null);
        self::assertIsString($inherited[2] ?? null);
    }

    #[Test]
    public function itAppendsToExistingGitConfiguration(): void
    {
        $script = <<<'PHP'
            require $argv[1];
            putenv('GIT_CONFIG_COUNT=01');
            putenv('GIT_CONFIG_KEY_0=diff.relative');
            putenv('GIT_CONFIG_VALUE_0=true');
            $_ENV['GIT_CONFIG_COUNT'] = $_SERVER['GIT_CONFIG_COUNT'] = '01';
            $_ENV['GIT_CONFIG_KEY_0'] = $_SERVER['GIT_CONFIG_KEY_0'] = 'diff.relative';
            $_ENV['GIT_CONFIG_VALUE_0'] = $_SERVER['GIT_CONFIG_VALUE_0'] = 'true';
            \Qualimetrix\Subprocess\GitProcessEnvironment::publishForTestProcess();
            $values = [];
            for ($index = 0; $index < (int) getenv('GIT_CONFIG_COUNT'); ++$index) {
                $values[getenv('GIT_CONFIG_KEY_' . $index)] = getenv('GIT_CONFIG_VALUE_' . $index);
            }
            $read = static function (string $key): string {
                $process = new \Symfony\Component\Process\Process(['git', 'config', '--get', $key]);
                $process->mustRun();

                return trim($process->getOutput());
            };
            echo json_encode([$values, $_ENV['GIT_CONFIG_COUNT'], $_SERVER['GIT_CONFIG_COUNT'], $read('diff.relative'), $read('maintenance.auto'), $read('gc.auto')], JSON_THROW_ON_ERROR);
            PHP;
        $child = new Process([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 2) . '/vendor/autoload.php']);
        $child->mustRun();
        [$configuration, $envCount, $serverCount, $relative, $maintenance, $gc] = json_decode($child->getOutput(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([
            'diff.relative' => 'true',
            'maintenance.auto' => 'false',
            'gc.auto' => '0',
        ], $configuration);
        self::assertSame('3', $envCount);
        self::assertSame('3', $serverCount);
        self::assertSame('true', $relative);
        self::assertSame('false', $maintenance);
        self::assertSame('0', $gc);
    }
}
