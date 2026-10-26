<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use LogicException;
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
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitPolicy;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\ExitPolicySection;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

#[CoversClass(ExitPolicy::class)]
#[CoversClass(ExitPolicySection::class)]
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
    #[DataProvider('provideValuesTheEngineRefuses')]
    public function itTrustsTheEngineToHaveRefusedEveryOtherValue(mixed $configured): void
    {
        $value = self::createStub(ResolvedValueInterface::class);
        $value->method('plain')->willReturn($configured);

        $this->expectException(LogicException::class);

        ExitPolicy::fromResolvedValue($value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideValuesTheEngineRefuses(): iterable
    {
        yield 'a severity instance' => [Severity::Warning];
        yield 'info' => ['info'];
        yield 'an unknown word' => ['warnin'];
        yield 'false' => [false];
    }

    #[Test]
    public function itNamesTheAllowedValuesAtAClosedPosition(): void
    {
        try {
            self::document('warnin');
            self::fail('An unknown threshold was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('must be one of none, warning, error, got "warnin".', $refusal->summary());
            $position = $refusal->position();
            self::assertNotNull($position);
            self::assertSame('warnin', $position->written);
            self::assertSame(ExitPolicy::acceptedWords(), $position->accepted);
            self::assertTrue($position->closed);
        }
    }

    #[Test]
    #[TestWith(['warnin'])]
    #[TestWith(['Info'])]
    #[TestWith([true])]
    public function itExplainsUnknownThresholdsWithoutInfoAdvice(string|bool $configured): void
    {
        try {
            self::document($configured);
            self::fail('An unknown threshold was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringNotContainsString('report-only', $refusal->summary());
            self::assertStringEndsWith('Use "none" to never fail on findings.', $refusal->summary());
        }
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
            self::assertStringContainsString('must be string (one of none, warning, error), got bool', $refusal->summary());
            self::assertStringContainsString('use "none"', $refusal->summary());
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
            self::assertStringContainsString('must be one of none, warning, error', $refusal->summary());
        }
    }

    private static function document(mixed $value): ConfigurationDocument
    {
        return LayeredDocument::of([
            ['source' => 'qmx.yaml', 'values' => [ConfigSchema::FAIL_ON => $value]],
        ], AbsolutePath::fromString('/project'));
    }
}
