<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Selection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Selection\RuleEnablementResolver;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(RuleEnablementResolver::class)]
final class RuleEnablementRoleTest extends TestCase
{
    #[Test]
    public function itAdmitsAnExplicitlyEnabledAddressedDiagnosticOutsideTheDirectFilter(): void
    {
        $channel = new FindingChannel('annotation.invalid-threshold');
        $enabled = self::resolve('annotation.validator', $channel, ChannelSelectionRole::FollowsAddressedRule);

        self::assertTrue($enabled->runs('annotation.validator'));
        self::assertTrue($enabled->publishes($channel, SymbolLevel::Project, 'complexity.alpha'));
        self::assertFalse($enabled->publishes($channel, SymbolLevel::Project, null));
        self::assertFalse($enabled->publishes($channel, SymbolLevel::Project, 'complexity.beta'));
        self::assertFalse($enabled->decisionFor('annotation.validator')->direct);
    }

    #[Test]
    public function itKeepsASyntheticFilterExemptCellLiveOutsideTheDirectFilter(): void
    {
        $channel = new FindingChannel('diagnostic.synthetic');
        $enabled = self::resolve('diagnostic.validator', $channel, ChannelSelectionRole::FilterExempt);

        self::assertTrue($enabled->runs('diagnostic.validator'));
        self::assertTrue($enabled->publishes($channel, SymbolLevel::Project));
        self::assertFalse($enabled->decisionFor('diagnostic.validator')->direct);
    }

    #[Test]
    public function itCountsADirectlyFilteredDiagnosticCellAsANonemptySelection(): void
    {
        $channel = new FindingChannel('annotation.invalid-threshold');
        try {
            $enabled = self::resolve('annotation.validator', $channel, ChannelSelectionRole::FollowsAddressedRule, 'annotation.invalid-threshold');
        } catch (ConfigurationRefusal $refusal) {
            self::fail('A filter directly selecting its live diagnostic channel must not be empty: ' . $refusal->getMessage());
        }

        self::assertTrue($enabled->decisionFor('annotation.validator')->direct);
        self::assertTrue($enabled->runs('annotation.validator'));
        self::assertTrue($enabled->publishes($channel, SymbolLevel::Project));
    }

    private static function resolve(string $producer, FindingChannel $channel, ChannelSelectionRole $role, string $only = 'complexity.alpha'): \Qualimetrix\Analysis\Finding\Contract\RuleEnablement
    {
        $metadata = [
            new RuleMetadata('complexity.alpha', CodeSmellOptions::class, 'Alpha', [], false),
            new RuleMetadata($producer, CodeSmellOptions::class, 'Diagnostic', [], false),
        ];
        $universe = new ChannelUniverse([
            'complexity.alpha' => ChannelDeclaration::occurrence(SymbolLevel::Project),
            $channel->code => ChannelDeclaration::occurrence(SymbolLevel::Project)->selectedAs($role),
        ], [
            'complexity.alpha' => ['complexity.alpha'],
            $producer => [$channel->code],
        ], [
            'complexity.alpha' => false,
            $producer => false,
        ], new ResolvedComputedMetricDefinitions([]));
        $document = ResolvedOptionsFixture::document([
            ['source' => 'config', 'values' => [
                'rules' => [$producer => ['enabled' => true]],
                'only_rules' => [$only],
            ]],
        ], AbsolutePath::fromString('/project'), $metadata);
        $ready = ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata, channels: $universe);
        return $ready->enablement ?? self::fail('The final enablement must be available.');
    }
}
