<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use Closure;
use Throwable;

final class NativePrivateGroupMembership implements PrivateGroupMembership
{
    private const int MAX_CONFIGURATION_BYTES = 65_536;
    private const int MAX_ENUMERATION_BYTES = 4_194_304;
    private const float ENUMERATION_SECONDS = 2.0;
    private const string GETENT = '/usr/bin/getent';

    /** @var Closure(): (string|false) */
    private Closure $readConfiguration;

    /** @var Closure(string, string): (?array{exitCode: int, output: string}) */
    private Closure $enumerate;

    /** @var Closure(int): (array<string|int, mixed>|false) */
    private Closure $userByUid;

    /** @var Closure(int): (array<string|int, mixed>|false) */
    private Closure $groupByGid;

    /**
     * @param ?Closure(): (string|false) $readConfiguration
     * @param ?Closure(string, string): (?array{exitCode: int, output: string}) $enumerate
     * @param ?Closure(int): (array<string|int, mixed>|false) $userByUid
     * @param ?Closure(int): (array<string|int, mixed>|false) $groupByGid
     */
    public function __construct(
        ?Closure $readConfiguration = null,
        ?Closure $enumerate = null,
        ?Closure $userByUid = null,
        ?Closure $groupByGid = null,
    ) {
        $this->readConfiguration = $readConfiguration ?? static fn(): string|false => self::readNativeConfiguration();
        $this->enumerate = $enumerate ?? self::enumerateNative(...);
        $this->userByUid = $userByUid ?? static fn(int $uid): array|false => \function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        $this->groupByGid = $groupByGid ?? static fn(int $gid): array|false => \function_exists('posix_getgrgid') ? posix_getgrgid($gid) : false;
    }

    public function isPrivatePrimaryGroup(int $effectiveUid, int $groupId): bool
    {
        if ($effectiveUid < 0 || $groupId < 0) {
            return false;
        }

        try {
            $configuration = ($this->readConfiguration)();
            $sources = self::sources($configuration);
            if ($sources === null) {
                return false;
            }

            $user = ($this->userByUid)($effectiveUid);
            $group = ($this->groupByGid)($groupId);
            if ($user === false || $group === false
                || !self::matchingUser($user, $effectiveUid, $groupId)
                || !self::matchingGroup($group, $groupId)) {
                return false;
            }

            $userName = $user['name'];
            $groupName = $group['name'];
            $primaryMembers = [];
            $groupFound = false;
            foreach ($sources['passwd'] as $source) {
                $rows = $this->rows($source, 'passwd');
                if ($rows === null) {
                    return false;
                }
                foreach ($rows as $row) {
                    $account = self::passwdRow($row);
                    if ($account === null) {
                        return false;
                    }
                    if ($account['gid'] === $groupId) {
                        $primaryMembers[] = $account;
                    }
                }
            }
            if (\count($primaryMembers) !== 1
                || $primaryMembers[0]['uid'] !== $effectiveUid
                || $primaryMembers[0]['name'] !== $userName) {
                return false;
            }

            foreach ($sources['group'] as $source) {
                $rows = $this->rows($source, 'group');
                if ($rows === null) {
                    return false;
                }
                foreach ($rows as $row) {
                    $record = self::groupRow($row);
                    if ($record === null) {
                        return false;
                    }
                    if ($record['gid'] !== $groupId) {
                        continue;
                    }
                    $groupFound = true;
                    if ($record['name'] !== $groupName || !self::onlyUser($record['members'], $userName)) {
                        return false;
                    }
                }
            }
            if (!$groupFound || !self::onlyUser($group['members'], $userName)) {
                return false;
            }

            return ($this->readConfiguration)() === $configuration;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return ?array{passwd: list<string>, group: list<string>} */
    private static function sources(string|false $configuration): ?array
    {
        if ($configuration === false || \strlen($configuration) > self::MAX_CONFIGURATION_BYTES) {
            return null;
        }

        $entries = [];
        $lines = preg_split('/\r\n|\r|\n/', $configuration);
        if ($lines === false) {
            return null;
        }
        foreach ($lines as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([a-z][a-z0-9_-]*):\s*(.*)$/i', $line, $match) !== 1) {
                if (preg_match('/^(?:passwd|group|initgroups)\b/i', $line) === 1) {
                    return null;
                }
                continue;
            }
            $database = strtolower($match[1]);
            if (!\in_array($database, ['passwd', 'group', 'initgroups'], true)) {
                continue;
            }
            if (isset($entries[$database])) {
                return null;
            }
            $entries[$database] = preg_replace('/\s+/', ' ', trim($match[2]));
        }

        if (isset($entries['initgroups']) || !isset($entries['passwd'], $entries['group'])) {
            return null;
        }
        $passwd = $entries['passwd'];
        $group = $entries['group'];
        if (!\in_array($passwd, ['files', 'files systemd'], true)
            || !\in_array($group, ['files', 'files systemd', 'files [SUCCESS=merge] systemd'], true)) {
            return null;
        }

        return [
            'passwd' => explode(' ', $passwd),
            'group' => $group === 'files' ? ['files'] : ['files', 'systemd'],
        ];
    }

    /** @return ?list<string> */
    private function rows(string $source, string $database): ?array
    {
        $result = ($this->enumerate)($source, $database);
        if ($result === null || $result['exitCode'] !== 0 || \strlen($result['output']) > self::MAX_ENUMERATION_BYTES) {
            return null;
        }
        if ($result['output'] === '') {
            return [];
        }
        if (!str_ends_with($result['output'], "\n")) {
            return null;
        }

        $rows = explode("\n", substr($result['output'], 0, -1));

        return \in_array('', $rows, true) ? null : $rows;
    }

    /** @return ?array{name: string, uid: int, gid: int} */
    private static function passwdRow(string $row): ?array
    {
        $fields = explode(':', $row);
        if (\count($fields) !== 7 || $fields[0] === '' || !ctype_digit($fields[2]) || !ctype_digit($fields[3])) {
            return null;
        }

        return ['name' => $fields[0], 'uid' => (int) $fields[2], 'gid' => (int) $fields[3]];
    }

    /** @return ?array{name: string, gid: int, members: list<string>} */
    private static function groupRow(string $row): ?array
    {
        $fields = explode(':', $row);
        if (\count($fields) !== 4 || $fields[0] === '' || !ctype_digit($fields[2])) {
            return null;
        }
        $members = $fields[3] === '' ? [] : explode(',', $fields[3]);
        if (\in_array('', $members, true)) {
            return null;
        }

        return ['name' => $fields[0], 'gid' => (int) $fields[2], 'members' => $members];
    }

    /** @param array<string|int, mixed>|false $user */
    private static function matchingUser(array|false $user, int $effectiveUid, int $groupId): bool
    {
        return $user !== false
            && isset($user['name'], $user['uid'], $user['gid'])
            && \is_string($user['name'])
            && $user['name'] !== ''
            && $user['uid'] === $effectiveUid
            && $user['gid'] === $groupId;
    }

    /** @param array<string|int, mixed>|false $group */
    private static function matchingGroup(array|false $group, int $groupId): bool
    {
        return $group !== false
            && isset($group['name'], $group['gid'], $group['members'])
            && \is_string($group['name'])
            && $group['name'] !== ''
            && $group['gid'] === $groupId
            && \is_array($group['members']);
    }

    /** @param list<string> $members */
    private static function onlyUser(array $members, string $userName): bool
    {
        foreach ($members as $member) {
            if ($member !== $userName) {
                return false;
            }
        }

        return true;
    }

    private static function readNativeConfiguration(): string|false
    {
        [$contents] = NativeCall::attempt(static fn(): string|false => file_get_contents('/etc/nsswitch.conf', false, null, 0, self::MAX_CONFIGURATION_BYTES + 1));

        return $contents;
    }

    /** @return ?array{exitCode: int, output: string} */
    private static function enumerateNative(string $source, string $database): ?array
    {
        if (!is_executable(self::GETENT) || !\function_exists('proc_open')) {
            return null;
        }
        $pipes = [];
        [$process] = NativeCall::attempt(static function () use (&$pipes, $source, $database) {
            return proc_open(
                [self::GETENT, '-s', $source, $database],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
        });
        if (!\is_resource($process)) {
            return null;
        }
        if (!isset($pipes[1]) || !\is_resource($pipes[1])) {
            proc_terminate($process, 9);
            proc_close($process);

            return null;
        }

        $stream = $pipes[1];
        $deadline = microtime(true) + self::ENUMERATION_SECONDS;
        $output = '';
        $exitCode = -1;
        $observedExitCode = null;
        try {
            if (!stream_set_blocking($stream, false)) {
                return null;
            }
            do {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    return null;
                }
                $output .= $chunk;
                if (\strlen($output) > self::MAX_ENUMERATION_BYTES) {
                    return null;
                }
                $status = proc_get_status($process);
                if (!$status['running'] && $observedExitCode === null) {
                    $observedExitCode = $status['exitcode'];
                }
                if (!$status['running'] && feof($stream)) {
                    $exitCode = $observedExitCode ?? -1;
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);

            if ($exitCode < 0) {
                return null;
            }

            return ['exitCode' => $exitCode, 'output' => $output];
        } finally {
            if ($exitCode < 0) {
                proc_terminate($process, 9);
            }
            fclose($stream);
            proc_close($process);
        }
    }
}
