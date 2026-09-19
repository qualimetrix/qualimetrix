<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\Channel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Split off from `ChannelLevelSelectorTest` (which keeps the hand-built
 * parsing cases): the whole level vocabulary and nothing else, read from the
 * enum so a sixth level is covered the day it is added.
 */
#[CoversClass(ChannelLevelSelector::class)]
final class ChannelLevelSelectorVocabularyTest extends TestCase
{
    #[Test]
    public function itParsesEveryLevelOfTheVocabulary(): void
    {
        foreach (SymbolLevel::cases() as $level) {
            $selector = ChannelLevelSelector::tryParse('demo.rule:' . $level->value);

            self::assertNotNull($selector, $level->value);
            self::assertSame($level, $selector->level());
        }
    }
}
