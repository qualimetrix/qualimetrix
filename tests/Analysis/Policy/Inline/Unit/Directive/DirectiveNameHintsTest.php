<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveNameHints;

#[CoversClass(DirectiveNameHints::class)]
final class DirectiveNameHintsTest extends TestCase
{
    #[Test]
    public function itOrdersEquidistantChannelHintsByCodeRegardlessOfRegistration(): void
    {
        $universe = self::createStubForIntersectionOfInterfaces([
            ChannelIdentityInterface::class,
            ChannelDeclarationRegistryInterface::class,
        ]);
        $universe->method('channels')->willReturn([
            new FindingChannel('sample.z'),
            new FindingChannel('sample.a'),
            new FindingChannel('sample.xx'),
        ]);
        $selector = NameSelector::tryParse('sample.x');
        self::assertNotNull($selector);

        self::assertSame(
            'Addressable names closest to it: sample.a, sample.xx, sample.z.',
            (new DirectiveNameHints($universe))->forChannelSelector($selector),
        );
    }

    #[Test]
    public function itOrdersRuleHintsByDistanceBeforeBreakingTiesByName(): void
    {
        $universe = self::createStubForIntersectionOfInterfaces([
            ChannelIdentityInterface::class,
            ChannelDeclarationRegistryInterface::class,
        ]);
        $universe->method('ruleNames')->willReturn(['sample.z', 'sample.a', 'sample.xxx']);
        $universe->method('producerOf')->willReturn(null);
        $universe->method('staticDeclarations')->willReturn([]);

        self::assertSame(
            'Addressable names closest to it: sample.a, sample.z, sample.xxx.',
            (new DirectiveNameHints($universe))->forRuleName('sample.x'),
        );
    }
}
