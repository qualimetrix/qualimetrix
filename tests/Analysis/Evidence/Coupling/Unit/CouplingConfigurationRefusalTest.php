<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingAnalysis;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;

/**
 * These refusals were bare `InvalidArgumentException`s: exit 3 without the
 * product's framing, so a caller matching on the framing saw a crash instead
 * of a refusal.
 */
final class CouplingConfigurationRefusalTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function provideUnexecutableValues(): iterable
    {
        yield 'the root as a list' => [['x']];
        yield 'the leaf as a boolean' => [['frameworkNamespaces' => true]];
        yield 'the leaf as an integer' => [['frameworkNamespaces' => 7331]];
        yield 'the leaf as a map' => [['frameworkNamespaces' => ['a' => ['subtree' => 'Zzz\\Nope']]]];
        yield 'a non-string entry' => [['frameworkNamespaces' => [7331]]];
        yield 'a bare string entry' => [['frameworkNamespaces' => ['Zzz\\Nope']]];
    }

    #[Test]
    #[DataProvider('provideUnexecutableValues')]
    public function itRefusesWithTheProductFraming(mixed $value): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve($value);
    }

    #[Test]
    public function itStillAcceptsAListOfNamespacePrefixes(): void
    {
        self::assertSame(
            ['subtree:Zzz\\Nope'],
            self::displays(self::resolve(['frameworkNamespaces' => [['subtree' => 'Zzz\\Nope']]])),
        );
    }

    #[Test]
    public function itStillAcceptsAnEmptyList(): void
    {
        self::assertSame([], self::resolve(['frameworkNamespaces' => []]));
    }

    /** @return list<\Qualimetrix\Core\Pattern\NamespacePattern> */
    private static function resolve(mixed $value): array
    {
        $document = new ConfigurationDocument(
            [['source' => 'config', 'values' => ['coupling' => $value]]],
            AbsolutePath::fromString('/project'),
        );

        return (new CouplingAnalysis())->resolve($document);
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
