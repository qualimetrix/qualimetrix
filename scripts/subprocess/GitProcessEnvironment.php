<?php

declare(strict_types=1);

namespace Qualimetrix\Subprocess;

use RuntimeException;

/** Git child configuration shared by repository fixtures and tooling processes. */
final class GitProcessEnvironment
{
    /** @var array<string, string> */
    private const AUTOMATIC_MAINTENANCE_DISABLED = [
        'maintenance.auto' => 'false',
        'gc.auto' => '0',
    ];

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    public static function forChild(array $environment): array
    {
        $configuration = self::configuration($environment);
        if (!isset($configuration['GIT_CONFIG_COUNT']) && $configuration !== []) {
            return $configuration;
        }
        $count = self::configurationCount($configuration['GIT_CONFIG_COUNT'] ?? null);
        if ($count === null) {
            return $configuration;
        }

        $effective = [];
        for ($index = 0; $index < $count; ++$index) {
            $keyName = 'GIT_CONFIG_KEY_' . $index;
            $valueName = 'GIT_CONFIG_VALUE_' . $index;
            if (!isset($configuration[$keyName], $configuration[$valueName])) {
                return $configuration;
            }
            $effective[$configuration[$keyName]] = $configuration[$valueName];
        }

        foreach (self::AUTOMATIC_MAINTENANCE_DISABLED as $key => $value) {
            if (($effective[$key] ?? null) === $value) {
                continue;
            }

            $configuration['GIT_CONFIG_KEY_' . $count] = $key;
            $configuration['GIT_CONFIG_VALUE_' . $count] = $value;
            ++$count;
        }

        $configuration['GIT_CONFIG_COUNT'] = (string) $count;

        return $configuration;
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    public static function forTestProcess(array $environment): array
    {
        $configuration = self::configuration($environment);
        if (!isset($configuration['GIT_CONFIG_COUNT']) && $configuration !== []) {
            throw new RuntimeException('Incomplete PHPUnit Git configuration environment: missing GIT_CONFIG_COUNT');
        }
        $count = self::configurationCount($configuration['GIT_CONFIG_COUNT'] ?? null);
        if ($count === null) {
            throw new RuntimeException('Invalid GIT_CONFIG_COUNT in the PHPUnit process environment.');
        }
        for ($index = 0; $index < $count; ++$index) {
            foreach (['GIT_CONFIG_KEY_' . $index, 'GIT_CONFIG_VALUE_' . $index] as $name) {
                if (!isset($configuration[$name])) {
                    throw new RuntimeException('Incomplete PHPUnit Git configuration environment: missing ' . $name);
                }
            }
        }

        return self::forChild($environment);
    }

    public static function publishForTestProcess(): void
    {
        $environment = getenv();
        if ($environment === false) {
            throw new RuntimeException('Cannot read the PHPUnit process environment.');
        }

        foreach (self::forTestProcess($environment) as $name => $value) {
            if (!putenv($name . '=' . $value)) {
                throw new RuntimeException('Cannot publish PHPUnit Git configuration environment: ' . $name);
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    private static function configuration(array $environment): array
    {
        return array_filter(
            $environment,
            static fn(string $name): bool => $name === 'GIT_CONFIG_COUNT'
                || str_starts_with($name, 'GIT_CONFIG_KEY_')
                || str_starts_with($name, 'GIT_CONFIG_VALUE_'),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    private static function configurationCount(?string $value): ?int
    {
        if ($value === null) {
            return 0;
        }
        if (preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }

        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            return 0;
        }
        $maximum = (string) \PHP_INT_MAX;
        if (\strlen($normalized) > \strlen($maximum)
            || (\strlen($normalized) === \strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }
}
