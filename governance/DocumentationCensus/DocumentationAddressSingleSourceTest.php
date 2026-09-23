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
 * failure here rather than a clean pass. The same guard applies per root: if
 * `html-report/src` stopped yielding any `.js` file, extending this test would
 * have silently become a no-op, so the walk asserts a non-empty population
 * for that root as well as for `src/`.
 *
 * Scope: PHP sources under `src/`, plus the HTML report viewer's own
 * JavaScript sources under `html-report/src/` — the one non-PHP channel that
 * renders the address (the report footer). The JS root walks every extension
 * vite resolves as a module (`js`, `mjs`, `cjs`); `html-report/src` carries
 * none but `.js` today, so the other two are covered but untested by the
 * corpus — {@see itWalksANonEmptyPopulationForEveryRoot()} only guards `.js`
 * for that reason; adding an `.mjs`/`.cjs` file under that root does not by
 * itself add a covered root there. Out of reach, deliberately:
 * `html-report/dist/` (the built bundle; its provenance is
 * `GeneratedArtifactFreshness`'s job, not this census's), `html-report/tests/`
 * (fixtures spell what they assert as data, not as a channel),
 * `html-report/report.html`/`dev.html` (checked by hand at the time this test
 * was extended — clean — but not walked), Markdown under `src/` (component
 * READMEs name the address as documentation, which is not a channel), a
 * TypeScript source under `html-report/src` (none exists; `.ts`/`.tsx` are
 * not in the extension set), case variants of the host (matched as a literal
 * substring), an address the SARIF fallback `helpUri` carries, and an address
 * assembled from fragments at runtime.
 */
final class DocumentationAddressSingleSourceTest extends TestCase
{
    private const string SOLE_OWNER = 'src/Core/ProductIdentity.php';

    /**
     * Repository-relative root => the file extensions that root's channel is
     * written in.
     *
     * @var array<string, list<string>>
     */
    private const array WALKED_ROOTS = [
        'src' => ['php'],
        'html-report/src' => ['js', 'mjs', 'cjs'],
    ];

    #[Test]
    public function itSpellsTheDocumentationHostOnlyInProductIdentity(): void
    {
        $host = parse_url(ProductIdentity::docsUrl(), \PHP_URL_HOST);
        self::assertIsString($host);

        $matches = [];

        foreach (self::WALKED_ROOTS as $root => $extensions) {
            $matches = [...$matches, ...self::filesSpelling($root, $extensions, $host)];
        }

        sort($matches);

        self::assertSame(
            [self::SOLE_OWNER],
            $matches,
            \sprintf(
                'The documentation host "%s" must be spelled in %s alone; read it from %s instead.',
                $host,
                self::SOLE_OWNER,
                ProductIdentity::class,
            ),
        );
    }

    /**
     * A root that stopped yielding any file of any of its extensions would
     * make this census vacuously trivial for that root; caught here rather
     * than left to the combined assertion above, which cannot tell "no match
     * because clean" from "no match because nothing was walked". Judged per
     * root, not per extension: `html-report/src` walks three extensions and
     * today carries only one of them, and that is not itself a failure here —
     * the root as a whole is non-empty. An individual extension going empty
     * (all of `.js` renamed to `.mjs`, say) is invisible to this assertion as
     * long as the root's combined population stays non-empty.
     */
    #[Test]
    public function itWalksANonEmptyPopulationForEveryRoot(): void
    {
        foreach (self::WALKED_ROOTS as $root => $extensions) {
            self::assertNotSame(
                [],
                self::filesOfExtensions($root, $extensions),
                \sprintf(
                    '%s yielded no *.{%s} file; the census for that root would be vacuous.',
                    $root,
                    implode(',', $extensions),
                ),
            );
        }
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<string> repository-relative paths, sorted
     */
    private static function filesSpelling(string $root, array $extensions, string $needle): array
    {
        $matches = [];

        foreach (self::walk($root, $extensions) as $file) {
            $repositoryRoot = \dirname(__DIR__, 2);
            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents, $file->getPathname());

            if (str_contains($contents, $needle)) {
                $matches[] = substr($file->getPathname(), \strlen($repositoryRoot) + 1);
            }
        }

        sort($matches);

        return $matches;
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<SplFileInfo>
     */
    private static function filesOfExtensions(string $root, array $extensions): array
    {
        return iterator_to_array(self::walk($root, $extensions), false);
    }

    /**
     * @param list<string> $extensions
     *
     * @return iterable<SplFileInfo>
     */
    private static function walk(string $root, array $extensions): iterable
    {
        $repositoryRoot = \dirname(__DIR__, 2);
        $absoluteRoot = $repositoryRoot . '/' . $root;

        if (!is_dir($absoluteRoot)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);

            if (\in_array($file->getExtension(), $extensions, true)) {
                yield $file;
            }
        }
    }
}
