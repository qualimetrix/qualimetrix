<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Contract\Refusal;

use PHPUnit\Framework\Attributes\CoversClass;
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
