<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Version;

#[CoversClass(ProductIdentity::class)]
final class ProductIdentityTest extends TestCase
{
    #[Test]
    public function itReturnsTheDocsUrl(): void
    {
        self::assertSame('https://qualimetrix.dev', ProductIdentity::docsUrl());
    }

    #[Test]
    public function itReturnsTheLlmsTxtUrlUnderTheDocsUrl(): void
    {
        self::assertSame('https://qualimetrix.dev/llms.txt', ProductIdentity::llmsTxtUrl());
    }

    /**
     * The site serves clean URLs, so a page's `.md` source becomes a trailing
     * slash; a path outside `rules/` is addressed from the same root.
     */
    #[Test]
    public function itAddressesADocumentationPageByItsCleanUrl(): void
    {
        self::assertSame('https://qualimetrix.dev/rules/complexity/', ProductIdentity::docsPageUrl('rules/complexity.md'));
        self::assertSame(
            'https://qualimetrix.dev/reference/health-scores/',
            ProductIdentity::docsPageUrl('reference/health-scores.md'),
        );
    }

    #[Test]
    public function itReturnsExactlyTheDesignedPointerLine(): void
    {
        self::assertSame(
            'Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt',
            ProductIdentity::pointerText(),
        );
    }

    /**
     * `pointerText()` is plain text by contract: dimming is an Ansi concern
     * owned by Reporting, so a styled return here would put a presentation
     * decision in the wrong module.
     */
    #[Test]
    public function itCarriesNoConsoleMarkupInThePointerText(): void
    {
        self::assertStringNotContainsString('<', ProductIdentity::pointerText());
    }

    /**
     * `identity()` deliberately omits `timestamp`: a timestamp is a fact
     * about a run, not about the product. Asserting the exact key set guards
     * against one creeping back in.
     */
    #[Test]
    public function itReturnsIdentityWithExactlyTheDesignedKeys(): void
    {
        self::assertSame(
            ['version', 'package', 'docs', 'llmsTxt'],
            array_keys(ProductIdentity::identity()),
        );
    }

    #[Test]
    public function itReturnsIdentityFieldsConsistentWithTheirOwnAccessors(): void
    {
        $identity = ProductIdentity::identity();

        self::assertSame(Version::get(), $identity['version']);
        self::assertSame('qmx', $identity['package']);
        self::assertSame(ProductIdentity::docsUrl(), $identity['docs']);
        self::assertSame(ProductIdentity::llmsTxtUrl(), $identity['llmsTxt']);
    }
}
