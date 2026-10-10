<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

use Closure;
use Throwable;

final class NativePrivateGroupMembership implements PrivateGroupMembership
{
    /** @var array<int, array<int, bool>> */
    private array $answers = [];

    public static function forProcess(): self
    {
        static $owner = null;
        static $membership = null;
        $pid = getmypid();
        if ($owner !== $pid || $membership === null) {
            $owner = $pid;
            $membership = new self();
        }

        return $membership;
    }

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
        $this->enumerate = $enumerate ?? NativeNssEnumerator::enumerate(...);
        $this->userByUid = $userByUid ?? static fn(int $uid): array|false => \function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
        $this->groupByGid = $groupByGid ?? static fn(int $gid): array|false => \function_exists('posix_getgrgid') ? posix_getgrgid($gid) : false;
    }

    public function isPrivatePrimaryGroup(int $effectiveUid, int $groupId): bool
    {
        return $this->answers[$effectiveUid][$groupId] ??= $this->prove($effectiveUid, $groupId);
    }

    private function prove(int $effectiveUid, int $groupId): bool
    {
        if ($effectiveUid < 0 || $groupId < 0) {
            return false;
        }

        try {
            $configuration = ($this->readConfiguration)();
            $sources = NssSourceSelection::fromConfiguration($configuration);
            if ($sources === null) {
                return false;
            }

            $user = ($this->userByUid)($effectiveUid);
            $group = ($this->groupByGid)($groupId);
            if ($user === false || $group === false || !self::keyedRecordsMatch($user, $group, $effectiveUid, $groupId)) {
                return false;
            }

            $roster = new NssPrivateGroupRoster($this->enumerate);
            if (!$roster->provesSoleUser($sources, $effectiveUid, $groupId, $user['name'], $group['name'], $group['members'])) {
                return false;
            }

            return ($this->readConfiguration)() === $configuration;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string|int, mixed>|false $user
     * @param array<string|int, mixed>|false $group
     */
    private static function keyedRecordsMatch(array|false $user, array|false $group, int $effectiveUid, int $groupId): bool
    {
        return self::matchingUser($user, $effectiveUid, $groupId)
            && self::matchingGroup($group, $groupId);
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

    private static function readNativeConfiguration(): string|false
    {
        [$contents] = NativeCall::attempt(static fn(): string|false => file_get_contents('/etc/nsswitch.conf', false, null, 0, NssSourceSelection::MAX_CONFIGURATION_BYTES + 1));

        return $contents;
    }
}
