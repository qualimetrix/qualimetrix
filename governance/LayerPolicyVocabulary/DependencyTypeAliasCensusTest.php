<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\LayerPolicyVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyRelationTokens;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * Split off from `DependencyRelationTokensTest` (which keeps the hand-picked
 * alias/token cases): every existing {@see DependencyType} case must
 * round-trip through the expander unchanged. This is the mechanism that
 * keeps the user-facing surface in sync with the collector: when a new case
 * is added to the enum, this data-provider grows automatically and the test
 * will fail loudly if the expander accidentally hard-codes the accepted
 * list instead of consulting {@see DependencyType::cases()}.
 */
#[CoversClass(DependencyRelationTokens::class)]
final class DependencyTypeAliasCensusTest extends TestCase
{
    #[Test]
    #[DataProvider('everyDependencyTypeCase')]
    public function itAcceptsEveryDependencyTypeCaseAsADirectToken(DependencyType $case): void
    {
        $result = DependencyRelationTokens::expand($case->value);

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
