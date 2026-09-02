<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\RuleVocabulary;

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
 * live tree unmoved. Six of the cases below exist for that reason alone.
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

    public static function setUpBeforeClass(): void
    {
        foreach (['EnumeratedSite', 'ThresholdDirectiveScan'] as $part) {
            require_once \dirname(__DIR__, 3) . '/scripts/directive-audit/' . $part . '.php';
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}> declaration => the targets it authors
     */
    public static function provideAuthoredForms(): iterable
    {
        yield 'plain' => ['plain', ['plain.simple']];

        yield 'glued to the docblock star' => ['gluedToTheStar', ['glued.star']];

        yield 'tag with a suffix' => ['suffixedWord', []];

        yield 'backticked' => ['backticked', []];

        yield 'after a multiline backtick region' => ['afterMultilineBacktickRegion', ['after.backticks']];

        yield 'outside a docblock' => ['outsideADocblock', []];

        yield 'two on one line' => ['twoOnOneLine', ['first.of.two']];

        yield 'target cut at a call' => ['targetCutAtACall', ['paren.call']];

        yield 'target wrapped in parens' => ['targetWrappedInParens', []];

        yield 'star' => ['targetWithAStar', ['class.star.*']];

        yield 'hash' => ['targetWithAHash', ['class.hash#code']];

        yield 'colon' => ['targetWithAColon', ['class.colon:level']];

        yield 'digit' => ['targetWithADigit', ['class.digit9']];

        yield 'underscore' => ['targetWithAnUnderscore', ['class_underscore']];

        yield 'capital' => ['targetWithACapital', ['Class.Upper']];
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
     * @param list<string> $targets
     */
    #[Test]
    #[DataProvider('provideAuthoredForms')]
    public function itReadsAnAuthoredFormTheWayTheProductDoes(string $declaration, array $targets): void
    {
        $expected = array_map(
            static fn(string $target): string => \sprintf('%d:%s', self::lineAuthoring($target), $target),
            $targets,
        );
        [$from, $to] = self::rangeOf($declaration);

        self::assertSame($expected, self::within(self::productSites(), $from, $to), 'the product read it differently');
        self::assertSame($expected, self::within(self::scanSites(), $from, $to), 'the enumeration read it differently');
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
     * What the product recognised, overrides and diagnostics alike.
     *
     * A directive whose values do not parse produces a diagnostic instead of an
     * override, and it is still a site the author wrote and the enumeration
     * lists — `paren.call(x) 30` is exactly that. Reading only the overrides
     * would make the two measures disagree over a form neither of them
     * misread.
     *
     * @return list<string> `line:target`, in the order the file authors them
     */
    private static function productSites(): array
    {
        $extractor = new ThresholdOverrideExtractor([]);
        $subject = MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString(self::FIXTURE)));
        $sites = [];

        foreach (self::documentedNodes() as $node) {
            $result = $extractor->extractWithDiagnostics($node, $subject, ControlScope::Class_);

            foreach ($result->overrides as $override) {
                $sites[] = \sprintf('%d:%s', $override->line, $override->rulePattern);
            }

            foreach ($result->diagnostics as $diagnostic) {
                $sites[] = \sprintf('%d:%s', $diagnostic->line, self::addressedBy($diagnostic->message));
            }
        }

        sort($sites);

        return $sites;
    }

    /** @return list<string> `line:target` */
    private static function scanSites(): array
    {
        $sites = array_map(
            static fn(EnumeratedSite $site): string => \sprintf('%d:%s', $site->line, $site->target),
            ThresholdDirectiveScan::overFile(self::FIXTURE, self::source()),
        );

        sort($sites);

        return $sites;
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
            $line = (int) explode(':', $site, 2)[0];

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
