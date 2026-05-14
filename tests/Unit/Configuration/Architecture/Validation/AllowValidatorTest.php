<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Architecture\Validation\AllowValidator;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;

#[CoversClass(AllowValidator::class)]
#[CoversClass(DeferredWarning::class)]
final class AllowValidatorTest extends TestCase
{
    private AllowValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AllowValidator();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyAllowProducesEmptyMap(): void
    {
        $warnings = [];
        $result = $this->validator->validate([], ['controller'], $warnings);

        self::assertSame([], $result);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function nullAllowProducesEmptyMap(): void
    {
        $warnings = [];
        $result = $this->validator->validate(null, ['controller'], $warnings);

        self::assertSame([], $result);
    }

    #[Test]
    public function singleSourceAndTargetIsParsed(): void
    {
        $warnings = [];
        $result = $this->validator->validate(
            ['controller' => ['service']],
            ['controller', 'service'],
            $warnings,
        );

        self::assertSame(['controller' => ['service']], $result);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function nullTargetsProducesEmptyList(): void
    {
        $warnings = [];
        $result = $this->validator->validate(
            ['controller' => null],
            ['controller'],
            $warnings,
        );

        self::assertSame(['controller' => []], $result);
    }

    // -------------------------------------------------------------------------
    // Self-reference and dedup
    // -------------------------------------------------------------------------

    #[Test]
    public function selfReferenceIsSilentlyStripped(): void
    {
        $warnings = [];
        $result = $this->validator->validate(
            ['controller' => ['controller', 'service']],
            ['controller', 'service'],
            $warnings,
        );

        // 'controller' should not appear in its own explicit target list.
        self::assertSame(['controller' => ['service']], $result);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function duplicateTargetsAreDeduplicated(): void
    {
        $warnings = [];
        $result = $this->validator->validate(
            ['a' => ['b', 'b']],
            ['a', 'b'],
            $warnings,
        );

        self::assertSame(['a' => ['b']], $result);
    }

    // -------------------------------------------------------------------------
    // Long-form allow entries
    // -------------------------------------------------------------------------

    #[Test]
    public function longFormAllowEntryWithoutTypesIsAcceptedSilently(): void
    {
        $warnings = [];
        $result = $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service'],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );

        self::assertSame(['controller' => ['service']], $result);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function longFormAllowEntryWithTypesEmitsDeprecationWarning(): void
    {
        $warnings = [];
        $result = $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'types' => ['method_call']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );

        self::assertSame(['controller' => ['service']], $result);
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]->level);
        self::assertStringContainsString("'types' filter declared but not yet enforced", $warnings[0]->message);
        self::assertStringContainsString('architecture.allow.controller', $warnings[0]->message);
    }

    #[Test]
    public function longFormAllowEntryWithoutTargetKeyIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            [
                'controller' => [
                    ['types' => ['method_call']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function longFormAllowEntryWithEmptyTargetIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => ''],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    // -------------------------------------------------------------------------
    // Shape validation
    // -------------------------------------------------------------------------

    #[Test]
    public function allowAsSequentialListIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->validator->validate(['a', 'b'], ['a'], $warnings);
    }

    #[Test]
    public function allowAsScalarIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->validator->validate('wrong', ['a'], $warnings);
    }

    #[Test]
    public function allowKeyReferencingUnknownLayerIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller: unknown layer');

        $this->validator->validate(
            ['controller' => ['service']],
            ['service'],
            $warnings,
        );
    }

    #[Test]
    public function allowTargetReferencingUnknownLayerIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("architecture.allow.controller[0]: unknown layer 'servise'");

        $this->validator->validate(
            ['controller' => ['servise']],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function allowTargetsAsScalarIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller');

        $this->validator->validate(
            ['controller' => 'service'],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function emptyTargetStringIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            ['controller' => ['']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function configPathIsArchitectureForAllErrors(): void
    {
        $warnings = [];

        try {
            $this->validator->validate('wrong', ['a'], $warnings);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }
}
