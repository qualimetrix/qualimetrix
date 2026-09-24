<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\SuppressionBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\SuppressionBinding\ValueScopeJudgement;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

/**
 * Whether one configured value may be judged by this run, asked directly.
 *
 * Every case here is a pair. Silence is the safe answer, so a test that only
 * shows a value falling silent proves nothing on its own: the same edit made
 * blunt would silence the values a genuine miss is reported through, and the
 * channel would go quiet without anyone seeing it. Each case therefore names
 * the value that must stay judged beside the one that must not.
 */
#[CoversClass(ValueScopeJudgement::class)]
final class ValueScopeJudgementTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx_value_scope_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o755, true);
        mkdir($this->root . '/tests', 0o755, true);
        mkdir($this->root . '/{legacy}', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (['/src', '/tests', '/{legacy}'] as $directory) {
            @rmdir($this->root . $directory);
        }
        @rmdir($this->root);
    }

    /**
     * The defect: on a run that analysed every PSR-4 root, a value with no
     * literal head fell through to "judged" because it was compatible with
     * every prefix, and a correct configuration got an unmatched warning.
     */
    #[Test]
    public function itJudgesRegexOnlyWhenTheWholeProjectIsAnalysed(): void
    {
        $judgement = $this->judgement(['src', 'tests']);

        self::assertFalse($judgement->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
        self::assertTrue($this->judgement([''])->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
    }

    /**
     * The opposite error, which the fix must not introduce: an anchored value
     * whose roots this run did analyse is still judged, so a genuine stale
     * entry is still reported.
     */
    #[Test]
    public function itStillJudgesAnAnchoredNamespaceValueOnAWholeProjectRun(): void
    {
        $judgement = $this->judgement(['src', 'tests']);

        self::assertTrue($judgement->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
    }

    /** And the run-shape answer the class exists for is unchanged. */
    #[Test]
    public function itStillDeclinesToJudgeAValueServedFromAnUnanalysedRoot(): void
    {
        $judgement = $this->judgement(['src']);

        self::assertFalse($judgement->judgesNamespaceValue($this->subtreeNamespace('Acme\\Tests\\Unit')));
        self::assertTrue($judgement->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
    }

    /** The path branch, whose answer the namespace branch was made to match. */
    #[Test]
    public function itAnswersTheSameForRegexPathValue(): void
    {
        $judgement = $this->judgement(['src', 'tests']);

        self::assertFalse($judgement->judgesPathValue($this->regexPath('.*Gone\\.php')));
        self::assertTrue($judgement->judgesPathValue($this->subtreePath('src/Gone')));
    }

    /**
     * A brace is not a glob character to the matchers that apply a value, so
     * it is not one to the judge either: `{legacy}` anchors at the directory
     * it names, and a run that did not analyse that directory stays silent
     * about it — the pair that would have been impossible while the alphabets
     * differed, since the judge cut the anchor away and never asked.
     */
    #[Test]
    public function itAnchorsAValueOnACharacterOnlyTheJudgeUsedToCallAGlob(): void
    {
        self::assertTrue($this->judgement(['{legacy}'])->judgesPathValue($this->subtreePath('{legacy}/Gone')));
        self::assertFalse($this->judgement(['src'])->judgesPathValue($this->subtreePath('{legacy}/Gone')));
    }

    /**
     * A project that declares no production autoload has nothing to locate a
     * namespace through, even when a development map is at hand: no namespace
     * value is judged, literal or regex, on a slice or on the whole tree. The
     * path value beside it keeps its on-disk anchor and stays judged.
     */
    #[Test]
    public function itJudgesNoNamespaceValueWhereTheProjectDeclaresNoAutoload(): void
    {
        foreach ([['src'], ['']] as $paths) {
            $undeclared = $this->judgement($paths, projectDeclared: false);

            self::assertFalse($undeclared->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
            self::assertFalse($undeclared->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
            self::assertTrue($undeclared->judgesPathValue($this->subtreePath('src/Gone')));
        }

        self::assertTrue($this->judgement(['src'])->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
    }

    /** @param list<string> $analyzedPaths relative to the fixture root */
    private function judgement(array $analyzedPaths, bool $projectDeclared = true): ValueScopeJudgement
    {
        return new ValueScopeJudgement(
            $this->root,
            ['Acme\\' => ['src'], 'Acme\Tests\\' => ['tests']],
            array_map(fn(string $path): string => rtrim($this->root . '/' . $path, '/'), $analyzedPaths),
            $projectDeclared,
        );
    }

    private function subtreePath(string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $value));
    }

    private function regexPath(string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition(SelectorKind::Regex, $value));
    }

    private function subtreeNamespace(string $value): NamespacePattern
    {
        return new NamespacePattern(new SelectorDefinition(SelectorKind::Subtree, $value));
    }

    private function regexNamespace(string $value): NamespacePattern
    {
        return new NamespacePattern(new SelectorDefinition(SelectorKind::Regex, $value));
    }
}
