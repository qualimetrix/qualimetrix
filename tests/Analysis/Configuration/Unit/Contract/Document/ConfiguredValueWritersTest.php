<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Document;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfiguredValueWriters;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use ReflectionClass;

#[CoversClass(ConfiguredValueWriters::class)]
final class ConfiguredValueWritersTest extends TestCase
{
    #[Test]
    public function itNamesOneWriter(): void
    {
        self::assertSame('Written by configuration file "qmx.yaml".', (new ConfiguredValueWriters([self::file('qmx.yaml')]))->sentence());
    }

    #[Test]
    public function itJoinsTwoWritersWithAnd(): void
    {
        $writers = new ConfiguredValueWriters([self::file('a.yaml'), self::file('b.yaml')]);

        self::assertSame('Written by configuration file "a.yaml" and configuration file "b.yaml".', $writers->sentence());
    }

    #[Test]
    public function itSeparatesThreeWritersWithCommasAndAnd(): void
    {
        $writers = new ConfiguredValueWriters([self::file('a.yaml'), self::file('b.yaml'), self::file('c.yaml')]);

        self::assertSame('Written by configuration file "a.yaml", configuration file "b.yaml" and configuration file "c.yaml".', $writers->sentence());
    }

    #[Test]
    public function itNamesTheCommandLineAndAnImportersChain(): void
    {
        $imported = self::file('vendor/acme/std/qmx.yaml')->importedThrough(self::file('qmx.yaml'));
        $cli = ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--exclude');

        self::assertSame(
            'Written by configuration file "vendor/acme/std/qmx.yaml" (imported by configuration file "qmx.yaml") and option --exclude.',
            (new ConfiguredValueWriters([$imported, $cli]))->sentence(),
        );
    }

    #[Test]
    public function itRefusesNoWriters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReflectionClass(ConfiguredValueWriters::class))->newInstance([]);
    }

    #[Test]
    public function itRefusesTheSameWriterTwiceEvenAsAnotherInstance(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConfiguredValueWriters([self::file('a.yaml'), self::file('a.yaml')]);
    }

    #[Test]
    public function itKeepsOneOriginPerWriterInOrderWhenBuiltFromProvenance(): void
    {
        $writers = ConfiguredValueWriters::fromProvenance([
            new Provenance(self::file('b.yaml'), ['exclude', '0'], 1),
            new Provenance(self::file('a.yaml'), ['exclude', '1'], 2),
            new Provenance(self::file('b.yaml'), ['exclude', '2'], 1),
        ]);

        self::assertEquals([self::file('b.yaml'), self::file('a.yaml')], $writers->origins);
    }

    #[Test]
    public function itUnitesWritersKeepingOneEntryPerOrigin(): void
    {
        $united = (new ConfiguredValueWriters([self::file('a.yaml'), self::file('b.yaml')]))
            ->with(new ConfiguredValueWriters([self::file('b.yaml'), self::file('c.yaml')]));

        self::assertEquals([self::file('a.yaml'), self::file('b.yaml'), self::file('c.yaml')], $united->origins);
    }

    private static function file(string $path): ConfigurationOrigin
    {
        return ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $path);
    }
}
