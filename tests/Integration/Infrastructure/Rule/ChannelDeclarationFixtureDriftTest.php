<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Infrastructure\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistryInterface;
use RuntimeException;

/**
 * Drift guard between the production container's STATIC channel
 * declarations and the tracked fixture at `tests/Fixtures/Channels/declared.txt`.
 *
 * The fixture is the oracle, not this test's own expectations and not the
 * integration suite's coverage: a channel a rule declares but the fixture
 * doesn't list fails, and a fixture line naming a channel no rule declares
 * any more also fails. Both directions are real drift — see
 * `docs/plan/baseline-ceiling-v10.md` §5.4/P1 for why a suite-only guard
 * would silently narrow to whatever tests happen to exercise.
 *
 * Deliberately scoped to {@see ChannelDeclarationRegistryInterface::staticDeclarations()},
 * never to {@see ChannelDeclarationRegistryInterface::declarationFor()}: with
 * any `computed_metrics:` configured, the declared set would contain
 * channels this file — a fixed line list — could never enumerate. The
 * open `computed.*`/`health.*` family is guarded separately by
 * {@see \Qualimetrix\Tests\Unit\Infrastructure\Rule\ChannelDeclarationRegistryTest}'s
 * run-time resolution cases.
 */
#[CoversClass(ChannelDeclarationRegistryInterface::class)]
final class ChannelDeclarationFixtureDriftTest extends TestCase
{
    #[Test]
    public function everyStaticallyDeclaredChannelIsListedInTheFixture(): void
    {
        $actual = self::realStaticDeclarations();
        $expected = self::readFixture();

        foreach ($actual as $key => $declaration) {
            self::assertArrayHasKey(
                $key,
                $expected,
                \sprintf(
                    'Channel "%s" is statically declared in code but missing from tests/Fixtures/Channels/declared.txt.'
                    . ' Add a line for it — this fixture, not the test suite, is what the drift guard trusts.',
                    $key,
                ),
            );
            self::assertEquals(
                $expected[$key],
                $declaration,
                \sprintf('Fixture line for "%s" does not match the declaration the code actually registers.', $key),
            );
        }
    }

    #[Test]
    public function everyFixtureLineIsStillDeclaredInCode(): void
    {
        $actual = self::realStaticDeclarations();

        foreach (self::readFixture() as $key => $declaration) {
            self::assertArrayHasKey(
                $key,
                $actual,
                \sprintf(
                    'tests/Fixtures/Channels/declared.txt lists "%s", but no rule declares it any more — remove the'
                    . ' stale line (or move it to excluded.txt if the channel now deliberately declares no baseline'
                    . ' support).',
                    $key,
                ),
            );
        }
    }

    /**
     * @return array<string, ChannelDeclaration>
     */
    private static function realStaticDeclarations(): array
    {
        $registry = (new ContainerFactory())->create()->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        return $registry->staticDeclarations();
    }

    /**
     * @return array<string, ChannelDeclaration>
     */
    private static function readFixture(): array
    {
        $path = \dirname(__DIR__, 4) . '/tests/Fixtures/Channels/declared.txt';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read fixture file %s.', $path));
        }

        $declarations = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            self::assertNotFalse($parts, \sprintf('Malformed fixture line: "%s".', $line));
            self::assertCount(2, $parts, \sprintf('Malformed fixture line: "%s".', $line));

            [$channelKey, $shapeSpec] = $parts;

            $declarations[$channelKey] = self::parseShapeSpec($shapeSpec, $channelKey);
        }

        return $declarations;
    }

    private static function parseShapeSpec(string $shapeSpec, string $channelKey): ChannelDeclaration
    {
        if ($shapeSpec === 'occurrence') {
            return ChannelDeclaration::occurrence();
        }

        if (str_starts_with($shapeSpec, 'magnitude:')) {
            $direction = substr($shapeSpec, \strlen('magnitude:'));

            return match ($direction) {
                'higher' => ChannelDeclaration::magnitude(WorseDirection::Higher),
                'lower' => ChannelDeclaration::magnitude(WorseDirection::Lower),
                default => throw new RuntimeException(\sprintf(
                    'Unknown direction "%s" for channel "%s" in the fixture.',
                    $direction,
                    $channelKey,
                )),
            };
        }

        throw new RuntimeException(\sprintf('Unknown shape spec "%s" for channel "%s" in the fixture.', $shapeSpec, $channelKey));
    }
}
