<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use Closure;

/** Proves that all selected NSS rosters name only the keyed primary user. */
final class NssPrivateGroupRoster
{
    private const int MAX_ENUMERATION_BYTES = 4_194_304;

    /** @param Closure(string, string): (?array{exitCode: int, output: string}) $enumerate */
    public function __construct(private readonly Closure $enumerate) {}

    /**
     * @param array{passwd: list<string>, group: list<string>} $sources
     * @param list<string> $keyedMembers
     */
    public function provesSoleUser(array $sources, int $effectiveUid, int $groupId, string $userName, string $groupName, array $keyedMembers): bool
    {
        return $this->solePrimary($sources['passwd'], $effectiveUid, $groupId, $userName)
            && $this->soleGroupMember($sources['group'], $groupId, $groupName, $userName)
            && self::onlyUser($keyedMembers, $userName);
    }

    /** @param list<string> $sources */
    private function solePrimary(array $sources, int $effectiveUid, int $groupId, string $userName): bool
    {
        $primaryMembers = [];
        foreach ($sources as $source) {
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

        return \count($primaryMembers) === 1
            && $primaryMembers[0]['uid'] === $effectiveUid
            && $primaryMembers[0]['name'] === $userName;
    }

    /** @param list<string> $sources */
    private function soleGroupMember(array $sources, int $groupId, string $groupName, string $userName): bool
    {
        $groupFound = false;
        foreach ($sources as $source) {
            $rows = $this->rows($source, 'group');
            if ($rows === null) {
                return false;
            }
            foreach ($rows as $row) {
                $matches = self::matchingGroupRow($row, $groupId, $groupName, $userName);
                if ($matches === null) {
                    return false;
                }
                if ($matches) {
                    $groupFound = true;
                }
            }
        }

        return $groupFound;
    }

    private static function matchingGroupRow(string $row, int $groupId, string $groupName, string $userName): ?bool
    {
        $record = self::groupRow($row);
        if ($record === null) {
            return null;
        }
        if ($record['gid'] !== $groupId) {
            return false;
        }

        return $record['name'] === $groupName && self::onlyUser($record['members'], $userName) ? true : null;
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
}
