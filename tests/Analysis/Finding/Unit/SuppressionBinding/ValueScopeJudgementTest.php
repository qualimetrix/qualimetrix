<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\SuppressionBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\RemovedCodeNamespaces;
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
        @unlink($this->root . '/src/A.php');
        foreach (['/src/dir', '/src\\dir', '/src', '/tests', '/{legacy}'] as $directory) {
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
        $judgement = $this->judgement(['src'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none()));

        self::assertFalse($judgement->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
        self::assertTrue($this->judgement(['src', 'tests'], new ProjectScopeJudgement(removedCode: RemovedCodeNamespaces::none()))->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
        self::assertTrue($this->judgement([''], new ProjectScopeJudgement(removedCode: RemovedCodeNamespaces::none()))->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
    }

    /**
     * The opposite error, which the fix must not introduce: an anchored value
     * whose roots this run did analyse is still judged, so a genuine stale
     * entry is still reported.
     */
    #[Test]
    public function itStillJudgesAnAnchoredNamespaceValueOnAWholeProjectRun(): void
    {
        $judgement = $this->judgement(['src', 'tests'], new ProjectScopeJudgement(removedCode: RemovedCodeNamespaces::none()));

        self::assertTrue($judgement->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
    }

    /** And the run-shape answer the class exists for is unchanged. */
    #[Test]
    public function itStillDeclinesToJudgeAValueServedFromAnUnanalysedRoot(): void
    {
        $judgement = $this->judgement(['src'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none()));

        self::assertFalse($judgement->judgesNamespaceValue($this->subtreeNamespace('Acme\\Tests\\Unit')));
        self::assertTrue($judgement->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
    }

    /** The path branch, whose answer the namespace branch was made to match. */
    #[Test]
    public function itAnswersTheSameForRegexPathValue(): void
    {
        $judgement = $this->judgement(['src'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none()));

        self::assertFalse($judgement->judgesPathValue($this->regexPath('.*Gone\\.php')));
        self::assertTrue($this->judgement(['src', 'tests'], new ProjectScopeJudgement(removedCode: RemovedCodeNamespaces::none()))->judgesPathValue($this->regexPath('.*Gone\\.php')));
        self::assertTrue($judgement->judgesPathValue($this->subtreePath('src/Gone')));
    }

    #[Test]
    public function itKeepsALiteralBackslashDirectoryDistinctFromASlashDirectory(): void
    {
        mkdir($this->root . '/src\\dir');
        mkdir($this->root . '/src/dir');

        $value = $this->subtreePath('src/dir/Gone');

        self::assertFalse($this->judgement(['src\\dir'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none()))->judgesPathValue($value));
        self::assertTrue($this->judgement(['src/dir'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none()))->judgesPathValue($value));
    }

    #[Test]
    public function itWithholdsALiteralUnderRemovedCodeButJudgesItsNeighbour(): void
    {
        $source = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml');
        $removed = ExcludeSelectorVerdict::fromMeasuredFacts(
            $this->subtreePath('src/Legacy'),
            [$source],
            ['src/Legacy'],
            ['src/Legacy'],
            'php-file',
            null,
            [],
            true,
        );
        $scope = new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [$removed]);
        $judgement = new ValueScopeJudgement($this->root, ['Acme\\' => ['src']], [$this->root . '/src'], true, $scope);

        self::assertFalse($judgement->judgesPathValue($this->subtreePath('src/Legacy/Gone')));
        self::assertTrue($judgement->judgesPathValue($this->subtreePath('src/Gone')));
        self::assertFalse($judgement->judgesPathValue($this->regexPath('.*Gone')));
        self::assertFalse($judgement->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
    }

    /**
     * Code removed from the run narrows the literal question to what that code
     * declared: the stale neighbour stays judged, what the code could have
     * declared does not.
     */
    #[Test]
    public function itJudgesALiteralNamespaceTheRemovedCodeCannotHaveDeclared(): void
    {
        $judgement = $this->removedPhp(RemovedCodeNamespaces::known(['App\\Foo\\Bar']));

        self::assertTrue($judgement->judgesNamespaceValue($this->exactNamespace('Acme\\Does\\Not\\Exist')));
        self::assertTrue($judgement->judgesNamespaceValue($this->exactNamespace('App\\Baz')));
        self::assertTrue($judgement->judgesNamespaceValue($this->subtreeNamespace('App\\Baz')));
        self::assertFalse($judgement->judgesNamespaceValue($this->subtreeNamespace('App')));
        self::assertFalse($judgement->judgesNamespaceValue($this->exactNamespace('App')));
        self::assertFalse($judgement->judgesNamespaceValue($this->subtreeNamespace('App\\Foo')));
        self::assertFalse($judgement->judgesNamespaceValue($this->exactNamespace('App\\Foo')));
        self::assertFalse($judgement->judgesNamespaceValue($this->exactNamespace('app\\foo')));
        self::assertFalse($judgement->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
    }

    #[Test]
    public function itWithholdsEveryNamespaceValueWhenTheRemovedNamespacesAreUnknownOrAPathsDoorIsOpen(): void
    {
        $unknown = $this->removedPhp(RemovedCodeNamespaces::unknown());
        $paths = $this->judgement(['src'], new ProjectScopeJudgement([ProjectScopeDoor::Paths, ProjectScopeDoor::Exclude], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::known(['Other'])));

        self::assertFalse($unknown->judgesNamespaceValue($this->exactNamespace('Acme\\Gone')));
        self::assertFalse($paths->judgesNamespaceValue($this->exactNamespace('Acme\\Gone')));
        self::assertFalse($paths->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
    }

    /**
     * Removed code is blamed for a value only when it is the one reason the
     * value is withheld: the value would be judged had that code declared
     * nothing. Each withheld value here is paired with its blamed neighbour.
     */
    #[Test]
    public function itBlamesRemovedCodeOnlyForAValueItAloneWithholds(): void
    {
        $judgement = $this->removedPhp(RemovedCodeNamespaces::known(['Acme\\Foo\\Bar', 'Acme\\Tests\\Old']));
        $srcOnly = $this->removedPhp(RemovedCodeNamespaces::known(['Acme\\Tests\\Old']), ['src']);
        $pathsDoor = $this->judgement(['src', 'tests'], new ProjectScopeJudgement([ProjectScopeDoor::Paths, ProjectScopeDoor::Exclude], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::known(['Acme\\Foo'])));

        self::assertTrue($judgement->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Foo')));
        self::assertTrue($judgement->withholdsOnlyForRemovedCode($this->subtreeNamespace('Acme\\Foo')));
        self::assertTrue($judgement->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Tests\\Old')));
        self::assertFalse($judgement->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Baz')), 'judged, so not withheld at all');
        self::assertFalse($judgement->withholdsOnlyForRemovedCode($this->regexNamespace('Acme\\\\Foo.*')), 'a regex is withheld by removal whatever it declares');
        self::assertFalse($srcOnly->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Tests\\Old')), 'an unanalysed PSR-4 root withholds it too');
        self::assertFalse($pathsDoor->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Foo')), 'a paths door withholds it too');
        self::assertFalse($this->removedPhp(RemovedCodeNamespaces::unknown(), ['src', 'tests'], 'unlistable')->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Foo')));
        self::assertTrue($this->removedPhp(RemovedCodeNamespaces::unknown())->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Foo')), 'unread removed code may declare anything');
    }

    /** Code removed outside the measured universe opens no door and is still blamed for what it may declare. */
    #[Test]
    public function itBlamesRemovedCodeThatOpenedNoDoor(): void
    {
        $judgement = $this->judgement(['src', 'tests'], new ProjectScopeJudgement([], [], [], RemovedCodeNamespaces::known(['Acme\\Tests\\Legacy'])));

        self::assertFalse($judgement->judgesNamespaceValue($this->exactNamespace('Acme\\Tests\\Legacy')));
        self::assertTrue($judgement->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Tests\\Legacy')));
        self::assertTrue($judgement->judgesNamespaceValue($this->exactNamespace('Acme\\Tests\\Gone')));
        self::assertFalse($judgement->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Tests\\Gone')));
    }

    #[Test]
    public function itBlamesNothingWhereTheProjectDeclaresNoAutoload(): void
    {
        $removed = ExcludeSelectorVerdict::fromMeasuredFacts($this->subtreePath('src/Legacy'), [ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')], ['src/Legacy'], ['src/Legacy'], 'php-file', null, [], true);
        $undeclared = $this->judgement(['src', 'tests'], new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [$removed], RemovedCodeNamespaces::known(['Acme\\Foo'])), false);

        self::assertFalse($undeclared->withholdsOnlyForRemovedCode($this->exactNamespace('Acme\\Foo')));
    }

    /** @param list<string> $analyzedPaths */
    private function removedPhp(RemovedCodeNamespaces $removed, array $analyzedPaths = ['src', 'tests'], string $evidence = 'php-file'): ValueScopeJudgement
    {
        $removedSelector = ExcludeSelectorVerdict::fromMeasuredFacts(
            $this->subtreePath('src/Legacy'),
            [ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')],
            ['src/Legacy'],
            ['src/Legacy'],
            $evidence,
            null,
            [],
            true,
        );

        return $this->judgement($analyzedPaths, new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [$removedSelector], $removed));
    }

    private function exactNamespace(string $value): NamespacePattern
    {
        return new NamespacePattern(new SelectorDefinition(SelectorKind::Exact, $value));
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
        self::assertTrue($this->judgement(['{legacy}'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none()))->judgesPathValue($this->subtreePath('{legacy}/Gone')));
        self::assertFalse($this->judgement(['src'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none()))->judgesPathValue($this->subtreePath('{legacy}/Gone')));
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
        foreach ([
            [['src'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none())],
            [[''], new ProjectScopeJudgement(removedCode: RemovedCodeNamespaces::none())],
        ] as [$paths, $scope]) {
            $undeclared = $this->judgement($paths, $scope, projectDeclared: false);

            self::assertFalse($undeclared->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
            self::assertFalse($undeclared->judgesNamespaceValue($this->regexNamespace('Acme\\.*')));
            self::assertTrue($undeclared->judgesPathValue($this->subtreePath('src/Gone')));
        }

        self::assertTrue($this->judgement(['src'], new ProjectScopeJudgement([], [ProjectScopeDoor::Paths], [], RemovedCodeNamespaces::none()))->judgesNamespaceValue($this->subtreeNamespace('Acme\\Gone')));
    }

    #[Test]
    public function itJudgesBothRegexKindsFromMeasuredScopeForACompleteNamedRoster(): void
    {
        file_put_contents($this->root . '/src/A.php', '<?php namespace App; class A {}');
        $path = $this->regexPath('src/Gone.*');
        $namespace = $this->regexNamespace('App.*Gone.*');
        $judgement = new ValueScopeJudgement(
            $this->root,
            ['App\\' => ['src']],
            [$this->root . '/src/A.php'],
            true,
            new ProjectScopeJudgement(removedCode: RemovedCodeNamespaces::none()),
        );

        self::assertTrue($judgement->judgesPathValue($path));
        self::assertTrue($judgement->judgesNamespaceValue($namespace));

        foreach ([ProjectScopeDoor::Paths, ProjectScopeDoor::UnknownUniverse] as $door) {
            $withheld = new ValueScopeJudgement(
                $this->root,
                ['App\\' => ['src']],
                [$this->root . '/src/A.php'],
                true,
                new ProjectScopeJudgement([$door], [$door]),
            );

            self::assertFalse($withheld->judgesPathValue($path));
            self::assertFalse($withheld->judgesNamespaceValue($namespace));
        }

        $generated = new ValueScopeJudgement(
            $this->root,
            ['App\\' => ['src']],
            [$this->root . '/src/A.php'],
            true,
            new ProjectScopeJudgement([ProjectScopeDoor::Generated]),
        );

        self::assertTrue($generated->judgesPathValue($path));
        self::assertFalse($generated->judgesNamespaceValue($namespace));
    }

    /** @param list<string> $analyzedPaths relative to the fixture root */
    private function judgement(array $analyzedPaths, ProjectScopeJudgement $scope, bool $projectDeclared = true): ValueScopeJudgement
    {
        return new ValueScopeJudgement(
            $this->root,
            ['Acme\\' => ['src'], 'Acme\Tests\\' => ['tests']],
            array_map(fn(string $path): string => rtrim($this->root . '/' . $path, '/'), $analyzedPaths),
            $projectDeclared,
            $scope,
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
