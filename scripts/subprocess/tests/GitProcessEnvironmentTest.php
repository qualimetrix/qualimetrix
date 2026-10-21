<?php

declare(strict_types=1);

namespace Qualimetrix\Subprocess\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\GitProcessEnvironment;
use RuntimeException;

require_once \dirname(__DIR__) . '/GitProcessEnvironment.php';

final class GitProcessEnvironmentTest extends TestCase
{
    #[Test]
    public function itAppendsAutomaticMaintenanceOverridesAfterForeignConfiguration(): void
    {
        $configuration = GitProcessEnvironment::forChild([
            'GIT_CONFIG_COUNT' => '01',
            'GIT_CONFIG_KEY_0' => 'maintenance.auto',
            'GIT_CONFIG_VALUE_0' => 'true',
            'GIT_CONFIG_KEY_unusual' => 'foreign.unindexed',
        ]);

        self::assertSame([
            'GIT_CONFIG_COUNT' => '3',
            'GIT_CONFIG_KEY_0' => 'maintenance.auto',
            'GIT_CONFIG_VALUE_0' => 'true',
            'GIT_CONFIG_KEY_unusual' => 'foreign.unindexed',
            'GIT_CONFIG_KEY_1' => 'maintenance.auto',
            'GIT_CONFIG_VALUE_1' => 'false',
            'GIT_CONFIG_KEY_2' => 'gc.auto',
            'GIT_CONFIG_VALUE_2' => '0',
        ], $configuration);
    }

    #[Test]
    public function itDoesNotDuplicateEffectiveAutomaticMaintenanceOverrides(): void
    {
        self::assertSame([
            'GIT_CONFIG_COUNT' => '3',
            'GIT_CONFIG_KEY_0' => 'foreign.setting',
            'GIT_CONFIG_VALUE_0' => 'foreign-value',
            'GIT_CONFIG_KEY_1' => 'maintenance.auto',
            'GIT_CONFIG_VALUE_1' => 'false',
            'GIT_CONFIG_KEY_2' => 'gc.auto',
            'GIT_CONFIG_VALUE_2' => '0',
            'GIT_CONFIG_KEY_9' => 'orphan.setting',
            'GIT_CONFIG_VALUE_9' => 'preserved',
        ], GitProcessEnvironment::forChild([
            'GIT_CONFIG_COUNT' => '3',
            'GIT_CONFIG_KEY_0' => 'foreign.setting',
            'GIT_CONFIG_VALUE_0' => 'foreign-value',
            'GIT_CONFIG_KEY_1' => 'maintenance.auto',
            'GIT_CONFIG_VALUE_1' => 'false',
            'GIT_CONFIG_KEY_2' => 'gc.auto',
            'GIT_CONFIG_VALUE_2' => '0',
            'GIT_CONFIG_KEY_9' => 'orphan.setting',
            'GIT_CONFIG_VALUE_9' => 'preserved',
        ]));
    }

    #[Test]
    public function itTransportsMalformedConfigurationWithoutRepairingItForAChild(): void
    {
        $malformed = [
            'GIT_CONFIG_COUNT' => 'not-a-count',
            'GIT_CONFIG_KEY_unusual' => 'section.key',
            'GIT_CONFIG_VALUE_unusual' => 'value',
            'GIT_CONFIG_KEY_9' => 'orphan.key',
        ];

        self::assertSame($malformed, GitProcessEnvironment::forChild($malformed));
        self::assertSame(
            ['GIT_CONFIG_KEY_0' => 'orphan.key'],
            GitProcessEnvironment::forChild(['GIT_CONFIG_KEY_0' => 'orphan.key']),
        );
    }

    #[Test]
    public function itRefusesMalformedConfigurationForTheTestProcess(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid GIT_CONFIG_COUNT');

        GitProcessEnvironment::forTestProcess(['GIT_CONFIG_COUNT' => 'not-a-count']);
    }
}
