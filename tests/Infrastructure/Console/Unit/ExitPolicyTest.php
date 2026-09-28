<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\ExitPolicy;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

#[CoversClass(ExitPolicy::class)]
final class ExitPolicyTest extends TestCase
{
    /**
     * A `fail_on: info` inherited from an older config must be named as no
     * longer supported rather than silently read as "fail on everything".
     */
    #[Test]
    public function itRejectsInfoAsAFailOnThreshold(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('report-only');

        ExitPolicy::fromResolvedValue(self::document('info')->resolved()->get(ConfigSchema::FAIL_ON));
    }

    #[Test]
    public function itRefusesToBeConstructedWithInfoAtAll(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        new ExitPolicy(Severity::Info);
    }

    #[Test]
    #[DataProvider('provideAcceptedValues')]
    public function itAcceptsTheRemainingThresholds(mixed $configured, Severity|false|null $expected): void
    {
        self::assertSame($expected, ExitPolicy::fromResolvedValue(self::document($configured)->resolved()->get(ConfigSchema::FAIL_ON))->failOn);
    }

    /** @return iterable<string, array{mixed, Severity|false|null}> */
    public static function provideAcceptedValues(): iterable
    {
        yield 'none' => ['none', false];
        yield 'warning' => ['warning', Severity::Warning];
        yield 'error' => ['error', Severity::Error];
        yield 'unset' => [null, null];
    }

    #[Test]
    public function itNamesTheAllowedValuesWhenRejectingAnUnknownWord(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Allowed values: none, warning, error');

        ExitPolicy::fromResolvedValue(self::document('warnin')->resolved()->get(ConfigSchema::FAIL_ON));
    }

    #[Test]
    public function itKeepsTheProgrammaticNeverFailValue(): void
    {
        self::assertFalse((new ExitPolicy(false))->failOn);
    }

    #[Test]
    public function itUsesTheWinningThreshold(): void
    {
        $document = LayeredDocument::of([
            ['source' => 'lower.yaml', 'values' => [ConfigSchema::FAIL_ON => 'warning']],
            ['source' => 'cli', 'values' => [ConfigSchema::FAIL_ON => 'error']],
        ], AbsolutePath::fromString('/project'));

        self::assertSame(Severity::Error, ExitPolicy::fromResolvedValue($document->resolved()->get(ConfigSchema::FAIL_ON))->failOn);
    }

    private static function document(mixed $value): ConfigurationDocument
    {
        return LayeredDocument::of([
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::FAIL_ON => $value]],
        ], AbsolutePath::fromString('/project'));
    }
}
