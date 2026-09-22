<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub;

#[CoversClass(DistanceOptions::class)]
final class DistanceOptionsTest extends TestCase
{
    #[Test]
    public function itAcceptsATypedCliSelector(): void
    {
        $options = DistanceOptions::fromArray(['include_namespaces' => NamespacePatternStub::subtree('App\\Service')]);

        self::assertSame(['subtree:App\\Service'], self::displays($options->includeNamespaces));
    }

    #[Test]
    public function itDecodesAYamlSelectorList(): void
    {
        $options = DistanceOptions::fromArray([
            'include_namespaces' => [
                ['subtree' => 'App\\Service'],
                ['regex' => 'App\\\\(?:Domain|Model)(?:\\\\[^\\\\]+)*'],
            ],
        ]);

        self::assertSame(
            ['subtree:App\\Service', 'regex:App\\\\(?:Domain|Model)(?:\\\\[^\\\\]+)*'],
            self::displays($options->includeNamespaces),
        );
    }

    #[Test]
    public function itLeavesIncludeNamespacesNullWhenAbsent(): void
    {
        $options = DistanceOptions::fromArray([]);

        self::assertNull($options->includeNamespaces);
    }

    #[Test]
    public function itRefusesABareYamlString(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('bare strings are not supported');

        DistanceOptions::fromArray(['include_namespaces' => ['App\\Service']]);
    }

    /**
     * @param list<NamespacePattern>|null $patterns
     *
     * @return list<string>
     */
    private static function displays(?array $patterns): array
    {
        self::assertNotNull($patterns);

        return array_map(static fn(NamespacePattern $pattern): string => $pattern->definition->display(), $patterns);
    }
}
