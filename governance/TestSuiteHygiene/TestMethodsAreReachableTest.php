<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What PHPUnit runs and what the convention allows are the same set.
 *
 * These are two different questions and the guard asks both. PHPUnit runs a
 * public, non-abstract method that either carries `#[Test]` or is named
 * `test…`; the convention allows a public method that is named `itXxx` **and**
 * carries `#[Test]`. Every way the two answers can differ is silent on its own,
 * so each is refused with its own sentence:
 *
 * - named `itXxx` without the attribute — never called, and nothing says so.
 *   Found in the tree as a CLI-alias contract that had never executed since the
 *   day it was written;
 * - not public although it reads as a test — PHPUnit runs only public methods,
 *   so a visibility change during a refactor retires a case in silence;
 * - public and named `test…` — PHPUnit does run it, under the legacy prefix
 *   this repository does not use, so it passes unread by the convention;
 * - carrying the attribute under a name no reader recognises as a case.
 *
 * A method that is neither named nor attributed as a test is a helper, and
 * helpers are not this guard's business at any visibility. **A helper named
 * `itXxx` is not allowed, though**, whatever its visibility: the name is
 * reserved for cases here, and a lost case and a misnamed helper are
 * indistinguishable from outside the author's head. The visibility branch is
 * checked first so that one method yields one diagnosis rather than a second
 * one after the first is obeyed.
 *
 * **The legacy-prefix branch applies only to classes that reach a test base.**
 * A `*Test.php` may declare spies, fixture rules and traits beside its test
 * class, and PHPUnit runs none of them; a helper there named `testable()` is
 * not running under any prefix. Ancestry is resolved over the corpus rather
 * than assumed, and `PHPUnit\Framework\TestCase` is not the only base in this
 * tree — see {@see TestTree::looksExecutable()}.
 *
 * **The attribute is read from the syntax tree, not from reflection.** The tree
 * still carries test files whose namespace does not follow their path, so
 * loading a class by its expected name would skip exactly the files most likely
 * to be wrong. Parsing reads every file the same way and needs no autoloader.
 *
 * **Scope is {@see TestTree}'s corpus**, the PSR-4 dev roots taken from
 * `composer.json`. That is what makes a `*Test.php` a test class rather than a
 * fixture: the corpus files the finding gate analyses, and the tooling helpers
 * under `scripts/`, live outside those roots and are deliberately not judged by
 * this rule.
 */
final class TestMethodsAreReachableTest extends TestCase
{
    #[Test]
    public function itFindsNoTestMethodWhoseNameAndAttributeDisagree(): void
    {
        $violations = [];

        $index = TestTree::corpusIndex();
        foreach (TestTree::testFiles() as $path) {
            foreach (self::violationsIn($path, TestTree::read($path), $index) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame([], $violations, \sprintf(
            "%d test method(s) are named, attributed or scoped so that PHPUnit and the reader disagree.\n"
            . "A case is public, named itXxx and carries #[Test]; anything else is a helper:\n%s",
            \count($violations),
            implode("\n", $violations),
        ));
    }

    /**
     * Proves the rule refuses at all, in every direction, without waiting for
     * the tree to break: a scan that reads nothing reports nothing either, and
     * would stay green forever.
     */
    #[Test]
    public function itRefusesEachDirectionOnSourceItIsGiven(): void
    {
        $source = <<<'PHP'
            <?php

            namespace Acme\Probe;

            use PHPUnit\Framework\Attributes\Test as RenamedAttribute;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[RenamedAttribute]
                public function itRuns(): void
                {
                }

                public function itIsNeverCalled(): void
                {
                }

                #[RenamedAttribute]
                public function runsUnderTheWrongName(): void
                {
                }

                public function provideCases(): array
                {
                    return [];
                }

                private function collectFixtures(): void
                {
                }

                private function itIsAPrivateHelper(): void
                {
                }

                #[RenamedAttribute]
                protected function itLostItsVisibility(): void
                {
                }

                public function testUnderTheLegacyPrefix(): void
                {
                }
            }

            final class ProbeFixture
            {
                public function testable(): void
                {
                }
            }
            PHP;

        $declarations = TestTree::parseDeclarations('probe/ProbeTest.php', $source)['declarations'];
        $violations = self::violationsIn('probe/ProbeTest.php', $source, $declarations);

        self::assertSame(
            [
                'probe/ProbeTest.php:15 Acme\Probe\ProbeTest::itIsNeverCalled()'
                . ' is named itXxx but carries no #[Test] attribute, so PHPUnit never calls it',
                'probe/ProbeTest.php:19 Acme\Probe\ProbeTest::runsUnderTheWrongName()'
                . ' carries #[Test] but is not named itXxx',
                'probe/ProbeTest.php:33 Acme\Probe\ProbeTest::itIsAPrivateHelper()'
                . ' is named itXxx or carries #[Test] but is not public, so PHPUnit never calls it',
                'probe/ProbeTest.php:37 Acme\Probe\ProbeTest::itLostItsVisibility()'
                . ' is named itXxx or carries #[Test] but is not public, so PHPUnit never calls it',
                'probe/ProbeTest.php:42 Acme\Probe\ProbeTest::testUnderTheLegacyPrefix()'
                . ' is public and named test..., so PHPUnit runs it under the legacy prefix'
                . ' instead of the itXxx convention',
            ],
            $violations,
            'ProbeFixture::testable() is public and prefixed, and PHPUnit runs no case in a class'
            . ' that reaches no test base — so it is absent from this list.',
        );
    }

    /**
     * Proves the scan reaches every root it claims, and names the two that
     * exist today as a floor rather than a ceiling: a root added to
     * `autoload-dev` joins the scan on its own, but neither of these may
     * silently drop out of it.
     */
    #[Test]
    public function itReadsEveryTestRootItJudges(): void
    {
        $perRoot = [];

        foreach (TestTree::roots() as $root) {
            $perRoot[$root] = \count(TestTree::testFilesIn($root));
        }

        self::assertArrayHasKey('tests', $perRoot);
        self::assertArrayHasKey('governance', $perRoot);
        self::assertGreaterThan(500, $perRoot['tests']);
        self::assertGreaterThan(0, $perRoot['governance']);
        self::assertGreaterThan(500, \count(TestTree::testFiles()));
    }

    /**
     * The index is the corpus ancestry map, or one built from this source alone
     * when the caller hands over a probe.
     *
     * @param array<string, array{concrete: bool, declaresCase: bool, parents: list<string>}> $index
     *
     * @return list<string> one line per offending method, each naming the file,
     *                      the line, the class and the method
     */
    private static function violationsIn(string $displayPath, string $code, array $index): array
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        if ($statements === null) {
            throw new LogicException('Unable to parse ' . $displayPath);
        }

        $collector = new class extends NodeVisitorAbstract {
            /** @var list<array{class: string, method: ClassMethod}> */
            public array $methods = [];

            public function enterNode(Node $node): null
            {
                if (!$node instanceof ClassLike || $node->name === null) {
                    return null;
                }

                $name = $node->namespacedName?->toString() ?? $node->name->toString();
                foreach ($node->getMethods() as $method) {
                    $this->methods[] = ['class' => $name, 'method' => $method];
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($collector);
        $traverser->traverse($statements);

        $violations = [];
        foreach ($collector->methods as ['class' => $class, 'method' => $method]) {
            $complaint = self::complaintAbout($method, TestTree::reachesATestBaseClass($class, $index));
            if ($complaint === null) {
                continue;
            }

            $violations[] = \sprintf(
                '%s:%d %s::%s() %s',
                $displayPath,
                $method->getStartLine(),
                $class,
                $method->name->toString(),
                $complaint,
            );
        }

        return $violations;
    }

    /** The one thing wrong with this method, or null when nothing is. */
    private static function complaintAbout(ClassMethod $method, bool $inATestClass): ?string
    {
        $named = preg_match('/^it[A-Z]/', $method->name->toString()) === 1;
        $attributed = TestTree::carriesTestAttribute($method);

        // Visibility first: a non-public method is unreachable whatever else is
        // true of it, and diagnosing the attribute first would send the reader
        // back here after they added one.
        if (($named || $attributed) && !$method->isPublic()) {
            return 'is named itXxx or carries #[Test] but is not public, so PHPUnit never calls it';
        }

        if ($named && !$attributed) {
            return 'is named itXxx but carries no #[Test] attribute, so PHPUnit never calls it';
        }

        if ($inATestClass && TestTree::isExecutableMethod($method) && TestTree::carriesLegacyTestPrefix($method)) {
            return 'is public and named test..., so PHPUnit runs it under the legacy prefix'
                . ' instead of the itXxx convention';
        }

        if ($attributed && !$named) {
            return 'carries #[Test] but is not named itXxx';
        }

        return null;
    }
}
