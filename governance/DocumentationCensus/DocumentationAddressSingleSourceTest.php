<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DocumentationCensus;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ProductIdentity;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The documentation site's address is spelled in exactly one production
 * file, {@see ProductIdentity}; every channel that points at the
 * documentation reads it from there. A second spelling is a channel that
 * stays behind when the site moves.
 *
 * The assertion is "exactly one file, and it is this one", not "no file other
 * than this one": a walk that reaches nothing finds zero files, and zero is a
 * failure here rather than a clean pass.
 *
 * Scope: PHP sources under `src/`. Markdown there (component READMEs) names
 * the address as documentation and is out of scope. The host is matched as a
 * literal substring, so an address assembled from fragments at runtime is not
 * seen.
 */
final class DocumentationAddressSingleSourceTest extends TestCase
{
    private const string SOURCE_ROOT = 'src';

    private const string SOLE_OWNER = 'src/Core/ProductIdentity.php';

    #[Test]
    public function itSpellsTheDocumentationHostOnlyInProductIdentity(): void
    {
        $host = parse_url(ProductIdentity::docsUrl(), \PHP_URL_HOST);
        self::assertIsString($host);

        self::assertSame(
            [self::SOLE_OWNER],
            self::filesSpelling($host),
            \sprintf(
                'The documentation host "%s" must be spelled in %s alone; read it from %s instead.',
                $host,
                self::SOLE_OWNER,
                ProductIdentity::class,
            ),
        );
    }

    /**
     * @return list<string> repository-relative paths, sorted
     */
    private static function filesSpelling(string $needle): array
    {
        $repositoryRoot = \dirname(__DIR__, 2);
        $root = $repositoryRoot . '/' . self::SOURCE_ROOT;
        $matches = [];

        if (!is_dir($root)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents, $file->getPathname());

            if (str_contains($contents, $needle)) {
                $matches[] = substr($file->getPathname(), \strlen($repositoryRoot) + 1);
            }
        }

        sort($matches);

        return $matches;
    }
}
