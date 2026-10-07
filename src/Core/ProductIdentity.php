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
 * @qmx-threshold coupling.cbo 26 -- Documentation identity has 24 incoming readers and one outgoing Version dependency.
 *                Separate JSON and text assignment presenters consume that same identity;
 *                splitting its address by output would duplicate a product fact. Raw CBO 25
 *                retains one-edge headroom at the inclusive threshold of 26.
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

    /**
     * The published `meta` object, keys in published order. The timestamp is
     * a fact about the run, not the product, so the caller supplies it.
     *
     * @return array{version: string, package: string, timestamp: string, docs: string, llmsTxt: string}
     */
    public static function meta(string $timestamp): array
    {
        $identity = self::identity();

        return [
            'version' => $identity['version'],
            'package' => $identity['package'],
            'timestamp' => $timestamp,
            'docs' => $identity['docs'],
            'llmsTxt' => $identity['llmsTxt'],
        ];
    }
}
