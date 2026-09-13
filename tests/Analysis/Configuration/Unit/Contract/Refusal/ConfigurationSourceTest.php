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
    #[Test]
    public function itHasExactlyFiveCases(): void
    {
        // Defaults and ComposerJson do not produce ConfigurationRefusal.
        // Adding a sixth case would change the source contract.
        self::assertSame(
            ['ConfigFile', 'Preset', 'CommandLine', 'BaselineFile', 'Resolved'],
            array_map(static fn(ConfigurationSource $case): string => $case->name, ConfigurationSource::cases()),
        );
    }
}
