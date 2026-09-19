<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\LayerPolicyVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;

/**
 * Split off from `LayerDefinitionTest` (which keeps the behavioural
 * matching cases): the closed vocabulary itself, pinned against the enum.
 */
#[CoversClass(MatchMode::class)]
final class MatchModeVocabularyTest extends TestCase
{
    #[Test]
    public function itHasOnlyAnyAndAllCases(): void
    {
        self::assertSame(
            ['any', 'all'],
            array_map(static fn(MatchMode $mode): string => $mode->value, MatchMode::cases()),
        );
    }
}
