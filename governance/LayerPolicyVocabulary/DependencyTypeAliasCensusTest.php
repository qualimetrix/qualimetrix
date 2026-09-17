<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\LayerPolicyVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowAliasExpander;

/**
 * Split off from `AllowAliasExpanderTest` (which keeps the hand-picked
 * alias/token cases): every existing {@see DependencyType} case must
 * round-trip through the expander unchanged. This is the mechanism that
 * keeps the user-facing surface in sync with the collector: when a new case
 * is added to the enum, this data-provider grows automatically and the test
 * will fail loudly if the expander accidentally hard-codes the accepted
 * list instead of consulting {@see DependencyType::cases()}.
 */
#[CoversClass(AllowAliasExpander::class)]
final class DependencyTypeAliasCensusTest extends TestCase
{
    #[Test]
    #[DataProvider('everyDependencyTypeCase')]
    public function itAcceptsEveryDependencyTypeCaseAsADirectToken(DependencyType $case): void
    {
        $result = AllowAliasExpander::expand([$case->value], 'architecture.allow.app[0]');

        self::assertSame([$case], $result);
    }

    /**
     * @return iterable<string, array{DependencyType}>
     */
    public static function everyDependencyTypeCase(): iterable
    {
        foreach (DependencyType::cases() as $case) {
            yield $case->value => [$case];
        }
    }
}
