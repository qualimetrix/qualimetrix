<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

/**
 * What `paths:` refuses, and why each refusal exists.
 *
 * The empty path used to be answered by the path factory in the vocabulary of
 * the CLI, which misnames the door whenever the value came from `paths:` in a
 * document — and without the product's refusal framing either way.
 *
 * The rest of this class is the same class of defect one step earlier: an
 * entry that was not a string was filtered out of the list without a word, and
 * a list those entries emptied left discovery with nothing to find and the run
 * reporting success over zero files.
 */
final class EmptyAnalysisPathRefusalTest extends TestCase
{
    #[Test]
    public function itRefusesAnEmptyPathWithTheProductFraming(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve(['']);
    }

    #[Test]
    public function itRefusesAnEmptyPathAmongLawfulOnes(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve(['src', '']);
    }

    #[Test]
    public function itStillAcceptsLawfulPaths(): void
    {
        self::assertNotSame([], self::resolve(['src'])->paths);
    }

    #[Test]
    public function itStillDefaultsToTheWorkingDirectory(): void
    {
        $document = LayeredDocument::of([], AbsolutePath::fromString('/project'));
        $resolved = (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerReader())))->resolve($document);

        self::assertSame(['/project'], array_map(static fn($path): string => $path->value(), $resolved->paths));
    }

    /**
     * The dangling `-` a document reads as `null`, the unquoted directory name
     * a document reads as an integer, and the rest of the forms that are not a
     * path. Each used to leave the list quietly shorter than the author wrote.
     *
     * @param list<mixed> $paths
     */
    #[Test]
    #[TestWith([[null]])]
    #[TestWith([[2024]])]
    #[TestWith([[true]])]
    #[TestWith([[1.5]])]
    #[TestWith([[['src']]])]
    #[TestWith([['src', null]])]
    #[TestWith([[null, 'src']])]
    public function itRefusesAnEntryThatIsNotAPath(array $paths): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve($paths);
    }

    /**
     * A list the author wrote empty asks for no analysis at all, which is less
     * analysis than the command promises and used to be reported as a
     * successful run over zero files.
     */
    #[Test]
    public function itRefusesAnEmptyListOfPaths(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve([]);
    }

    /** A value that is not a list at all — the same question, asked of the whole key. */
    #[Test]
    #[TestWith(['src'])]
    #[TestWith([['first' => 'src']])]
    public function itRefusesAPathsValueThatIsNotAList(mixed $paths): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve($paths);
    }

    /** `paths: ~` is a key nobody wrote, as `~` is everywhere in the document: the default stands. */
    #[Test]
    public function itReadsANullPathsAsUnwritten(): void
    {
        self::assertSame(['/project'], array_map(static fn($path): string => $path->value(), self::resolve(null)->paths));
    }

    /** @param list<string> $paths */
    #[Test]
    #[TestWith([[]])]
    #[TestWith([['']])]
    #[TestWith([['src', '']])]
    public function itRefusesAnEmptyContributionThatALaterSourceOverrides(array $paths): void
    {
        try {
            LayeredDocument::of([
                ['source' => 'qmx.yaml', 'values' => ['paths' => $paths]],
                ['source' => 'cli', 'values' => ['paths' => ['src']]],
            ], AbsolutePath::fromString('/project'));
            self::fail('The command line hid an empty paths contribution.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('qmx.yaml', $refusal->sources()[0]->locator());
            self::assertSame($paths === [] ? ['paths'] : ['paths', (string) array_search('', $paths, true)], $refusal->position()?->segments);
            self::assertStringContainsString('empty', $refusal->summary());
        }
    }

    /**
     * Form is judged of every contribution, not only of the last one: a
     * malformed list stays malformed whatever a later source says about it.
     */
    #[Test]
    public function itRefusesAMalformedContributionThatALaterSourceOverrides(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerReader())))->resolve(LayeredDocument::of(
            [
                ['source' => 'config', 'values' => ['paths' => [2024]]],
                ['source' => 'cli', 'values' => ['paths' => ['src']]],
            ],
            AbsolutePath::fromString('/project'),
        ));
    }

    private static function resolve(mixed $paths): \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration
    {
        $document = LayeredDocument::of(
            [['source' => 'cli', 'values' => ['paths' => $paths]]],
            AbsolutePath::fromString('/project'),
        );

        return (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerReader())))->resolve($document);
    }
}
