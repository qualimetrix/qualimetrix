<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingAnalysis;

#[CoversClass(CouplingAnalysis::class)]
final class CouplingAnalysisTest extends TestCase
{
    #[Test]
    public function isEmpty_returnsTrueWhenNoPrefixes(): void
    {
        $fn = $this->configured([]);

        self::assertTrue($fn->isEmpty());
    }

    #[Test]
    public function isEmpty_returnsFalseWhenPrefixesExist(): void
    {
        $fn = $this->configured(['Symfony']);

        self::assertFalse($fn->isEmpty());
    }

    #[Test]
    #[DataProvider('frameworkMatchingProvider')]
    public function isFramework_matchesBoundaryAware(
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
    public function isFramework_exactMatchForSingleSegment(): void
    {
        $fn = $this->configured(['Psr']);

        // "Psr" alone as FQCN matches (exact match)
        self::assertTrue($fn->isFramework('Psr'));
        // "PsrLog" does NOT match (no backslash boundary)
        self::assertFalse($fn->isFramework('PsrLog'));
    }

    #[Test]
    public function isFramework_returnsFalseWhenEmpty(): void
    {
        $fn = $this->configured([]);

        self::assertFalse($fn->isFramework('Symfony\\Component\\Console'));
    }

    #[Test]
    public function isFrameworkNamespace_matchesNamespaceStrings(): void
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
        $analysis->configure(new ConfigurationDocument([
            ['coupling' => ['frameworkNamespaces' => ['Symfony']]],
            ['coupling' => ['frameworkNamespaces' => ['Psr']]],
        ]));

        self::assertFalse($analysis->isFramework('Symfony\\Component\\Console'));
        self::assertTrue($analysis->isFramework('Psr\\Log\\LoggerInterface'));
    }

    #[Test]
    public function itReplacesPrefixesWithAnEmptyContribution(): void
    {
        $analysis = $this->configured(['Symfony']);

        $analysis->configure(new ConfigurationDocument([
            ['coupling' => ['frameworkNamespaces' => []]],
        ]));

        self::assertTrue($analysis->isEmpty());
        self::assertFalse($analysis->isFramework('Symfony\\Component\\Console'));
    }

    #[Test]
    public function itRetainsThePreviousPrefixesWhenConfigurationIsInvalid(): void
    {
        $analysis = $this->configured(['Symfony']);

        try {
            $analysis->configure(new ConfigurationDocument([
                ['coupling' => ['frameworkNamespaces' => ['Psr', 1]]],
            ]));
            self::fail('Invalid framework namespace configuration must fail.');
        } catch (InvalidArgumentException) {
        }

        self::assertTrue($analysis->isFramework('Symfony\\Component\\Console'));
        self::assertFalse($analysis->isFramework('Psr\\Log\\LoggerInterface'));
    }

    /** @param list<string> $prefixes */
    private function configured(array $prefixes): CouplingAnalysis
    {
        $analysis = new CouplingAnalysis();
        $analysis->configure(new ConfigurationDocument([
            ['coupling' => ['frameworkNamespaces' => $prefixes]],
        ]));

        return $analysis;
    }
}
