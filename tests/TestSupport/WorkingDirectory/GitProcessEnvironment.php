<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\TestSupport\WorkingDirectory;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Test-process Git configuration inherited by every repository fixture. */
final class GitProcessEnvironment
{
    /** @var array<string, string> */
    private const AUTOMATIC_MAINTENANCE_DISABLED = [
        'maintenance.auto' => 'false',
        'gc.auto' => '0',
    ];

    public static function disableAutomaticMaintenance(): void
    {
        $count = self::configurationCount();
        $effective = [];

        for ($index = 0; $index < $count; ++$index) {
            $keyName = 'GIT_CONFIG_KEY_' . $index;
            $valueName = 'GIT_CONFIG_VALUE_' . $index;
            $key = self::requiredValue($keyName);
            $value = self::requiredValue($valueName);
            self::publish($keyName, $key);
            self::publish($valueName, $value);
            $effective[$key] = $value;
        }

        foreach (self::AUTOMATIC_MAINTENANCE_DISABLED as $key => $value) {
            if (($effective[$key] ?? null) === $value) {
                continue;
            }

            self::publish('GIT_CONFIG_KEY_' . $count, $key);
            self::publish('GIT_CONFIG_VALUE_' . $count, $value);
            ++$count;
        }

        self::publish('GIT_CONFIG_COUNT', (string) $count);
    }

    private static function configurationCount(): int
    {
        $value = getenv('GIT_CONFIG_COUNT');
        if ($value === false) {
            return 0;
        }

        (new Process(['git', 'config', '--list', '--null']))->mustRun();

        return (int) $value;
    }

    private static function requiredValue(string $name): string
    {
        $value = getenv($name);
        if ($value === false) {
            throw new RuntimeException('Incomplete PHPUnit Git configuration environment: missing ' . $name);
        }

        return $value;
    }

    private static function publish(string $name, string $value): void
    {
        if (!putenv($name . '=' . $value)) {
            throw new RuntimeException('Cannot publish PHPUnit Git configuration environment: ' . $name);
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
