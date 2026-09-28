<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\Coupling\Configuration\CouplingSection;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

#[CoversClass(CouplingSection::class)]
final class CouplingSectionTest extends TestCase
{
    #[Test]
    public function itKeepsALowerFrameworkListWhenAnUpperCouplingMapIsEmpty(): void
    {
        $document = LayeredDocument::of([
            ['source' => 'preset:strict', 'values' => ['coupling' => ['frameworkNamespaces' => [['subtree' => 'Symfony']]]]],
            ['source' => 'qmx.yaml', 'values' => ['coupling' => []]],
        ], AbsolutePath::fromString('/project'), new CouplingSection());

        self::assertSame(
            [['subtree' => 'Symfony']],
            $document->resolved()->get(CouplingSection::KEY, 'framework_namespaces')?->plain(),
        );
    }

    #[Test]
    public function itRefusesAShadowedSelectorInTheLayerThatWroteIt(): void
    {
        try {
            LayeredDocument::of([
                ['source' => 'preset:broken', 'values' => ['coupling' => ['frameworkNamespaces' => [true]]]],
                ['source' => 'qmx.yaml', 'values' => ['coupling' => ['frameworkNamespaces' => [['subtree' => 'Symfony']]]]],
            ], AbsolutePath::fromString('/project'), new CouplingSection());
            self::fail('A selector written with the wrong shape must be refused before a later layer replaces it.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(ConfigurationSource::Preset, $refusal->origin()->source());
            self::assertSame('preset:broken', $refusal->origin()->locator());
            self::assertSame(['coupling', 'frameworkNamespaces', '0'], $refusal->position()?->segments);
        }
    }
}
