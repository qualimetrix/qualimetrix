<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ProjectScopeCoverage;

use FilesystemIterator;
use PhpParser\Node\Arg;
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
 * Production contexts must explicitly receive the run's measured project scope.
 *
 * Hand-built contexts keep the complete default. Production constructions may
 * not omit the fifth argument or pass a literal empty ProjectScopeJudgement,
 * because both claim complete answers without transporting the measurement.
 *
 * The existing scan reads direct AnalysisContext constructions and named or
 * positional arguments. It does not establish the provenance of a variable,
 * property or call, nor resolve aliases or indirect construction forms.
 */
final class AnalysisContextScopeArgumentGuardTest extends TestCase
{
    private const string ARGUMENT = 'projectScope';

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

                $answer = null;
                foreach ($construction->args as $index => $argument) {
                    if (!$argument instanceof Arg) {
                        continue;
                    }

                    $name = $argument->name?->toString();
                    if ($name === self::ARGUMENT || ($name === null && $index === self::POSITION - 1)) {
                        $answer = $argument->value;
                    }
                }

                $site = $file . ':' . $construction->getStartLine();

                if ($answer === null) {
                    $offenders[] = $site . ' (the argument is absent, so the default answers for it)';

                    continue;
                }

                if ($answer instanceof New_
                    && $answer->class instanceof Name
                    && $answer->class->getLast() === 'ProjectScopeJudgement'
                    && $answer->args === []) {
                    $offenders[] = $site . ' (an empty judgement is written out, not measured)';
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
        $root = \dirname(__DIR__, 2) . '/src';
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
