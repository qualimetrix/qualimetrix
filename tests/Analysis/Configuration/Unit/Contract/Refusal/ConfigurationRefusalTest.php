<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use RuntimeException;

#[CoversClass(ConfigurationRefusal::class)]
final class ConfigurationRefusalTest extends TestCase
{
    #[Test]
    public function itCanBeCaughtAsARuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Selector is not a valid glob.');

        throw ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--selector'),
            'Selector is not a valid glob.',
        );
    }

    /**
     * The per-source shorthands are the form every literal-source throw site
     * uses, so what each one puts in `origin()` and `position()` is the whole
     * behaviour a caller depends on.
     *
     * @param callable(RefusedPosition): ConfigurationRefusal $build
     */
    #[Test]
    #[DataProvider('providePositionedShorthands')]
    public function itAddressesAPositionedShorthandToItsOwnSource(
        callable $build,
        ConfigurationSource $source,
        ?string $locator,
    ): void {
        $position = RefusedPosition::open(['rules', 'complexity'], 'complexity');

        $refusal = $build($position);

        self::assertSame($source, $refusal->origin()->source());
        self::assertSame($locator, $refusal->origin()->locator());
        self::assertSame($position, $refusal->position());
        self::assertSame('Refused.', $refusal->summary());
    }

    /**
     * @return iterable<string, array{callable(RefusedPosition): ConfigurationRefusal, ConfigurationSource, string|null}>
     */
    public static function providePositionedShorthands(): iterable
    {
        yield 'config file' => [
            static fn(RefusedPosition $p): ConfigurationRefusal
                => ConfigurationRefusal::atConfigFileKey('qmx.yaml', $p, 'Refused.'),
            ConfigurationSource::ConfigFile,
            'qmx.yaml',
        ];
        yield 'preset' => [
            static fn(RefusedPosition $p): ConfigurationRefusal
                => ConfigurationRefusal::atPresetKey('strict', $p, 'Refused.'),
            ConfigurationSource::Preset,
            'strict',
        ];
        yield 'resolved without a key' => [
            static fn(RefusedPosition $p): ConfigurationRefusal
                => ConfigurationRefusal::atResolvedKey($p, 'Refused.'),
            ConfigurationSource::Resolved,
            null,
        ];
        yield 'resolved with a diagnostic key' => [
            static fn(RefusedPosition $p): ConfigurationRefusal
                => ConfigurationRefusal::atResolvedKey($p, 'Refused.', 'fail_on'),
            ConfigurationSource::Resolved,
            'fail_on',
        ];
    }

    /**
     * @param callable(): ConfigurationRefusal $build
     */
    #[Test]
    #[DataProvider('providePositionlessShorthands')]
    public function itLeavesAPositionlessShorthandWithoutAPosition(
        callable $build,
        ConfigurationSource $source,
        ?string $locator,
    ): void {
        $refusal = $build();

        self::assertSame($source, $refusal->origin()->source());
        self::assertSame($locator, $refusal->origin()->locator());
        self::assertNull($refusal->position());
        self::assertSame('Refused.', $refusal->summary());
    }

    /**
     * @return iterable<string, array{callable(): ConfigurationRefusal, ConfigurationSource, string|null}>
     */
    public static function providePositionlessShorthands(): iterable
    {
        yield 'config file document' => [
            static fn(): ConfigurationRefusal => ConfigurationRefusal::aboutConfigFileDocument('qmx.yaml', 'Refused.'),
            ConfigurationSource::ConfigFile,
            'qmx.yaml',
        ];
        yield 'preset document' => [
            static fn(): ConfigurationRefusal => ConfigurationRefusal::aboutPresetDocument('strict', 'Refused.'),
            ConfigurationSource::Preset,
            'strict',
        ];
        yield 'baseline document' => [
            static fn(): ConfigurationRefusal
                => ConfigurationRefusal::aboutBaselineFileDocument('qmx-baseline.json', 'Refused.'),
            ConfigurationSource::BaselineFile,
            'qmx-baseline.json',
        ];
        yield 'document named by an argument' => [
            static fn(): ConfigurationRefusal => ConfigurationRefusal::aboutCommandLineDocument('map', 'Refused.'),
            ConfigurationSource::CommandLine,
            'map',
        ];
        yield 'command-line input' => [
            static fn(): ConfigurationRefusal => ConfigurationRefusal::aboutCommandLineInput('--format', 'Refused.'),
            ConfigurationSource::CommandLine,
            '--format',
        ];
        yield 'resolved input without a key' => [
            static fn(): ConfigurationRefusal => ConfigurationRefusal::aboutResolvedInput('Refused.'),
            ConfigurationSource::Resolved,
            null,
        ];
        yield 'resolved input with a diagnostic key' => [
            static fn(): ConfigurationRefusal => ConfigurationRefusal::aboutResolvedInput('Refused.', 'memory_limit'),
            ConfigurationSource::Resolved,
            'memory_limit',
        ];
    }

    #[Test]
    public function itCarriesThePreviousExceptionThroughAShorthand(): void
    {
        $cause = new RuntimeException('cause');

        $refusal = ConfigurationRefusal::aboutResolvedInput('Refused.', null, $cause);

        self::assertSame($cause, $refusal->getPrevious());
    }

    #[Test]
    public function itBuildsAPositionedRefusalFromAt(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
        $position = RefusedPosition::closed(['rules'], 'compexity', ['complexity']);

        $refusal = ConfigurationRefusal::at($origin, $position, 'Unknown rule name.');

        self::assertSame($origin, $refusal->origin());
        self::assertSame($position, $refusal->position());
        self::assertSame('Unknown rule name.', $refusal->summary());
        self::assertSame('Unknown rule name.', $refusal->getMessage());
    }

    #[Test]
    public function itBuildsADocumentRefusalWithoutAPosition(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');

        $refusal = ConfigurationRefusal::aboutDocument($origin, 'File is not valid YAML.');

        self::assertSame($origin, $refusal->origin());
        self::assertNull($refusal->position());
        self::assertSame('File is not valid YAML.', $refusal->summary());
    }

    #[Test]
    public function itBuildsAnInputRefusalWithoutAPosition(): void
    {
        $origin = ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--baseline');

        $refusal = ConfigurationRefusal::aboutInput($origin, 'Baseline path does not exist.');

        self::assertSame($origin, $refusal->origin());
        self::assertNull($refusal->position());
        self::assertSame('Baseline path does not exist.', $refusal->summary());
    }

    #[Test]
    public function itCarriesThePreviousExceptionForDiagnostics(): void
    {
        $previous = new RuntimeException('YAML parse error at line 14.');

        $refusal = ConfigurationRefusal::aboutDocument(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'),
            'File is not valid YAML.',
            $previous,
        );

        self::assertSame($previous, $refusal->getPrevious());
    }
}
