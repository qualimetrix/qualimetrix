<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Composer\InstalledVersions;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplFileInfo;

/**
 * Generates cache keys for PHP files based on their content and environment.
 */
final class CacheKeyGenerator
{
    private const string KEY_SCHEMA_VERSION = 'ast-cache-v2';

    private const string PARSER_PACKAGE = 'nikic/php-parser';

    private readonly string $cacheVersion;

    public function __construct(LoggerInterface $logger = new NullLogger())
    {
        $parserVersion = self::phpParserVersion();

        // An empty version is not a version to key on: it would pin every
        // php-parser release to one key and hand a warm cache to a parser
        // whose node classes have changed. Caching is given up instead.
        $this->cacheVersion = $parserVersion === ''
            ? ''
            : \sprintf('php%s-parser%s', \PHP_MAJOR_VERSION . '.' . \PHP_MINOR_VERSION, $parserVersion);

        if ($this->cacheVersion === '') {
            // Giving caching up is the right call and an invisible one: what
            // the user sees is a run several times slower and a cache
            // directory that stays empty. Said once, where the decision is
            // taken. A parallel run builds one of these in the parent and one
            // more in each worker (measured), and only the parent's is given a
            // logger: every process of one install reaches the same verdict,
            // so a worker repeating it would add copies, not information.
            $logger->warning(\sprintf(
                'AST caching is off for this run: the Composer runtime cannot name the installed %s, '
                . 'and a cache key that does not carry the parser version would serve a stale AST.',
                self::PARSER_PACKAGE,
            ));
        }
    }

    /**
     * Generate a unique cache key for a file.
     *
     * Key components:
     * - content hash: invalidates the cache for every source change
     * - cacheVersion: PHP + php-parser version
     */
    public function generate(SplFileInfo $file): string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            return '';
        }

        $content = @file_get_contents($realPath);

        if ($content === false) {
            return '';
        }

        return $this->generateForContent($content);
    }

    /**
     * Generate a cache key for source bytes that were already read.
     *
     * An empty key means "do not cache this"; {@see \Qualimetrix\Infrastructure\Ast\CachedFileParser}
     * parses directly when it gets one.
     */
    public function generateForContent(string $content): string
    {
        if ($this->cacheVersion === '') {
            return '';
        }

        $contentHash = hash('xxh128', $content);
        $data = \sprintf('%s|%s|%s', self::KEY_SCHEMA_VERSION, $contentHash, $this->cacheVersion);

        return hash('xxh128', $data);
    }

    /**
     * Get the cache version string.
     */
    public function getCacheVersion(): string
    {
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
