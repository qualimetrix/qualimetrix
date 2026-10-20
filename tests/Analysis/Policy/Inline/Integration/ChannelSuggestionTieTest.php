<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveNameHints;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

#[CoversClass(DirectiveNameHints::class)]
final class ChannelSuggestionTieTest extends TestCase
{
    #[Test]
    public function itOrdersTiedRealChannelSuggestionsByCode(): void
    {
        $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
        self::assertInstanceOf(ChannelUniverseInterface::class, $universe);
        $selector = NameSelector::tryParse('design.type-coverage.propurn');
        self::assertNotNull($selector);

        self::assertSame(
            'Addressable names closest to it: design.type-coverage.property, design.type-coverage.return.',
            (new DirectiveNameHints($universe))->forChannelSelector($selector),
        );
    }
}
