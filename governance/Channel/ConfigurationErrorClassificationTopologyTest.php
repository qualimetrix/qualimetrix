<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\Channel;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Core\Symbol\SymbolLevel;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * "This finding is about the configuration" is stamped in one place, and
 * nowhere else can reach it.
 *
 * Before the split, every producer authored the answer for its own channels,
 * which is how a rule came to hold five channels it could never baseline
 * beside two it could. The answer is now a consequence of the producing type,
 * and a consequence is only as trustworthy as the number of places that can
 * produce it — so this file counts them.
 *
 * Three halves, and all are needed. The first counts calls spelled literally:
 * it parses production sources and finds every call whose method name is the
 * wither, whatever receiver it is called on — a receiver is unconstrained, a
 * dynamic method name is not, which is why there is a second. The second pins
 * the name itself: no production file outside the assembly point and its own
 * documentation may so much as mention it, which is what an indirect call
 * would have to do. The third closes the way around the wither entirely — the
 * constructor is private, so no caller can hand the flag in directly, and the
 * two public factories both yield `false`.
 *
 * The other half of this subject —
 * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\ConfigurationErrorClassificationTopologyTest} —
 * runs the compiler pass on fixture classes the test declares itself and
 * stays a product test.
 */
#[CoversClass(ChannelDeclaration::class)]
final class ConfigurationErrorClassificationTopologyTest extends TestCase
{
    private const string WITHER = 'asConfigurationError';

    #[Test]
    public function itAllowsExactlyOneProductionSiteToTurnADeclarationIntoAConfigurationError(): void
    {
        $sites = [];

        foreach (self::productionFiles() as $file) {
            $finder = new NodeFinder();
            $ast = self::parse($file);

            /** @var list<Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\NullsafeMethodCall> $calls */
            $calls = $finder->find($ast, static function (Node $node): bool {
                if (!$node instanceof Node\Expr\MethodCall
                    && !$node instanceof Node\Expr\StaticCall
                    && !$node instanceof Node\Expr\NullsafeMethodCall
                ) {
                    return false;
                }

                return $node->name instanceof Node\Identifier && $node->name->toString() === self::WITHER;
            });

            foreach ($calls as $call) {
                $sites[] = \sprintf('%s:%d', self::relative($file), $call->getStartLine());
            }
        }

        self::assertCount(
            1,
            $sites,
            'The classification must be applied where the channel registry is assembled and the declaring'
            . ' producer type is known, and nowhere else. Sites found: ' . implode(', ', $sites),
        );
        self::assertStringContainsString('ChannelDeclarationCompilerPass', $sites[0]);
    }

    /**
     * What the parse above cannot see, closed by text.
     *
     * The finder matches a literal method name, so `$declaration->{$m}()`,
     * `[$declaration, 'asConfigurationError']()` and `call_user_func(...)`
     * would all be invisible to it. Every one of them has to spell the name
     * somewhere, so the name itself is what is pinned: outside the class that
     * declares the wither and the pass that calls it, no production file may
     * mention it at all.
     *
     * Residual, stated rather than hidden: a name assembled from fragments at
     * run time defeats both halves. Nothing in this codebase builds method
     * names that way, and the `@internal` marker on the wither says the same
     * thing to a reader.
     */
    #[Test]
    public function itRefusesAnyOtherProductionFileThatEvenNamesTheWither(): void
    {
        $allowed = [
            'src/Analysis/Finding/Contract/ChannelDeclaration.php',
            'src/Analysis/Finding/Contract/ConfigurationValidatorInterface.php',
            'src/Infrastructure/DependencyInjection/CompilerPass/ChannelDeclarationCompilerPass.php',
        ];

        $mentions = [];

        foreach (self::productionFiles() as $file) {
            $relative = self::relative($file);

            if (\in_array($relative, $allowed, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                throw new RuntimeException(\sprintf('Could not read %s.', $file));
            }

            // Word-boundary on the left so `hasConfigurationError` and friends
            // are not mistaken for a reference to the wither.
            if (preg_match('/(?<![A-Za-z0-9_$])' . self::WITHER . '/', $contents) === 1) {
                $mentions[] = $relative;
            }
        }

        self::assertSame(
            [],
            $mentions,
            'The classification wither is registry-assembly internal. A production file that names it is either a'
            . ' second assembly site the AST count cannot see, or a reference that will become one. Files: '
            . implode(', ', $mentions),
        );
    }

    #[Test]
    public function itRefusesAProductionSiteThatHandsTheFlagToTheConstructorInstead(): void
    {
        $constructor = (new ReflectionClass(ChannelDeclaration::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue(
            $constructor->isPrivate(),
            'A public constructor taking the flag would be a second assembly site the parse above cannot see.',
        );
        self::assertFalse(ChannelDeclaration::occurrence(SymbolLevel::Project)->isConfigurationError());
        self::assertFalse(
            ChannelDeclaration::magnitude(
                \Qualimetrix\Core\Observation\WorseDirection::Higher,
                SymbolLevel::Project,
            )->isConfigurationError(),
        );
    }

    /**
     * @return list<string>
     */
    private static function productionFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::sourceRoot(), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<Node>
     */
    private static function parse(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $file));
        }

        return (new ParserFactory())->createForHostVersion()->parse($contents) ?? [];
    }

    private static function sourceRoot(): string
    {
        return \dirname(__DIR__, 2) . '/src';
    }

    private static function relative(string $file): string
    {
        return 'src' . substr($file, \strlen(self::sourceRoot()));
    }
}
