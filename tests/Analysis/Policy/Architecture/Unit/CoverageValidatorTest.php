<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageValidator;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ArchitectureDocument;

#[CoversClass(CoverageValidator::class)]
final class CoverageValidatorTest extends TestCase
{
    private CoverageValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CoverageValidator();
    }

    #[Test]
    public function itDefaultsToIgnoreWhenValueIsNull(): void
    {
        self::assertSame(CoverageMode::Ignore, $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => null], 'coverage-gap')));
    }

    #[Test]
    public function itParsesIgnore(): void
    {
        self::assertSame(CoverageMode::Ignore, $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => 'ignore'], 'coverage-gap')));
    }

    #[Test]
    public function itParsesWarn(): void
    {
        self::assertSame(CoverageMode::Warn, $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => 'warn'], 'coverage-gap')));
    }

    #[Test]
    public function itParsesError(): void
    {
        self::assertSame(CoverageMode::Error, $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => 'error'], 'coverage-gap')));
    }

    #[Test]
    public function itParsesCoverageValuesCaseInsensitively(): void
    {
        self::assertSame(CoverageMode::Error, $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => 'ERROR'], 'coverage-gap')));
        self::assertSame(CoverageMode::Warn, $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => 'Warn'], 'coverage-gap')));
    }

    #[Test]
    public function itRejectsAnUnknownCoverageValue(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.coverage-gap');

        $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => 'verbose'], 'coverage-gap'));
    }

    #[Test]
    public function itRejectsACoverageValueOfTheWrongType(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.coverage-gap');

        $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => 42], 'coverage-gap'));
    }

    #[Test]
    public function itRejectsABooleanCoverageValue(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessageMatches('/got bool/');

        $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => true], 'coverage-gap'));
    }

    #[Test]
    public function itNamesTheWritingFileForEveryError(): void
    {
        try {
            $this->validator->validate(ArchitectureDocument::spot(['coverage-gap' => 'verbose'], 'coverage-gap'));
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertCount(1, $e->sources());
            self::assertSame(ConfigurationSource::ConfigFile, $e->sources()[0]->source());
            self::assertSame(ArchitectureDocument::FILE, $e->sources()[0]->locator());
        }
    }
}
