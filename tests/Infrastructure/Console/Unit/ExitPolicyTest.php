<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
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
    #[DataProvider('provideProgrammaticValues')]
    public function itAcceptsProgrammaticResolvedValues(mixed $configured, Severity|false $expected): void
    {
        self::assertSame($expected, ExitPolicy::fromResolvedValue(self::resolvedValue($configured))->failOn);
    }

    /** @return iterable<string, array{mixed, Severity|false}> */
    public static function provideProgrammaticValues(): iterable
    {
        yield 'warning severity' => [Severity::Warning, Severity::Warning];
    }

    #[Test]
    #[DataProvider('provideInvalidValues')]
    public function itPreservesTheAuthorWhenTheFactoryRefusesAnInvalidValue(mixed $configured, string $rejected): void
    {
        try {
            ExitPolicy::fromResolvedValue(self::resolvedValue($configured));
            self::fail('Invalid fail_on values must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertStringContainsString($rejected, $refusal->getMessage());
        }
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function provideInvalidValues(): iterable
    {
        yield 'scalar false' => [false, 'Invalid value'];
        yield 'scalar true' => [true, '"1"'];
        yield 'non-scalar array' => [[], 'array'];
    }

    #[Test]
    public function itNamesTheAllowedValuesWhenRejectingAnUnknownWord(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Allowed values: none, warning, error');

        ExitPolicy::fromResolvedValue(self::document('warnin')->resolved()->get(ConfigSchema::FAIL_ON));
    }

    #[Test]
    public function itRefusesAuthoredFalseAndNamesItsFile(): void
    {
        try {
            self::document(false);
            self::fail('The retired boolean spelling of fail_on was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['fail_on'], $refusal->position()?->segments);
            self::assertStringContainsString('must be string, got bool', $refusal->summary());
        }
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

    #[Test]
    #[TestWith(['warnin'])]
    #[TestWith(['info'])]
    public function itRefusesAnInvalidThresholdEvenWhenTheCommandLineOverridesIt(string $threshold): void
    {
        try {
            LayeredDocument::of([
                ['source' => 'qmx.yaml', 'values' => [ConfigSchema::FAIL_ON => $threshold]],
                ['source' => 'cli', 'values' => [ConfigSchema::FAIL_ON => 'error']],
            ], AbsolutePath::fromString('/project'));
            self::fail('The command line hid an invalid fail_on threshold.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame(['fail_on'], $refusal->position()?->segments);
            self::assertStringContainsString('Allowed values: none, warning, error', $refusal->summary());
        }
    }

    private static function document(mixed $value): ConfigurationDocument
    {
        return LayeredDocument::of([
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::FAIL_ON => $value]],
        ], AbsolutePath::fromString('/project'));
    }

    private static function resolvedValue(mixed $plain): ResolvedValueInterface
    {
        $value = self::createStub(ResolvedValueInterface::class);
        $value->method('plain')->willReturn($plain);
        $value->method('refuse')->willReturnCallback(
            static fn(string $summary): never => throw ConfigurationRefusal::aboutConfigFileDocument('qmx.yaml', $summary),
        );

        return $value;
    }
}
