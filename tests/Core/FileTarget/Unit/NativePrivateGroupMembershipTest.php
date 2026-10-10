<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\FileTarget\NativePrivateGroupMembership;

#[CoversClass(NativePrivateGroupMembership::class)]
final class NativePrivateGroupMembershipTest extends TestCase
{
    #[Test]
    public function itAcceptsASolePrimaryUserWithNoSupplementaryMembers(): void
    {
        self::assertTrue(self::membership()->isPrivatePrimaryGroup(1000, 1000));
    }

    #[Test]
    public function itAcceptsTheDocumentedFilesAndSystemdSourcesAfterEnumeratingBoth(): void
    {
        $queried = [];
        $membership = self::membership(
            "passwd: files systemd\ngroup: files [SUCCESS=merge] systemd\n",
            [
                'files:passwd' => self::ownerPasswd(),
                'systemd:passwd' => "dynamic:x:60000:60000::/:/bin/false\n",
                'files:group' => self::ownerGroup(),
                'systemd:group' => "dynamic:x:60000:\n",
            ],
            static function (string $source, string $database) use (&$queried): void {
                $queried[] = $source . ':' . $database;
            },
        );

        self::assertTrue($membership->isPrivatePrimaryGroup(1000, 1000));
        self::assertSame(
            ['files:passwd', 'systemd:passwd', 'files:group', 'systemd:group'],
            $queried,
        );
    }

    #[Test]
    public function itRejectsAnotherPrimaryUserFromTheLaterNssSource(): void
    {
        $membership = self::membership(
            "passwd: files systemd\ngroup: files [SUCCESS=merge] systemd\n",
            [
                'files:passwd' => self::ownerPasswd(),
                'systemd:passwd' => "other:x:1001:1000::/:/bin/false\n",
                'files:group' => self::ownerGroup(),
                'systemd:group' => '',
            ],
        );

        self::assertFalse($membership->isPrivatePrimaryGroup(1000, 1000));
    }

    #[Test]
    public function itRejectsAnotherSupplementaryMemberFromMergedGroup(): void
    {
        $membership = self::membership(
            "passwd: files systemd\ngroup: files [SUCCESS=merge] systemd\n",
            [
                'files:passwd' => self::ownerPasswd(),
                'systemd:passwd' => '',
                'files:group' => self::ownerGroup(),
                'systemd:group' => "owner:x:1000:other\n",
            ],
        );

        self::assertFalse($membership->isPrivatePrimaryGroup(1000, 1000));
    }

    #[Test]
    public function itDoesNotTrustACombinedRosterWhenAnUnknownSourceCouldHideUsers(): void
    {
        $queries = 0;
        $membership = self::membership(
            "passwd: files sss\ngroup: files\n",
            ['files:passwd' => self::ownerPasswd(), 'files:group' => self::ownerGroup()],
            static function () use (&$queries): void {
                ++$queries;
            },
        );

        self::assertFalse($membership->isPrivatePrimaryGroup(1000, 1000));
        self::assertSame(0, $queries);
    }

    #[Test]
    public function itRejectsUnknownActionsAndSeparateInitgroupsSources(): void
    {
        foreach ([
            "passwd: files [NOTFOUND=return] systemd\ngroup: files\n",
            "passwd: files\ngroup: files [SUCCESS=continue] systemd\n",
            "passwd: files\ngroup: files\ninitgroups: sss\n",
            "passwd: files\ngroup: files\ninitgroups: files\n",
            "passwd: files\npasswd: systemd\ngroup: files\n",
        ] as $configuration) {
            self::assertFalse(self::membership($configuration)->isPrivatePrimaryGroup(1000, 1000), $configuration);
        }
    }

    #[Test]
    public function itRejectsAFailedOrPartialSourceEnumeration(): void
    {
        foreach ([
            null,
            ['exitCode' => 3, 'output' => ''],
            ['exitCode' => 0, 'output' => 'owner:x:1000:1000::/:/bin/sh'],
            ['exitCode' => 0, 'output' => self::ownerPasswd() . "\n"],
        ] as $failed) {
            $membership = self::membership(
                "passwd: files\ngroup: files\n",
                ['files:passwd' => $failed, 'files:group' => self::ownerGroup()],
            );
            self::assertFalse($membership->isPrivatePrimaryGroup(1000, 1000));
        }
    }

    #[Test]
    public function itRejectsMalformedAndConflictingNssRecords(): void
    {
        foreach ([
            'files:passwd' => [
                "owner:x:1000:1000\n",
                self::ownerPasswd() . "other:x:1001:1000::/:/bin/sh\n",
            ],
            'files:group' => [
                "owner:x:1000:owner,other\n",
                "owner:x:1000:owner,\n",
            ],
        ] as $key => $malformed) {
            foreach ($malformed as $rows) {
                $membership = self::membership(
                    "passwd: files\ngroup: files\n",
                    array_replace(['files:passwd' => self::ownerPasswd(), 'files:group' => self::ownerGroup()], [$key => $rows]),
                );
                self::assertFalse($membership->isPrivatePrimaryGroup(1000, 1000), $key . ': ' . $rows);
            }
        }
    }

    #[Test]
    public function itRejectsKeyedLookupDisagreementAndChangingConfiguration(): void
    {
        $wrongGroup = self::membership(
            "passwd: files\ngroup: files\n",
            [],
            null,
            ['name' => 'owner', 'gid' => 1001, 'members' => []],
        );
        self::assertFalse($wrongGroup->isPrivatePrimaryGroup(1000, 1000));

        $reads = 0;
        $changed = new NativePrivateGroupMembership(
            static function () use (&$reads): string {
                ++$reads;

                return $reads === 1 ? "passwd: files\ngroup: files\n" : "passwd: files systemd\ngroup: files\n";
            },
            static fn(string $source, string $database): array => [
                'exitCode' => 0,
                'output' => $database === 'passwd' ? self::ownerPasswd() : self::ownerGroup(),
            ],
            static fn(int $uid): array => ['name' => 'owner', 'uid' => $uid, 'gid' => 1000],
            static fn(int $gid): array => ['name' => 'owner', 'gid' => $gid, 'members' => []],
        );
        self::assertFalse($changed->isPrivatePrimaryGroup(1000, 1000));
    }

    /**
     * @param array<string, string|array{exitCode: int, output: string}|null> $records
     * @param ?Closure(string, string): void $observe
     * @param ?array{name: string, gid: int, members: list<string>} $keyedGroup
     */
    private static function membership(
        string $configuration = "passwd: files\ngroup: files\n",
        array $records = [],
        ?Closure $observe = null,
        ?array $keyedGroup = null,
    ): NativePrivateGroupMembership {
        return new NativePrivateGroupMembership(
            static fn(): string => $configuration,
            static function (string $source, string $database) use ($records, $observe): ?array {
                $observe?->__invoke($source, $database);
                $key = $source . ':' . $database;
                $record = \array_key_exists($key, $records)
                    ? $records[$key]
                    : ($database === 'passwd' ? self::ownerPasswd() : self::ownerGroup());

                return \is_string($record) ? ['exitCode' => 0, 'output' => $record] : $record;
            },
            static fn(int $uid): array => ['name' => 'owner', 'uid' => $uid, 'gid' => 1000],
            static fn(int $gid): array => $keyedGroup ?? ['name' => 'owner', 'gid' => $gid, 'members' => []],
        );
    }

    #[Test]
    public function itEnumeratesEachMembershipOnlyOnceIncludingAConservativeRefusal(): void
    {
        foreach ([self::ownerPasswd(), self::ownerPasswd() . "other:x:1001:1000::/:/bin/sh\n"] as $passwd) {
            $queries = [];
            $membership = self::membership(
                records: ['files:passwd' => $passwd],
                observe: static function (string $source, string $database) use (&$queries): void {
                    $queries[] = $source . ':' . $database;
                },
            );
            $expected = $passwd === self::ownerPasswd();
            self::assertSame($expected, $membership->isPrivatePrimaryGroup(1000, 1000));
            $first = $queries;
            self::assertNotEmpty($first);
            self::assertSame($expected, $membership->isPrivatePrimaryGroup(1000, 1000));
            self::assertSame($first, $queries, 'The same membership proof must not enumerate NSS again.');
            self::assertFalse($membership->isPrivatePrimaryGroup(1001, 1000));
            self::assertFalse($membership->isPrivatePrimaryGroup(1000, 1001));
        }
    }

    private static function ownerPasswd(): string
    {
        return "owner:x:1000:1000::/srv/owner:/bin/sh\n";
    }

    private static function ownerGroup(): string
    {
        return "owner:x:1000:\n";
    }
}
