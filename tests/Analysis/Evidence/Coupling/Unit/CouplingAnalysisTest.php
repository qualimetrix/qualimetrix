<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingAnalysis;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;

#[CoversClass(CouplingAnalysis::class)]
final class CouplingAnalysisTest extends TestCase
{
    #[Test]
    public function itIsEmptyWhenNoFrameworkPrefixesAreConfigured(): void
    {
        $fn = $this->configured([]);

        self::assertTrue($fn->isEmpty());
    }

    #[Test]
    public function itIsNotEmptyWhenFrameworkPrefixesAreConfigured(): void
    {
        $fn = $this->configured(['Symfony']);

        self::assertFalse($fn->isEmpty());
    }

    #[Test]
    #[DataProvider('frameworkMatchingProvider')]
    public function itMatchesFrameworkPrefixesOnNamespaceBoundaries(
        string $fqcn,
        bool $expected,
    ): void {
        $fn = $this->configured(['Symfony', 'PhpParser', 'Psr']);

        self::assertSame($expected, $fn->isFramework($fqcn));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function frameworkMatchingProvider(): iterable
    {
        // Framework matches
        yield 'Symfony top-level' => ['Symfony\\Component\\Console', true];
        yield 'Symfony nested' => ['Symfony\\Component\\Console\\Command\\Command', true];
        yield 'PhpParser class' => ['PhpParser\\Node\\Expr', true];
        yield 'Psr interface' => ['Psr\\Log\\LoggerInterface', true];

        // Non-framework
        yield 'App namespace' => ['App\\Service\\UserService', false];
        yield 'PsrExtended should not match Psr' => ['PsrExtended\\Custom\\Class_', false];
        yield 'SymfonyBridge should not match Symfony' => ['SymfonyBridge\\Component', false];
        yield 'PhpParserExtra should not match PhpParser' => ['PhpParserExtra\\Node', false];
        yield 'empty string' => ['', false];
        yield 'global class' => ['stdClass', false];
    }

    #[Test]
    public function itMatchesASingleSegmentPrefixExactlyButNotAsASubstring(): void
    {
        $fn = $this->configured(['Psr']);

        // "Psr" alone as FQCN matches (exact match)
        self::assertTrue($fn->isFramework('Psr'));
        // "PsrLog" does NOT match (no backslash boundary)
        self::assertFalse($fn->isFramework('PsrLog'));
    }

    #[Test]
    public function itAppliesExactAndRegexFrameworkSelectorsWithoutReparsingThem(): void
    {
        $analysis = new CouplingAnalysis();
        $analysis->replace($analysis->resolve($this->document([[
            'coupling' => [
                'frameworkNamespaces' => [
                    ['exact' => 'Amp'],
                    ['regex' => 'Vendor\\\\(?:Http|Queue)(?:\\\\[^\\\\]+)*'],
                ],
            ],
        ]])));

        self::assertTrue($analysis->isFramework('Amp'));
        self::assertFalse($analysis->isFramework('Amp\\Future'));
        self::assertTrue($analysis->isFramework('Vendor\\Http\\Client'));
        self::assertTrue($analysis->isFramework('Vendor\\Queue'));
        self::assertFalse($analysis->isFramework('Vendor\\Database'));
    }

    #[Test]
    public function itNeverMatchesWhenNoFrameworkPrefixesAreConfigured(): void
    {
        $fn = $this->configured([]);

        self::assertFalse($fn->isFramework('Symfony\\Component\\Console'));
    }

    #[Test]
    public function itMatchesFrameworkNamespaceStringsAndRejectsNullOrEmpty(): void
    {
        $fn = $this->configured(['Symfony', 'PhpParser']);

        self::assertTrue($fn->isFrameworkNamespace('Symfony\\Component'));
        self::assertTrue($fn->isFrameworkNamespace('PhpParser\\Node'));
        self::assertFalse($fn->isFrameworkNamespace('App\\Service'));
        self::assertFalse($fn->isFrameworkNamespace(null));
        self::assertFalse($fn->isFrameworkNamespace(''));
    }

    #[Test]
    public function itUsesTheLastFrameworkNamespaceContribution(): void
    {
        $analysis = new CouplingAnalysis();
        $analysis->replace($analysis->resolve($this->document([
            ['coupling' => ['frameworkNamespaces' => [['subtree' => 'Symfony']]]],
            ['coupling' => ['frameworkNamespaces' => [['subtree' => 'Psr']]]],
        ])));

        self::assertFalse($analysis->isFramework('Symfony\\Component\\Console'));
        self::assertTrue($analysis->isFramework('Psr\\Log\\LoggerInterface'));
    }

    #[Test]
    public function itReplacesPrefixesWithAnEmptyContribution(): void
    {
        $analysis = $this->configured(['Symfony']);

        $analysis->replace($analysis->resolve($this->document([
            ['coupling' => ['frameworkNamespaces' => []]],
        ])));

        self::assertTrue($analysis->isEmpty());
        self::assertFalse($analysis->isFramework('Symfony\\Component\\Console'));
    }

    #[Test]
    public function itRetainsThePreviousPrefixesWhenConfigurationIsInvalid(): void
    {
        $analysis = $this->configured(['Symfony']);

        try {
            $analysis->resolve($this->document([
                ['coupling' => ['frameworkNamespaces' => [['subtree' => 'Psr'], 1]]],
            ]));
            self::fail('Invalid framework namespace configuration must fail.');
        } catch (ConfigurationRefusal) {
        }

        self::assertTrue($analysis->isFramework('Symfony\\Component\\Console'));
        self::assertFalse($analysis->isFramework('Psr\\Log\\LoggerInterface'));
    }

    /**
     * The question the unmatched-framework-namespace channel asks: which of
     * the declared prefixes did the run's own names never fall under.
     */
    #[Test]
    public function itNamesEveryPrefixNoNameFellUnder(): void
    {
        $fn = $this->configured(['Symfony', 'Nope\\Missing', 'Doctrine\\ORM']);

        self::assertSame(
            ['subtree:Nope\\Missing', 'subtree:Doctrine\\ORM'],
            self::displays($fn->unboundSelectors(['Symfony\\Component\\Console\\Command\\Command', 'App\\Service'])),
        );
    }

    /** Order follows the declaration, so a report reads like the config file. */
    #[Test]
    public function itKeepsDeclarationOrderAmongTheUnboundPrefixes(): void
    {
        $fn = $this->configured(['Zeta\\Missing', 'Alpha\\Missing']);

        self::assertSame(['subtree:Zeta\\Missing', 'subtree:Alpha\\Missing'], self::displays($fn->unboundSelectors([])));
    }

    /**
     * The exact-name and boundary cases {@see CouplingAnalysis::isFramework()}
     * accepts must bind here too — the two answers come from one predicate,
     * and a caller that re-spelled it could disagree with the metric.
     */
    #[Test]
    public function itBindsOnTheSameBoundaryIsFrameworkMatchesOn(): void
    {
        self::assertSame([], $this->configured(['Symfony'])->unboundSelectors(['Symfony']));
        self::assertSame([], $this->configured(['Symfony'])->unboundSelectors(['Symfony\\Console']));
        self::assertSame(
            ['subtree:Symfony'],
            self::displays($this->configured(['Symfony'])->unboundSelectors(['SymfonyBundle\\Thing'])),
            'A prefix that is only a string prefix binds nothing, exactly as it classifies nothing.',
        );
    }

    /** A prefix written twice is one mistake, and is reported once. */
    #[Test]
    public function itReportsARepeatedPrefixOnce(): void
    {
        self::assertSame(
            ['subtree:Nope\\Missing'],
            self::displays($this->configured(['Nope\\Missing', 'Nope\\Missing'])->unboundSelectors(['App\\Service'])),
        );
    }

    /** Nothing declared is not a prefix that failed. */
    #[Test]
    public function itNamesNoPrefixWhenNoneAreConfigured(): void
    {
        self::assertSame([], $this->configured([])->unboundSelectors(['App\\Service']));
    }

    /** @param list<string> $prefixes */
    private function configured(array $prefixes): CouplingAnalysis
    {
        $analysis = new CouplingAnalysis();
        $analysis->replace($analysis->resolve($this->document([
            ['coupling' => ['frameworkNamespaces' => array_map(static fn(string $prefix): array => ['subtree' => $prefix], $prefixes)]],
        ])));

        return $analysis;
    }

    /** @param list<array<string, mixed>> $contributions */
    private function document(array $contributions): ConfigurationDocument
    {
        return new ConfigurationDocument(array_map(
            static fn(array $values): array => ['source' => 'test', 'values' => $values],
            $contributions,
        ), AbsolutePath::fromString('/project'));
    }

    /**
     * @param list<NamespacePattern> $patterns
     *
     * @return list<string>
     */
    private static function displays(array $patterns): array
    {
        return array_map(static fn(NamespacePattern $pattern): string => $pattern->definition->display(), $patterns);
    }
}
