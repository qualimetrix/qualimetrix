<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationMerger;

#[CoversClass(ConfigurationMerger::class)]
final class ConfigurationMergerTest extends TestCase
{
    #[Test]
    public function itOverlaysTopLevelScalarValues(): void
    {
        self::assertSame(
            ['format' => 'json', 'workers' => 4],
            ConfigurationMerger::merge(
                ['format' => 'text', 'workers' => 4],
                ['format' => 'json'],
            ),
        );
    }

    #[Test]
    public function itReplacesListsWithoutApplyingOwnerUnionSemantics(): void
    {
        self::assertSame(
            ['disabled_rules' => ['design']],
            ConfigurationMerger::merge(
                ['disabled_rules' => ['security']],
                ['disabled_rules' => ['design']],
            ),
        );
    }

    #[Test]
    public function itReplacesNestedDocumentsWithoutApplyingFindingSemantics(): void
    {
        $overlay = ['rules' => ['size.loc' => ['threshold' => 1000]]];

        self::assertSame(
            $overlay,
            ConfigurationMerger::merge(
                ['rules' => ['size.loc' => ['warning' => 800, 'error' => 1200]]],
                $overlay,
            ),
        );
    }

    #[Test]
    public function itKeepsBaseKeysThatAreAbsentFromTheOverlay(): void
    {
        self::assertSame(
            ['paths' => ['src'], 'format' => 'json'],
            ConfigurationMerger::merge(['paths' => ['src']], ['format' => 'json']),
        );
    }
}
