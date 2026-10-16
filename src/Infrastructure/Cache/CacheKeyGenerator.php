<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Composer\InstalledVersions;
use OutOfBoundsException;

/**
 * Generates cache keys for PHP files based on their content and environment.
 */
final class CacheKeyGenerator
{
    private const string PARSER_PACKAGE = 'nikic/php-parser';

    private ?string $cacheVersion = null;
    private ?string $parserVersion = null;

    public function available(): bool
    {
        return $this->getCacheVersion() !== '';
    }

    /**
     * Generate a cache key for source bytes that were already read.
     *
     * An empty key means "do not cache this"; {@see \Qualimetrix\Infrastructure\Ast\CachedFileParser}
     * parses directly when it gets one.
     */
    public function generateForContent(string $content): string
    {
        if (!$this->available()) {
            return '';
        }
        $context = hash_init('sha256');
        foreach (['php' . \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION, $this->parserVersion ?? '', (string) \ini_get('short_open_tag'), (string) \ini_get('zend.multibyte'), (string) \ini_get('zend.script_encoding'), $content] as $field) {
            hash_update($context, \strlen($field) . ':' . $field);
        }

        return hash_final($context);
    }

    public function getCacheVersion(): string
    {
        if ($this->cacheVersion === null) {
            $this->parserVersion = self::phpParserVersion();
            $this->cacheVersion = $this->parserVersion === '' ? '' : \sprintf('php%d.%d-parser%s', \PHP_MAJOR_VERSION, \PHP_MINOR_VERSION, $this->parserVersion);
        }

        return $this->cacheVersion;
    }

    /**
     * Asked of the Composer runtime, by package name.
     *
     * The path this used to compute from `__DIR__` resolves to the installing
     * project's `vendor/` only when Qualimetrix is the root package. Installed
     * the documented way — as a dependency — the file is not there, and the
     * fallback answered with the major version alone: every 5.x release shared
     * one key, so upgrading the parser did not invalidate anything.
     *
     * A tagged version names one set of bytes and is the whole answer. A
     * branch version does not: Composer normalizes a branch requirement to
     * `dev-<name>` or `<n>-dev`, and every commit on that branch carries the
     * same string while the node classes underneath it move. There the commit
     * is appended, because two installs of one dev branch are two different
     * parsers and must not share a key. `getVersion()` never carries it —
     * `reference` is a separate field of the install record.
     */
    private static function phpParserVersion(): string
    {
        if (!InstalledVersions::isInstalled(self::PARSER_PACKAGE)) {
            return '';
        }

        try {
            $version = InstalledVersions::getVersion(self::PARSER_PACKAGE) ?? '';
            $reference = self::namesABranch($version)
                ? InstalledVersions::getReference(self::PARSER_PACKAGE)
                : null;
        } catch (OutOfBoundsException) {
            return '';
        }

        // A branch install without a reference — a path repository, say — has
        // nothing more precise to offer, and the branch name is kept as the
        // best available answer rather than turned into a refusal to cache.
        return $reference === null ? $version : $version . '@' . $reference;
    }

    /**
     * Whether this version string names a branch rather than a release.
     */
    private static function namesABranch(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    }
}
