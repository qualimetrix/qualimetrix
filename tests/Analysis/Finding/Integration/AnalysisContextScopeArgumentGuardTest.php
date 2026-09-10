<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No production context may inherit "this run covers the project" by default.
 *
 * {@see \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration}
 * states the rule and enforces it by refusing a default value, which it can
 * afford because every one of its construction sites is production.
 * {@see AnalysisContext} cannot: hundreds of hand-built test contexts would
 * have to name a field they have nothing to say about, and the field would
 * become noise at exactly the sites that do not matter. So the default stays
 * and the rule is enforced where its population is — here.
 *
 * A production site that omits the argument gets `true`: it says "this run
 * looked at the whole project" without having measured it, which is the silent
 * acceptance the field exists to prevent, and the direction of that error is
 * a channel accusing an author of a correct configuration.
 */
final class AnalysisContextScopeArgumentGuardTest extends TestCase
{
    private const string ARGUMENT = 'coversProjectScope';

    /** The fifth constructor parameter, when a site passes them positionally. */
    private const int POSITION = 5;

    #[Test]
    public function itFindsNoProductionContextBuiltWithoutAMeasuredScopeAnswer(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $offenders = [];
        $sites = 0;

        foreach (self::productionFiles() as $file) {
            $statements = $parser->parse((string) file_get_contents($file)) ?? [];

            /** @var list<New_> $constructions */
            $constructions = $finder->find($statements, static fn($node): bool => $node instanceof New_
                    && $node->class instanceof Name
                    && $node->class->getLast() === 'AnalysisContext');

            foreach ($constructions as $construction) {
                ++$sites;

                $named = false;
                foreach ($construction->args as $index => $argument) {
                    $name = $argument->name?->toString();
                    if ($name === self::ARGUMENT || ($name === null && $index === self::POSITION - 1)) {
                        $named = true;
                    }
                }

                if (!$named) {
                    $offenders[] = $file . ':' . $construction->getStartLine();
                }
            }
        }

        self::assertGreaterThan(0, $sites, 'The scan found no construction site at all, so it proves nothing.');
        self::assertSame([], $offenders, implode("\n", [
            'Every production AnalysisContext must carry a measured answer for ' . self::ARGUMENT . ':',
            ...$offenders,
        ]));
    }

    /**
     * @return list<string>
     */
    private static function productionFiles(): array
    {
        $root = \dirname(__DIR__, 4) . '/src';
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
