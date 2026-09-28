<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;

#[CoversClass(ConfigurationSource::class)]
final class ConfigurationSourceTest extends TestCase
{
    /** The backing values are the `kind` the JSON refusal envelope publishes. */
    #[Test]
    public function itPublishesOneKindPerSource(): void
    {
        self::assertSame(
            [
                'Defaults' => 'defaults',
                'ComposerJson' => 'composer',
                'Preset' => 'preset',
                'ConfigFile' => 'file',
                'CommandLine' => 'cli',
                'BaselineFile' => 'baseline',
                'Resolved' => 'resolved',
            ],
            array_combine(
                array_map(static fn(ConfigurationSource $case): string => $case->name, ConfigurationSource::cases()),
                array_map(static fn(ConfigurationSource $case): string => $case->value, ConfigurationSource::cases()),
            ),
        );
    }
}
