<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;

#[CoversClass(ConfigurationOrigin::class)]
final class ConfigurationOriginTest extends TestCase
{
    #[Test]
    public function itCarriesTheSourceAndLocatorItWasBuiltWith(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');

        self::assertSame(ConfigurationSource::ConfigFile, $origin->source());
        self::assertSame('qmx.yaml', $origin->locator());
    }

    #[Test]
    public function itDefaultsTheLocatorToNull(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::Resolved);

        self::assertNull($origin->locator());
    }
}
