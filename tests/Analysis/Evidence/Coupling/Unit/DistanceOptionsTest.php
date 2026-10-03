<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(DistanceOptions::class)]
final class DistanceOptionsTest extends TestCase
{
    #[Test]
    public function itDecodesAnAuthoredSelectorIntoTheTypedNamespacePattern(): void
    {
        $options = DistanceOptions::fromResolved(ResolvedOptionsFixture::values(DistanceOptions::class, ['include_namespaces' => [['subtree' => 'App\\Service']]]));

        self::assertSame(['subtree:App\\Service'], self::displays($options->includeNamespaces));
    }

    #[Test]
    public function itDecodesAYamlSelectorList(): void
    {
        $options = DistanceOptions::fromResolved(ResolvedOptionsFixture::values(DistanceOptions::class, [
            'include_namespaces' => [
                ['subtree' => 'App\\Service'],
                ['regex' => 'App\\\\(?:Domain|Model)(?:\\\\[^\\\\]+)*'],
            ],
        ]));

        self::assertSame(
            ['subtree:App\\Service', 'regex:App\\\\(?:Domain|Model)(?:\\\\[^\\\\]+)*'],
            self::displays($options->includeNamespaces),
        );
    }

    #[Test]
    public function itLeavesIncludeNamespacesNullWhenAbsent(): void
    {
        $options = DistanceOptions::fromResolved(ResolvedOptionsFixture::values(DistanceOptions::class, []));

        self::assertNull($options->includeNamespaces);
    }

    #[Test]
    public function itRefusesABareYamlString(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('"rules.fixture.include_namespaces[0]" in configuration file "/project/qmx.yaml" must be a map, got string.');

        DistanceOptions::fromResolved(ResolvedOptionsFixture::values(DistanceOptions::class, ['include_namespaces' => ['App\\Service']]));
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
