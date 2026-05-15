<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\CoverageMode;
use Qualimetrix\Configuration\Architecture\Validation\CoverageValidator;
use Qualimetrix\Configuration\Exception\ConfigLoadException;

#[CoversClass(CoverageValidator::class)]
final class CoverageValidatorTest extends TestCase
{
    private CoverageValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CoverageValidator();
    }

    #[Test]
    public function nullDefaultsToIgnore(): void
    {
        self::assertSame(CoverageMode::Ignore, $this->validator->validate(null));
    }

    #[Test]
    public function ignoreIsParsed(): void
    {
        self::assertSame(CoverageMode::Ignore, $this->validator->validate('ignore'));
    }

    #[Test]
    public function warnIsParsed(): void
    {
        self::assertSame(CoverageMode::Warn, $this->validator->validate('warn'));
    }

    #[Test]
    public function errorIsParsed(): void
    {
        self::assertSame(CoverageMode::Error, $this->validator->validate('error'));
    }

    #[Test]
    public function coverageIsCaseInsensitive(): void
    {
        self::assertSame(CoverageMode::Error, $this->validator->validate('ERROR'));
        self::assertSame(CoverageMode::Warn, $this->validator->validate('Warn'));
    }

    #[Test]
    public function unknownCoverageValueIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.coverage');

        $this->validator->validate('verbose');
    }

    #[Test]
    public function coverageOfWrongTypeIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.coverage');

        $this->validator->validate(42);
    }

    #[Test]
    public function coverageOfBoolTypeIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/got bool/');

        $this->validator->validate(true);
    }

    #[Test]
    public function configPathIsArchitectureForAllErrors(): void
    {
        try {
            $this->validator->validate('verbose');
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }
}
