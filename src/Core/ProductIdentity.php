<?php

declare(strict_types=1);

namespace Qualimetrix\Core;

/**
 * Where to find the product's documentation, and the one line that points an
 * agent at it.
 *
 * Placed beside {@see Version}, the existing precedent for product identity in
 * `Core`: a fact about the product's address on the web, not about any
 * capability, so no capability owns it. Kept separate from {@see Version}
 * rather than merged into it: a version number and a documentation address are
 * two facts from two sources, not one fact with two fields.
 *
 * @qmx-threshold coupling.cbo 23 -- Every output channel reads its documentation address from here
 *                instead of spelling it, so each channel that points at the documentation is one
 *                more inbound edge by design — including the HTML report, whose HtmlTreeBuilder
 *                reads it into report-data for the footer. The class has no dependency of its own
 *                beyond Version. Raw CBO 22 gets one-edge headroom from the inclusive threshold of 23.
 */
final class ProductIdentity
{
    // Every published document already names the tool 'qmx', not the Composer
    // package 'qualimetrix/qualimetrix' — this states the fact the documents
    // publish, not the installable unit.
    private const PACKAGE = 'qmx';

    private const DOCS_URL = 'https://qualimetrix.dev';

    private const LLMS_TXT_PATH = '/llms.txt';

    public static function docsUrl(): string
    {
        return self::DOCS_URL;
    }

    public static function llmsTxtUrl(): string
    {
        return self::DOCS_URL . self::LLMS_TXT_PATH;
    }

    /**
     * The published address of one documentation page, given its source path
     * under `website/docs/` (`rules/complexity.md`).
     *
     * The `.md` suffix becomes a trailing slash because the site serves clean
     * URLs. That routing is a property of the site at this address, so it
     * lives beside the address: a move to a host with different routing
     * changes both in one place.
     */
    public static function docsPageUrl(string $docsPage): string
    {
        return self::DOCS_URL . '/' . preg_replace('/\.md$/', '/', $docsPage);
    }

    /**
     * Plain text, no markup. Dimming is an Ansi concern owned by Reporting;
     * a `Core` value that promised a styled line would put a presentation
     * decision in the wrong module. Each consuming channel styles this text
     * itself, if it chooses to.
     *
     * The "AI agents:" label is load-bearing, not decorative: it is what
     * makes an agent fetch the second address instead of skimming past a
     * generic docs link.
     */
    public static function pointerText(): string
    {
        return \sprintf('Docs: %s · AI agents: %s', self::docsUrl(), self::llmsTxtUrl());
    }

    /**
     * Omits `timestamp` deliberately: a timestamp is a fact about a run, not
     * about the product, and every channel that publishes one already obtains
     * it its own way.
     *
     * @return array{version: string, package: string, docs: string, llmsTxt: string}
     */
    public static function identity(): array
    {
        return [
            'version' => Version::get(),
            'package' => self::PACKAGE,
            'docs' => self::docsUrl(),
            'llmsTxt' => self::llmsTxtUrl(),
        ];
    }
}
