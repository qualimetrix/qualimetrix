<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Architecture\Validation\LayersValidator;
use Qualimetrix\Configuration\Exception\ConfigLoadException;

#[CoversClass(LayersValidator::class)]
final class LayersValidatorTest extends TestCase
{
    private LayersValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LayersValidator();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyInputProducesEmptyRegistry(): void
    {
        $registry = $this->validator->validate([]);

        self::assertTrue($registry->isEmpty());
        self::assertSame([], $registry->layerNames());
    }

    #[Test]
    public function nullInputProducesEmptyRegistry(): void
    {
        $registry = $this->validator->validate(null);

        self::assertTrue($registry->isEmpty());
    }

    #[Test]
    public function singleLayerWithListPatternRegistersAllPatterns(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service', 'App\\Domain\\Service']],
        ]);

        $definitions = $registry->definitions();
        self::assertCount(1, $definitions);
        self::assertSame('service', $definitions[0]->name());
        self::assertSame(['App\\Service', 'App\\Domain\\Service'], $definitions[0]->patterns());
    }

    #[Test]
    public function layersListPreservesDeclarationOrder(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'zebra', 'patterns' => ['App\\Zebra']],
            ['name' => 'alpha', 'patterns' => ['App\\Alpha']],
            ['name' => 'beta', 'patterns' => ['App\\Beta']],
        ]);

        // NOT sorted — declaration order is preserved through the registry.
        self::assertSame(['zebra', 'alpha', 'beta'], $registry->layerNames());
    }

    // -------------------------------------------------------------------------
    // Layer-list shape validation
    // -------------------------------------------------------------------------

    #[Test]
    public function legacyMapShapeForLayersIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/ordered list of layer entries/');

        // Legacy map shape ('layer-name' => pattern) is no longer accepted.
        $this->validator->validate(['controller' => 'App\\Controller']);
    }

    #[Test]
    public function singleKeyMapShorthandForLayerEntryIsRejected(): void
    {
        // ADR 0006 explicitly rejects the `- controller: 'App\Controller\**'`
        // shorthand. Only the long form (`- name: ... patterns: [...]`) is accepted.
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unknown key\(s\) "controller"/');

        $this->validator->validate([
            ['controller' => 'App\\Controller\\**'],
        ]);
    }

    #[Test]
    public function layersAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers');

        $this->validator->validate('App\\Controller');
    }

    // -------------------------------------------------------------------------
    // Per-entry validation
    // -------------------------------------------------------------------------

    #[Test]
    public function layerEntryWithoutNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing or empty "name"/');

        $this->validator->validate([
            ['patterns' => ['App\\Controller']],
        ]);
    }

    #[Test]
    public function layerEntryWithEmptyNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing or empty "name"/');

        $this->validator->validate([
            ['name' => '', 'patterns' => ['App\\Controller']],
        ]);
    }

    #[Test]
    public function layerEntryNameAsNonStringIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing or empty "name"/');

        $this->validator->validate([
            ['name' => 42, 'patterns' => ['App\\Controller']],
        ]);
    }

    #[Test]
    public function layerEntryAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/each entry must be a map/');

        $this->validator->validate(['just-a-string']);
    }

    #[Test]
    public function layerEntryAsListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/each entry must be a map/');

        $this->validator->validate([
            [0 => 'foo', 1 => 'bar'],
        ]);
    }

    #[Test]
    public function unknownKeyOnLayerEntryIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unknown key/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => ['App\\Controller'], 'unexpected' => 'foo'],
        ]);
    }

    #[Test]
    public function layerEntryWithoutPatternsIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing "patterns"/');

        $this->validator->validate([
            ['name' => 'controller'],
        ]);
    }

    #[Test]
    public function layerEntryWithEmptyPatternsListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/"patterns" must contain at least one entry/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => []],
        ]);
    }

    #[Test]
    public function layerEntryWithPatternsAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/"patterns" must be a non-empty list of strings/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => 'App\\Controller'],
        ]);
    }

    #[Test]
    public function layerEntryWithPatternsAsMapIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/"patterns" must be a non-empty list of strings/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => ['foo' => 'App\\Controller']],
        ]);
    }

    #[Test]
    public function emptyPatternStringInsideListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/non-empty string/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => ['App\\Controller', '']],
        ]);
    }

    #[Test]
    public function nonStringPatternInsideListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/non-empty string/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => ['App\\Controller', 42]],
        ]);
    }

    #[Test]
    public function invalidLayerNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/UpperCaseName/');

        $this->validator->validate([
            ['name' => 'UpperCaseName', 'patterns' => ['App\\Foo']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Cross-entry validation
    // -------------------------------------------------------------------------

    #[Test]
    public function duplicateLayerNameAcrossListEntriesIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/duplicate layer name "service"/');

        $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service']],
            ['name' => 'service', 'patterns' => ['App\\OtherService']],
        ]);
    }

    #[Test]
    public function duplicatePatternAcrossLayersIsRejected(): void
    {
        try {
            $this->validator->validate([
                ['name' => 'a', 'patterns' => ['App\\Shared']],
                ['name' => 'b', 'patterns' => ['App\\Shared']],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('App\\Shared', $e->getMessage());
            self::assertStringContainsString('"a"', $e->getMessage());
            self::assertStringContainsString('"b"', $e->getMessage());
            self::assertStringContainsString('unreachable', $e->getMessage());
        }
    }

    #[Test]
    public function duplicateWildcardPatternAcrossLayersIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/App\\\\\\*\\*/');

        $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\**']],
            ['name' => 'b', 'patterns' => ['App\\**']],
        ]);
    }

    #[Test]
    public function duplicatePatternWithTrailingBackslashIsTreatedAsDuplicate(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unreachable/');

        // 'App\\Service' and 'App\\Service\\' are normalized identically
        $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Service']],
            ['name' => 'b', 'patterns' => ['App\\Service\\']],
        ]);
    }

    #[Test]
    public function samePatternWithinOneLayerIsNotADuplicate(): void
    {
        // Cross-layer duplicates are rejected; within-layer repetition is allowed.
        $registry = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared', 'App\\Shared']],
        ]);

        self::assertSame(['a'], $registry->layerNames());
    }

    #[Test]
    public function configPathIsArchitectureForAllErrors(): void
    {
        try {
            $this->validator->validate('bad');
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }
}
