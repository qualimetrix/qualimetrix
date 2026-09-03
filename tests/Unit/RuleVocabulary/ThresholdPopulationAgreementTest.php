<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\RuleVocabulary;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxDirectiveAudit\EnumeratedSite;
use QmxDirectiveAudit\ThresholdDirectiveScan;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use RuntimeException;

/**
 * The two measures of the authored `@qmx-threshold` population, asked the same
 * questions.
 *
 * `composer directives:audit` refuses a tree where the audit's population and
 * the enumeration's disagree, and that comparison is only evidence if the two
 * measures could disagree. Until this test existed they could not: the
 * enumeration carried a copy of the extractor's private pattern and a test
 * demanded the copy stay byte-identical, so a character narrowed in the pattern
 * moved both measures at once and the gate stayed green through it.
 *
 * What replaced that text comparison is this one: the enumeration reads
 * docblock lines word by word with a character list of its own, and agreement
 * with the product is asserted on a fixture of authored forms rather than
 * assumed from shared source. Byte-identity of two spellings is not a property
 * anyone needs; answering alike on what a developer can write is.
 *
 * The fixture and not `src/` because of what `src/` does not contain: every
 * target in the tree is spelled with lowercase letters, dots and hyphens, so
 * narrowing the pattern to exactly those would leave every measurement of the
 * live tree unmoved. The rows naming a target with any other character exist
 * for that reason alone, and so do the ones the product cuts short — a
 * character list that *grows* reads further than the product does, and no
 * narrowing catches that.
 *
 * One authored form is deliberately absent. The product's separator between the
 * tag and its target is `\s+`, which crosses a line break, so a tag alone at
 * the end of a line takes the next line's docblock star as its target — a
 * target of `*` is not something an author wrote, and reproducing that defect
 * in the second measure would make the pair agree about a bug. It is recorded
 * in `FOLLOWUPS.md` instead.
 *
 * The library has no PSR-4 entry, the same as `scripts/finding-gate/`, so this
 * test loads it the way its own scripts do.
 */
final class ThresholdPopulationAgreementTest extends TestCase
{
    private const string FIXTURE = 'tests/Unit/RuleVocabulary/Fixtures/AuthoredThresholdForms.php';

    /** The seeded tree `composer directives:narrow-control` measures its heterogeneous half over. */
    private const string SEEDED_FIXTURE = 'tests/Analysis/Policy/Inline/Fixtures/NarrowControl';

    /** @var list<string> scratch trees to remove, whatever the case did with them */
    private static array $trees = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['EnumeratedSite', 'ThresholdDirectiveScan'] as $part) {
            require_once \dirname(__DIR__, 3) . '/scripts/directive-audit/' . $part . '.php';
        }
    }

    protected function tearDown(): void
    {
        foreach (self::$trees as $tree) {
            self::removeTree($tree);
        }

        self::$trees = [];
    }

    /**
     * A site by what it says rather than by where it is: file name, line,
     * target and values.
     *
     * The directory is deliberately not part of it. An identity carrying the
     * path would report two copies of one fixture as two different sites, and
     * the mistake this case guards against is exactly a copy that moved.
     */
    private static function contentIdentity(EnumeratedSite $site): string
    {
        return \sprintf('%s:%d:%s:%s', basename($site->file), $site->line, $site->target, $site->values);
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path)) {
            @chmod($path, 0o600);
            @unlink($path);

            return;
        }

        $entries = scandir($path);

        foreach (array_diff($entries === false ? [] : $entries, ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }

        @rmdir($path);
    }

    /**
     * Each row is the declaration and what it authors: the target, and the
     * values the product parses out behind it.
     *
     * The values are part of the expectation because they are part of the
     * measurement — the enumeration publishes them in its fourth column, and
     * nothing else in this repository compares them to what the product read.
     *
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function provideAuthoredForms(): iterable
    {
        yield 'plain' => ['plain', [['plain.simple', '20']]];

        yield 'glued to the docblock star' => ['gluedToTheStar', [['glued.star', '20']]];

        yield 'tag with a suffix' => ['suffixedWord', []];

        yield 'backticked' => ['backticked', []];

        yield 'after a multiline backtick region' => ['afterMultilineBacktickRegion', [['after.backticks', '20']]];

        yield 'outside a docblock' => ['outsideADocblock', []];

        yield 'two on one line' => [
            'twoOnOneLine',
            [['first.of.two', '20 @qmx-threshold second.of.two 30']],
        ];

        yield 'target cut at a call' => ['targetCutAtACall', [['paren.call', '']]];

        yield 'target wrapped in parens' => ['targetWrappedInParens', []];

        yield 'star' => ['targetWithAStar', [['class.star.*', '20']]];

        yield 'hash' => ['targetWithAHash', [['class.hash#code', '20']]];

        yield 'colon' => ['targetWithAColon', [['class.colon:level', '20']]];

        yield 'digit' => ['targetWithADigit', [['class.digit9', '20']]];

        yield 'underscore' => ['targetWithAnUnderscore', [['class_underscore', '20']]];

        yield 'capital' => ['targetWithACapital', [['Class.Upper', '20']]];

        yield 'hyphen' => ['targetWithAHyphen', [['hyphen-target', '20']]];

        yield 'cut target then a second directive' => [
            'cutTargetThenASecondDirective',
            [['cut.first', ''], ['second.target', '20']],
        ];

        yield 'single-line docblock' => ['onASingleLineDocblock', [['one.line', '20']]];

        yield 'slash' => ['targetCutAtASlash', [['slash', '']]];

        yield 'plus' => ['targetCutAtAPlus', [['plus', '']]];

        yield 'comma' => ['targetFollowedByAComma', [['comma.target', '']]];
    }

    /**
     * Both measures are asked about one declaration, and both answers are
     * compared to the same expectation rather than to each other.
     *
     * "The two agree" alone would pass while both are wrong, which is how the
     * pair failed before: they agreed by construction. The expected line comes
     * out of the fixture source itself, so a measure that reports a real site
     * on the wrong line — what removing a multiline backtick region does to
     * everything below it — is a failure and not a rounding difference.
     *
     * @param list<array{string, string}> $authored target and values, in the order the source writes them
     */
    #[Test]
    #[DataProvider('provideAuthoredForms')]
    public function itReadsAnAuthoredFormTheWayTheProductDoes(string $declaration, array $authored): void
    {
        $authoredValues = array_map(
            static fn(array $site): string => \sprintf('%d|%s|%s', self::lineAuthoring($site[0]), $site[0], $site[1]),
            $authored,
        );
        $productReadings = array_map(
            static fn(array $site): string => \sprintf(
                '%d|%s|%s',
                self::lineAuthoring($site[0]),
                $site[0],
                self::productReadingOf($site[0], $site[1]),
            ),
            $authored,
        );
        [$from, $to] = self::rangeOf($declaration);

        self::assertSame(
            $productReadings,
            self::within(self::productSites(), $from, $to),
            'the product read this form differently from what the row says it authors',
        );
        self::assertSame(
            $authoredValues,
            self::within(self::scanRawSites(), $from, $to),
            'the enumeration read this form differently from what the row says it authors',
        );
    }

    /**
     * Every declaration in the fixture is named by a row above.
     *
     * A form nobody wrote a row for is measured by one case only — the
     * whole-fixture one, which compares the two measures against each other.
     * That is the self-confirming pair this package exists to remove, and it
     * would grow back silently with the next form added to the fixture.
     */
    #[Test]
    public function itNamesEveryFormTheFixtureDeclares(): void
    {
        $declared = array_map(
            static fn(Node\Stmt\ClassMethod $method): string => $method->name->toString(),
            self::methods(),
        );
        $named = array_map(
            static fn(array $row): string => $row[0],
            iterator_to_array(self::provideAuthoredForms()),
        );

        sort($declared);
        sort($named);

        self::assertSame($declared, $named);
    }

    /**
     * The whole fixture, as one population each.
     *
     * Per-declaration agreement leaves one gap: a measure that invents a site
     * where no declaration expects one — in the file's own prose, say — is
     * outside every range the cases look at.
     */
    #[Test]
    public function itMeasuresTheSamePopulationOverTheWholeFixture(): void
    {
        self::assertSame(self::productSites(), self::scanSites());
        self::assertNotSame([], self::productSites(), 'a fixture nobody read is not an agreement');
    }

    /**
     * The seeded directives stay out of the tree's own measurement.
     *
     * `NarrowControl` exists to make the narrow/full comparison face a
     * population it could disagree over: a dead directive, an overrun boundary,
     * a masking coalition and three refusals, all authored on purpose. None of
     * them is a statement about this project's code, and the enumeration over
     * `src/` — the measure `composer directives:audit` judges the tree against
     * — must not carry them.
     *
     * The enumerator has no exclusion mechanism, so what keeps the two apart is
     * the target `src`, which is a convention rather than a barrier. This case
     * is the barrier. It compares by the site's own content rather than by its
     * path, so it still reddens when the fixture is moved under `src/`, which is
     * exactly the mistake a convention permits.
     *
     * @throws RuntimeException
     */
    #[Test]
    public function itKeepsTheSeededDirectivesOutOfTheEnumerationOverSrc(): void
    {
        $root = \dirname(__DIR__, 3);

        $seeded = array_map(self::contentIdentity(...), ThresholdDirectiveScan::overTree($root, self::SEEDED_FIXTURE));
        self::assertNotSame([], $seeded, 'the seeded fixture carries no directive, so this case proves nothing');

        $enumerated = array_map(self::contentIdentity(...), ThresholdDirectiveScan::overTree($root, 'src'));

        self::assertSame(
            [],
            array_values(array_unique(array_intersect($enumerated, $seeded))),
            'a seeded directive reached the enumeration over src/',
        );
    }

    /**
     * The scan over a directory, rather than over a string.
     *
     * `overTree()` is what the CI step actually runs, and until this case
     * existed nothing but a live run over `src/` exercised its file filter, its
     * relative paths or its refusal to carry on past a file it cannot read — a
     * measurement with a hole in it is the one thing this measure may not be.
     */
    #[Test]
    public function itScansATreeAndSkipsWhatIsNotPhp(): void
    {
        $root = self::temporaryTree([
            'lib/Kept.php' => "<?php\n/**\n * @qmx-threshold tree.kept 20\n */\nclass Kept {}\n",
            'lib/nested/Deep.php' => "<?php\n/** @qmx-threshold tree.deep 30 */\nclass Deep {}\n",
            // A whole PHP docblock, in a file the scan must not open: a plain
            // sentence would be skipped by the tokenizer anyway, and then the
            // extension filter could be deleted with nothing noticing.
            'lib/notes.txt' => "<?php\n/** @qmx-threshold tree.text 40 */\n",
        ]);

        $sites = array_map(
            static fn(EnumeratedSite $site): string => $site->site() . '|' . $site->values,
            ThresholdDirectiveScan::overTree($root, 'lib'),
        );
        sort($sites);

        self::assertSame(
            ['lib/Kept.php:3:tree.kept|20', 'lib/nested/Deep.php:2:tree.deep|30'],
            $sites,
        );
    }

    #[Test]
    public function itRefusesToScanATreeItCannotRead(): void
    {
        $root = self::temporaryTree(['lib/Locked.php' => "<?php\n/** @qmx-threshold tree.locked 20 */\n"]);
        chmod($root . '/lib/Locked.php', 0o000);

        if (is_readable($root . '/lib/Locked.php')) {
            self::markTestSkipped('This user reads a file with no permission bits, so nothing can be hidden from it.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unreadable');

        ThresholdDirectiveScan::overTree($root, 'lib');
    }

    /**
     * What the product recognised, overrides and diagnostics alike.
     *
     * A directive whose values do not parse produces a diagnostic instead of an
     * override, and it is still a site the author wrote and the enumeration
     * lists — a target cut short by a bracket is exactly that. Reading only the
     * overrides would make the two measures disagree over a form neither of
     * them misread.
     *
     * The third field is what the product made of the values, because the
     * string it read them from is not observable: an override carries parsed
     * thresholds and a diagnostic quotes its complaint.
     *
     * @return list<string> `line|target|reading`
     */
    private static function productSites(): array
    {
        $sites = [];

        foreach (self::documentedNodes() as $node) {
            foreach (self::readingsOf($node) as $reading) {
                $sites[] = $reading;
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * The enumeration's sites, with their values put back to the product.
     *
     * The values column cannot be compared to the product directly: what the
     * extractor publishes is the parsed thresholds, never the string it parsed
     * them from. So each pair the enumeration reports is handed back to the
     * extractor on a docblock of its own, and what the product makes of it must
     * be what the product made of the line it came from. A values column that
     * carried `20` and a docblock terminator would parse as nothing where the
     * original parsed as a number.
     *
     * @return list<string> `line|target|reading`
     */
    private static function scanSites(): array
    {
        $sites = array_map(
            static fn(EnumeratedSite $site): string => \sprintf(
                '%d|%s|%s',
                $site->line,
                $site->target,
                self::productReadingOf($site->target, $site->values),
            ),
            ThresholdDirectiveScan::overFile(self::FIXTURE, self::source()),
        );

        sort($sites);

        return $sites;
    }

    /** @return list<string> `line|target|values`, the enumeration's own answer verbatim */
    private static function scanRawSites(): array
    {
        $sites = array_map(
            static fn(EnumeratedSite $site): string => \sprintf(
                '%d|%s|%s',
                $site->line,
                $site->target,
                $site->values,
            ),
            ThresholdDirectiveScan::overFile(self::FIXTURE, self::source()),
        );

        sort($sites);

        return $sites;
    }

    /**
     * What the product makes of one authored pair, on a docblock of its own.
     *
     * @return list<string> `line|target|reading`
     */
    private static function readingsOf(Node $node): array
    {
        $extractor = new ThresholdOverrideExtractor([]);
        $subject = MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString(self::FIXTURE)));
        $result = $extractor->extractWithDiagnostics($node, $subject, ControlScope::Class_);
        $sites = [];

        foreach ($result->overrides as $override) {
            $sites[] = \sprintf(
                '%d|%s|%s',
                $override->line,
                $override->rulePattern,
                self::thresholds($override->warning, $override->error),
            );
        }

        foreach ($result->diagnostics as $diagnostic) {
            $sites[] = \sprintf(
                '%d|%s|%s',
                $diagnostic->line,
                self::addressedBy($diagnostic->message),
                self::complaintIn($diagnostic->message),
            );
        }

        return $sites;
    }

    private static function productReadingOf(string $target, string $values): string
    {
        $line = $values === ''
            ? \sprintf('/** @qmx-threshold %s */', $target)
            : \sprintf('/** @qmx-threshold %s %s */', $target, $values);
        $node = new Node\Stmt\Nop();
        $node->setDocComment(new Doc($line, 1, 0));
        $node->setAttribute('startLine', 1);
        $node->setAttribute('endLine', 1);

        $readings = self::readingsOf($node);

        return \count($readings) === 1
            ? explode('|', $readings[0], 3)[2]
            : \sprintf('%d reading(s)', \count($readings));
    }

    private static function thresholds(int|float|null $warning, int|float|null $error): string
    {
        return \sprintf('warning=%s error=%s', var_export($warning, true), var_export($error, true));
    }

    /**
     * A diagnostic names the directive it refuses as `@qmx-threshold <target>:
     * <complaint>`. The split is on the colon *and a space*, because a target
     * may carry a colon of its own — `channel:level` is captured whole so that
     * it can be refused by name.
     */
    private static function addressedBy(string $message): string
    {
        $tagged = explode(' ', $message, 2)[1] ?? $message;

        return explode(': ', $tagged, 2)[0];
    }

    /** What the product complained about, which is where it quotes the values it read. */
    private static function complaintIn(string $message): string
    {
        $tagged = explode(' ', $message, 2)[1] ?? $message;

        return explode(': ', $tagged, 2)[1] ?? $message;
    }

    /**
     * Every node carrying a docblock, each docblock only once: php-parser can
     * hand the same comment to more than one node, and a site counted twice on
     * one side is a disagreement about nothing.
     *
     * @return list<Node>
     */
    private static function documentedNodes(): array
    {
        $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse(self::source()) ?? [];
        $seen = [];
        $nodes = [];

        foreach ((new NodeFinder())->find($parsed, static fn(Node $node): bool => $node->getDocComment() !== null) as $node) {
            $comment = $node->getDocComment();

            if ($comment === null) {
                continue;
            }

            $key = $comment->getStartLine() . ':' . $comment->getText();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * The declaration's own lines: its docblock, if it has one, through its
     * last line. The body is in range on purpose — a measure that starts
     * reading ordinary comments finds its site there, and a range stopping at
     * the docblock would hide it.
     *
     * @return array{0: int, 1: int}
     */
    private static function rangeOf(string $declaration): array
    {
        $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse(self::source()) ?? [];
        $methods = (new NodeFinder())->find(
            $parsed,
            static fn(Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $declaration,
        );

        if (\count($methods) !== 1) {
            self::fail(\sprintf('The fixture does not declare exactly one %s().', $declaration));
        }

        $method = $methods[0];
        $comment = $method->getDocComment();

        return [$comment?->getStartLine() ?? $method->getStartLine(), $method->getEndLine()];
    }

    /**
     * @param list<string> $sites
     *
     * @return list<string>
     */
    private static function within(array $sites, int $from, int $to): array
    {
        return array_values(array_filter($sites, static function (string $site) use ($from, $to): bool {
            $line = (int) explode('|', $site, 2)[0];

            return $line >= $from && $line <= $to;
        }));
    }

    /**
     * The line the fixture authors a target on, found in the source rather than
     * written down here: a line number in a test is stale the moment anyone
     * edits the fixture above it, and a stale expectation that still passes is
     * worse than none.
     */
    private static function lineAuthoring(string $target): int
    {
        $needle = '@qmx-threshold ' . $target;

        foreach (explode("\n", self::source()) as $offset => $line) {
            if (str_contains($line, $needle)) {
                return $offset + 1;
            }
        }

        self::fail(\sprintf('The fixture authors no directive addressing %s.', $target));
    }

    /**
     * Every method the fixture declares.
     *
     * @return list<Node\Stmt\ClassMethod>
     */
    private static function methods(): array
    {
        $parsed = (new ParserFactory())->createForNewestSupportedVersion()->parse(self::source()) ?? [];

        /** @var list<Node\Stmt\ClassMethod> $methods */
        $methods = (new NodeFinder())->find(
            $parsed,
            static fn(Node $node): bool => $node instanceof Node\Stmt\ClassMethod,
        );

        return $methods;
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    private static function temporaryTree(array $files): string
    {
        $root = sys_get_temp_dir() . '/qmx-scan-' . bin2hex(random_bytes(6));

        foreach ($files as $relative => $contents) {
            $path = $root . '/' . $relative;
            @mkdir(\dirname($path), 0o777, true);
            file_put_contents($path, $contents);
        }

        self::$trees[] = $root;

        return $root;
    }

    private static function source(): string
    {
        $path = \dirname(__DIR__, 3) . '/' . self::FIXTURE;
        $source = file_get_contents($path);

        if ($source === false) {
            self::fail('The fixture of authored forms is unreadable.');
        }

        return $source;
    }
}
