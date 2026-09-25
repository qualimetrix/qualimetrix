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
        self::assertNull($origin->importer());
    }

    #[Test]
    public function itDefaultsTheLocatorToNull(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::Resolved);

        self::assertNull($origin->locator());
    }

    #[Test]
    public function itKeepsTheImporterOfAnImportedFile(): void
    {
        $importer = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');

        $imported = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'shared.yaml')->importedThrough($importer);

        self::assertSame($importer, $imported->importer());
        self::assertSame(
            'configuration file "shared.yaml" (imported by configuration file "qmx.yaml")',
            $imported->describe(),
        );
    }

    #[Test]
    public function itNarrowsTheLocatorAndKeepsTheImporter(): void
    {
        $importer = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
        $origin = ConfigurationOrigin::of(ConfigurationSource::CommandLine)->importedThrough($importer);

        $option = $origin->locatedAt('--fail-on');

        self::assertSame('--fail-on', $option->locator());
        self::assertSame($importer, $option->importer());
        self::assertSame(ConfigurationSource::CommandLine, $option->source());
    }

    #[Test]
    public function itDescribesEverySourceKind(): void
    {
        self::assertSame('the built-in defaults', ConfigurationOrigin::of(ConfigurationSource::Defaults)->describe());
        self::assertSame('composer.json', ConfigurationOrigin::of(ConfigurationSource::ComposerJson)->describe());
        self::assertSame('preset "strict"', ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict')->describe());
        self::assertSame('configuration file "qmx.yaml"', ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')->describe());
        self::assertSame('the command line', ConfigurationOrigin::of(ConfigurationSource::CommandLine)->describe());
        self::assertSame('option --fail-on', ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--fail-on')->describe());
        self::assertSame('baseline file "b.json"', ConfigurationOrigin::of(ConfigurationSource::BaselineFile, 'b.json')->describe());
        self::assertSame('the merged configuration', ConfigurationOrigin::of(ConfigurationSource::Resolved)->describe());
    }
}
