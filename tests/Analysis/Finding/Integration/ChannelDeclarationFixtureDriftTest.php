<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Core\Observation\WorseDirection;
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
 * {@see \Qualimetrix\Tests\Infrastructure\Unit\ChannelUniverseTest}'s
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
     * rule's `NAME` constant, or one of {@see LayerViolationRule}'s five
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
     * The reclassification itself, pinned as a closed list.
     *
     * Eight channels — and only eight — report a configuration mistake rather
     * than code debt: the five layer-policy diagnostics and the three
     * inline-directive diagnostics. The count is load-bearing in both
     * directions: a ninth would mean something acquired an
     * unacceptable-as-debt status without the argument for it, and a missing
     * one would mean a diagnostic drifted back to being ratchetable.
     *
     * The two negative assertions name the sibling channels of the very same
     * two rules, because those are the ones a future edit is most likely to
     * sweep along by analogy: a forbidden dependency edge is real code debt,
     * and a suppression that stopped firing is ordinary cleanup.
     */
    #[Test]
    public function exactlyTheLayerPolicyAndDirectiveDiagnosticsDeclareAConfigurationError(): void
    {
        $configurationErrors = [];

        foreach (self::realStaticDeclarations() as $key => $declaration) {
            if ($declaration->acceptability === ChannelAcceptability::ConfigurationError) {
                $configurationErrors[] = $key;
            }
        }

        sort($configurationErrors);

        self::assertSame(
            [
                'annotation.invalid-threshold#annotation.invalid-threshold',
                'annotation.unresolved-directive#annotation.unresolved-directive',
                'annotation.unsupported-threshold#annotation.unsupported-threshold',
                'architecture.coverage#architecture.coverage',
                'architecture.empty-template#architecture.empty-template',
                'architecture.pending-layer-matched#architecture.pending-layer-matched',
                'architecture.potential-shadow#architecture.potential-shadow',
                'architecture.unreachable-layer#architecture.unreachable-layer',
            ],
            $configurationErrors,
        );

        self::assertNotContains(
            'architecture.layer-violation#architecture.layer-violation',
            $configurationErrors,
            'architecture.layer-violation reports a forbidden dependency edge — real code debt a project may'
            . ' ratchet down. It is not a configuration error and must stay baselineable.',
        );

        self::assertNotContains(
            'annotation.unused-directive#annotation.unused-directive',
            $configurationErrors,
            'annotation.unused-directive reports a suppression that stopped firing — normal debt cleanup, not a'
            . ' mistake. Classifying it as a configuration error would fail every project that fixed a violation'
            . ' and left the annotation behind.',
        );
    }

    /**
     * The other direction of the exclusion fixture.
     *
     * {@see ChannelEmissionStaticGuardTest} checks that an emitted channel is
     * either declared or listed in `excluded.txt`; nothing checked that a
     * line in `excluded.txt` still names a channel the registry does not
     * declare. A stale exclusion is the silent failure mode of that pair: the
     * channel gains a declaration, the guard stops caring about it, and the
     * file goes on asserting a reason that no longer applies.
     */
    #[Test]
    public function noExcludedFixtureLineNamesADeclaredChannel(): void
    {
        $declared = self::realStaticDeclarations();
        $stale = [];

        foreach (self::readExcludedFixtureKeys() as $key) {
            if (isset($declared[$key])) {
                $stale[] = $key;
            }
        }

        self::assertSame(
            [],
            $stale,
            \sprintf(
                'excluded.txt claims these channels declare no baseline support, but the registry now declares'
                . ' them — remove the stale exclusion line(s): %s',
                implode(', ', $stale),
            ),
        );
    }

    /**
     * @return list<string>
     */
    private static function readExcludedFixtureKeys(): array
    {
        $path = \dirname(__DIR__) . '/Fixtures/Channels/excluded.txt';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read fixture file %s.', $path));
        }

        $keys = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+--\s+/', $line, 2);
            $keys[] = trim($parts === false ? $line : $parts[0]);
        }

        return $keys;
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
        $names[] = LayerViolationRule::UNASSIGNED_CLASS_DIAGNOSTIC_NAME;
        $names[] = LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME;
        $names[] = LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME;
        $names[] = LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME;
        $names[] = LayerViolationRule::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME;

        $names[] = InlineDirectivePolicyInterface::UNRESOLVED_DIRECTIVE_NAME;
        $names[] = InlineDirectivePolicyInterface::UNSUPPORTED_THRESHOLD_NAME;
        $names[] = InlineDirectivePolicyInterface::INVALID_THRESHOLD_NAME;
        $names[] = InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME;

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
        $path = \dirname(__DIR__) . '/Fixtures/Channels/declared.txt';
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
            self::assertContains(\count($parts), [3, 4], \sprintf('Malformed fixture line: "%s".', $line));

            $channelKey = $parts[0];
            $shapeSpec = $parts[1];

            $declarations[$channelKey] = self::parseShapeSpec(
                $shapeSpec,
                $channelKey,
                self::parseAcceptabilitySpec($parts[3] ?? null, $channelKey),
                self::parseLevelsSpec($parts[2], $channelKey),
            );
        }

        return $declarations;
    }

    /**
     * @param non-empty-list<SymbolLevel> $levels
     */
    private static function parseShapeSpec(
        string $shapeSpec,
        string $channelKey,
        ChannelAcceptability $acceptability,
        array $levels,
    ): ChannelDeclaration {
        if ($shapeSpec === 'occurrence') {
            return new ChannelDeclaration(ChannelShape::Occurrence, null, $acceptability, $levels);
        }

        if (str_starts_with($shapeSpec, 'magnitude:')) {
            $direction = substr($shapeSpec, \strlen('magnitude:'));

            return match ($direction) {
                'higher' => new ChannelDeclaration(ChannelShape::Magnitude, WorseDirection::Higher, $acceptability, $levels),
                'lower' => new ChannelDeclaration(ChannelShape::Magnitude, WorseDirection::Lower, $acceptability, $levels),
                default => throw new RuntimeException(\sprintf(
                    'Unknown direction "%s" for channel "%s" in the fixture.',
                    $direction,
                    $channelKey,
                )),
            };
        }

        throw new RuntimeException(\sprintf('Unknown shape spec "%s" for channel "%s" in the fixture.', $shapeSpec, $channelKey));
    }

    /**
     * The third token: the levels the channel reports at.
     *
     * Required, and required to be non-empty, because a declaration cannot
     * express "no level" — see {@see ChannelDeclaration}. A fixture line that
     * omitted it would be a line the code could never produce.
     *
     * @return non-empty-list<SymbolLevel>
     */
    private static function parseLevelsSpec(string $spec, string $channelKey): array
    {
        $levels = [];

        foreach (explode(',', $spec) as $value) {
            $level = SymbolLevel::tryFrom($value);

            if ($level === null) {
                throw new RuntimeException(\sprintf(
                    'Unknown level "%s" for channel "%s" in the fixture.',
                    $value,
                    $channelKey,
                ));
            }

            $levels[] = $level;
        }

        return $levels;
    }

    /**
     * The optional fourth token. Absent means the ordinary case — a channel
     * whose findings are acceptable as debt — so that the fixture reads as a
     * list of exceptions rather than repeating the default 47 times.
     */
    private static function parseAcceptabilitySpec(?string $spec, string $channelKey): ChannelAcceptability
    {
        if ($spec === null) {
            return ChannelAcceptability::AcceptableAsDebt;
        }

        if ($spec === 'config-error') {
            return ChannelAcceptability::ConfigurationError;
        }

        throw new RuntimeException(\sprintf(
            'Unknown acceptability spec "%s" for channel "%s" in the fixture.',
            $spec,
            $channelKey,
        ));
    }
}
