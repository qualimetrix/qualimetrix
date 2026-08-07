<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Infrastructure\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Rule\RuleNameReader;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use RuntimeException;

/**
 * Drift guard between the production container's STATIC channel
 * declarations and the tracked fixture at `tests/Fixtures/Channels/declared.txt`.
 *
 * The fixture is the oracle, not this test's own expectations and not the
 * integration suite's coverage: a channel a rule declares but the fixture
 * doesn't list fails, and a fixture line naming a channel no rule declares
 * any more also fails. Both directions are real drift — see
 * ADR 0017 for why a suite-only guard
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
     * Structural invariant, independent of {@see ChannelEmissionStaticGuardTest}:
     * the `violationCode` half of a declared key must either be the
     * `ruleName` half verbatim, or that same string with a `.suffix`
     * appended. This is what {@see ViolationChannel::fromKey()}'s own
     * "full key, not a bare code" design implies but does not itself
     * enforce, and it is exactly the shape a hand-typed declaration line
     * can violate silently — a `ruleName` half misspelled relative to its
     * own `violationCode` half would otherwise pass the drift guard above
     * (which only compares declarations against the fixture, not against
     * this shape) as long as the fixture line matches the wrong
     * declaration.
     */
    #[Test]
    public function everyDeclaredViolationCodeEqualsOrIsPrefixedByItsRuleName(): void
    {
        $violations = [];

        foreach (self::realStaticDeclarations() as $key => $declaration) {
            $channel = ViolationChannel::fromKey($key);

            $matches = $channel->violationCode === $channel->ruleName
                || str_starts_with($channel->violationCode, $channel->ruleName . '.');

            if (!$matches) {
                $violations[] = $key;
            }
        }

        self::assertSame(
            [],
            $violations,
            \sprintf(
                'Declared key(s) whose violationCode is neither equal to nor prefixed by "<ruleName>.": %s',
                implode(', ', $violations),
            ),
        );
    }

    /**
     * Structural invariant: the `ruleName` half of every declared key must
     * name something that actually emits under that name — either a real
     * rule's `NAME` constant, or one of {@see LayerViolationRule}'s four
     * `*_DIAGNOSTIC_NAME` constants (it emits those under names other than
     * its own `NAME`). A `ruleName` half that matches neither would mean
     * the declaration addresses a channel no rule class can ever produce —
     * a typo this guard, unlike the drift guard above, can catch even when
     * the typo is consistent between the fixture and the declaration.
     */
    #[Test]
    public function everyDeclaredRuleNameHalfNamesARealRuleOrALayerViolationDiagnostic(): void
    {
        $knownRuleNames = self::allRuleNames();
        $violations = [];

        foreach (self::realStaticDeclarations() as $key => $declaration) {
            $channel = ViolationChannel::fromKey($key);

            if (!\in_array($channel->ruleName, $knownRuleNames, true)) {
                $violations[] = $key;
            }
        }

        self::assertSame(
            [],
            $violations,
            \sprintf(
                'Declared key(s) whose ruleName half names neither a real rule\'s NAME nor a'
                . ' LayerViolationRule diagnostic constant: %s',
                implode(', ', $violations),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private static function allRuleNames(): array
    {
        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        $names = [];
        foreach ($registry->getClasses() as $ruleClass) {
            $names[] = RuleNameReader::read($ruleClass);
        }

        $names[] = LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME;
        $names[] = LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME;
        $names[] = LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME;
        $names[] = LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME;

        return $names;
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
