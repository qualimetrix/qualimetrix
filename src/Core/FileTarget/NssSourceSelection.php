<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

/** The NSS source grammar for a complete private-primary-group proof. */
final class NssSourceSelection
{
    public const int MAX_CONFIGURATION_BYTES = 65_536;

    /** @return ?array{passwd: list<string>, group: list<string>} */
    public static function fromConfiguration(string|false $configuration): ?array
    {
        if ($configuration === false || \strlen($configuration) > self::MAX_CONFIGURATION_BYTES) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $configuration);
        if ($lines === false) {
            return null;
        }
        $entries = self::entries($lines);
        if ($entries === null || isset($entries['initgroups']) || !isset($entries['passwd'], $entries['group'])) {
            return null;
        }

        return self::acceptedSources($entries['passwd'], $entries['group']);
    }

    /**
     * @param list<string> $lines
     *
     * @return ?array<string, string>
     */
    private static function entries(array $lines): ?array
    {
        $entries = [];
        foreach ($lines as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '') {
                continue;
            }
            $parsed = self::relevantLine($line);
            if ($parsed === false) {
                return null;
            }
            if ($parsed === null) {
                continue;
            }
            [$database, $sources] = $parsed;
            if (isset($entries[$database])) {
                return null;
            }
            $normal = preg_replace('/\s+/', ' ', trim($sources));
            if ($normal === null) {
                return null;
            }
            $entries[$database] = $normal;
        }

        return $entries;
    }

    /** @return array{string, string}|false|null False means a malformed relevant line. */
    private static function relevantLine(string $line): array|false|null
    {
        if (preg_match('/^([a-z][a-z0-9_-]*):\s*(.*)$/i', $line, $match) !== 1) {
            return preg_match('/^(?:passwd|group|initgroups)\b/i', $line) === 1 ? false : null;
        }
        $database = strtolower($match[1]);

        return \in_array($database, ['passwd', 'group', 'initgroups'], true)
            ? [$database, $match[2]]
            : null;
    }

    /** @return ?array{passwd: list<string>, group: list<string>} */
    private static function acceptedSources(string $passwd, string $group): ?array
    {
        if (!\in_array($passwd, ['files', 'files systemd'], true)
            || !\in_array($group, ['files', 'files systemd', 'files [SUCCESS=merge] systemd'], true)) {
            return null;
        }

        return [
            'passwd' => explode(' ', $passwd),
            'group' => $group === 'files' ? ['files'] : ['files', 'systemd'],
        ];
    }
}
