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
        // Five, not seven: Defaults and ComposerJson have no ConfigurationRefusal
        // producer (03-carrier-and-normalization.md §2). Adding a sixth case here
        // is a contract change frozen by this package for stages 01/02.
        self::assertSame(
            ['ConfigFile', 'Preset', 'CommandLine', 'BaselineFile', 'Resolved'],
            array_map(static fn(ConfigurationSource $case): string => $case->name, ConfigurationSource::cases()),
        );
    }
}
