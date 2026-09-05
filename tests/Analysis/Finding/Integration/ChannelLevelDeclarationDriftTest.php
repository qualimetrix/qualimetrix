<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Analysis\Finding\Support\CorpusCaseRun;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A channel's declared levels against the levels it is *observed* reporting
 * at, over the external corpus in `finding-gate/cases/`.
 *
 * The declaration says what the registry accepts; emission derives a
 * finding's level from its subject. Nothing joins the two, so they can
 * disagree — a rule can report at a level it never declared — and this is
 * the test that notices. A test that asked the declaration what the levels
 * are and then asserted the declaration says so would be green by
 * construction; this one runs the product and reads the answer out of real
 * findings.
 *
 * The oracle for the observation is
 * `docs/internal/plans/rule-vocabulary/enumeration-channel-levels.tsv` — 63
 * rows measured in Ш0 by two independent witnesses over six purpose-built
 * corpora, which is a stronger measurement than any single run here. A row
 * that stops reproducing means the corpus lost a fixture, not that the row
 * was wrong.
 *
 * Only the open `computed.*` / `health.*` family may appear beyond those
 * rows: its vocabulary comes from a user's own configuration, so no fixture
 * could enumerate it. Its levels are compared too — it is the only
 * multi-level family there is — but with a limit worth stating: a computed
 * metric's declared levels are also what
 * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule}
 * enumerates subjects over, so the two sides cannot drift apart there. What
 * the comparison does catch for that family is the projection between them
 * losing or merging a level. Real drift is possible only for the static
 * channels, where emission picks the subject on its own; a declaration
 * changed on one of those turns this test red.
 */
#[CoversClass(ChannelDeclaration::class)]
final class ChannelLevelDeclarationDriftTest extends TestCase
{
    private const string OBSERVATION_ORACLE = 'docs/internal/plans/rule-vocabulary/enumeration-channel-levels.tsv';

    private const string DECLARED_CHANNELS = 'tests/Analysis/Finding/Fixtures/Channels/declared.txt';

    /** @var array<string, list<string>>|null channel key => observed level values */
    private static ?array $observed = null;

    /** @var array<string, list<string>>|null channel name => declared level values */
    private static ?array $declared = null;

    /**
     * The channels that exist only because a case's own `qmx.yaml` defines a
     * computed metric — collected from the resolved definitions of every case,
     * so that "which rows of the oracle are not statically declared" is
     * answered by the configuration rather than by the fixture the rows are
     * about.
     *
     * @var list<string>|null
     */
    private static ?array $runtime = null;

    /**
     * Every subject form {@see levelOf()} distinguishes, in the order its own
     * `match` lists them. The self-test below requires this list to equal
     * what a corpus run actually reaches, so removing a form here without
     * removing its arm — or the reverse — is itself a red run, not a silent
     * gap.
     *
     * @var list<string>
     */
    private const array RECOGNISED_FORMS = ['declaration:callable', 'declaration:func', 'declaration:class', 'ns', 'file', 'project'];

    /**
     * Which of {@see RECOGNISED_FORMS} a call to {@see levelOf()} has
     * actually matched so far, keyed by form. Populated only inside
     * {@see levelOf()}'s own arms, so a form loses its entry if — and only
     * if — its arm stops being the one that returns.
     *
     * @var array<string, true>
     */
    private static array $recognisedForms = [];

    #[Test]
    public function theCorpusStillReportsEveryChannelAtTheLevelItWasMeasuredAt(): void
    {
        $oracle = self::readOracle();
        $observed = self::observe();

        foreach ($oracle as $channel => $levels) {
            self::assertArrayHasKey(
                $channel,
                $observed,
                \sprintf(
                    'Channel "%s" fired nothing over finding-gate/cases. The corpus lost the fixture that reaches it;'
                    . ' this guard is blind to that channel until it is restored.',
                    $channel,
                ),
            );
            self::assertSame($levels, $observed[$channel], \sprintf('Channel "%s" changed the level it reports at.', $channel));
        }

        foreach (array_keys($observed) as $channel) {
            if (isset($oracle[$channel])) {
                continue;
            }

            self::assertNotContains(
                $channel,
                self::readDeclaredChannels(),
                \sprintf(
                    'Channel "%s" is observed but absent from %s. Only a channel that exists because of a user\'s'
                    . ' own configuration may be outside that enumeration; a statically declared one must be'
                    . ' measured into it.',
                    $channel,
                    self::OBSERVATION_ORACLE,
                ),
            );
        }
    }

    #[Test]
    public function theOracleCoversEveryStaticallyDeclaredChannel(): void
    {
        $oracle = array_keys(self::readOracle());
        $declared = self::readDeclaredChannels();
        $runtimeNames = self::runtimeChannelNames();
        $staticOracle = array_values(array_filter(
            $oracle,
            static fn(string $channel): bool => !\in_array($channel, $runtimeNames, true),
        ));

        sort($staticOracle);
        sort($declared);

        self::assertSame(
            $declared,
            $staticOracle,
            'The level oracle and the declared-channel fixture disagree. A channel declared in code but absent from'
            . ' the oracle has no measured level, and nothing else would notice: the drift test only checks channels'
            . ' the oracle lists, and only channels the corpus fires. Measure the new channel into the oracle, or'
            . ' remove the stale line.',
        );
    }

    #[Test]
    public function everyChannelDeclaresExactlyTheLevelsItReportsAt(): void
    {
        $observed = self::observe();
        $declared = self::declared();

        foreach ($observed as $channel => $levels) {
            self::assertArrayHasKey(
                $channel,
                $declared,
                \sprintf('Channel "%s" reports findings but declares nothing.', $channel),
            );
            self::assertSame(
                $declared[$channel],
                $levels,
                \sprintf(
                    'Channel "%s" declares levels [%s] but reports at [%s]. The declaration governs what the registry'
                    . ' accepts; emission reads the finding\'s subject. They have drifted.',
                    $channel,
                    implode(', ', $declared[$channel]),
                    implode(', ', $levels),
                ),
            );
        }
    }

    /**
     * `levelOf()` is a hand-written parser kept deliberately independent of
     * the product's own subject-to-level derivation (rejected as
     * `r10-claude-04`, see Ш5c′ PLAN.md): a parser built from the same code
     * as what it checks would agree with it by construction and stop being a
     * witness. Independence has no guard of its own otherwise, so this test
     * is that guard — not a test of a finding, but of the oracle's fitness to
     * report one. It fails if the corpus stops reaching a form `levelOf()`
     * recognises: that is exactly the condition under which deleting the
     * arm for that form would go unnoticed.
     */
    #[Test]
    public function itRecognisesEveryFindingSubjectFormTheCorpusReaches(): void
    {
        self::observe();

        $reached = array_keys(self::$recognisedForms);
        sort($reached);
        $expected = self::RECOGNISED_FORMS;
        sort($expected);

        self::assertSame(
            $expected,
            $reached,
            'The corpus no longer exercises every subject form levelOf() recognises (or recognises one it no'
            . ' longer reaches). Either the corpus lost the fixture reaching a form, or a form was added to or'
            . ' removed from levelOf() without updating RECOGNISED_FORMS — this guard cannot tell which from the'
            . ' failure alone.',
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private static function readOracle(): array
    {
        $path = CorpusCaseRun::repositoryRoot() . '/' . self::OBSERVATION_ORACLE;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read the observation oracle at %s.', $path));
        }

        $oracle = [];

        foreach (explode("\n", $contents) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = explode("\t", $line);

            // The header row is skipped by its own first field, not by a
            // prefix match: the previous spelling used single quotes around
            // "channel\t", compared against a literal backslash, and the
            // row was only ever skipped by the check below it.
            if (\count($fields) < 2 || $fields[0] === 'channel') {
                continue;
            }

            $channel = self::channelNameOf($fields[0]);
            // Ш5c collapsed ten level-suffixed channels into five, so two rows
            // of the enumeration now describe one channel at two levels. Their
            // levels are unioned rather than one overwriting the other: what
            // the measurement recorded is that this channel reports at both.
            $oracle[$channel] = self::canonical([
                ...($oracle[$channel] ?? []),
                ...explode(' ', $fields[1]),
            ]);
        }

        return $oracle;
    }

    /**
     * @return list<string>
     */
    private static function runtimeChannelNames(): array
    {
        self::measure();
        \assert(self::$runtime !== null);

        return self::$runtime;
    }

    /**
     * The channel a row of the Ш0 enumeration names, in today's vocabulary.
     *
     * The enumeration was measured while a channel was a `rule#code` pair
     * whose code could end in a level, and it is left in the vocabulary it was
     * measured in: the measurement is what the row is worth, and rewriting 63
     * rows would restate it rather than preserve it. Two translations happen
     * here instead, both of them removals — the rule half (Ш5b) and a trailing
     * level segment (Ш5c) — so the comparison stays a comparison of names.
     *
     * A trailing segment that is not a {@see SymbolLevel} is left alone: an
     * aspect (`design.type-coverage.param`, before ADR 0030) is part of the
     * name, not a level.
     */
    private static function channelNameOf(string $row): string
    {
        $separator = strpos($row, FindingChannel::RETIRED_PAIR_SEPARATOR);
        $code = $separator === false ? $row : substr($row, $separator + 1);
        $lastDot = strrpos($code, '.');

        if ($lastDot !== false && SymbolLevel::tryFrom(substr($code, $lastDot + 1)) !== null) {
            return substr($code, 0, $lastDot);
        }

        return $code;
    }

    /**
     * The channel keys of the tracked declaration fixture — the same file
     * {@see ChannelDeclarationFixtureDriftTest} holds against the container,
     * read here for its key set alone.
     *
     * @return list<string>
     */
    private static function readDeclaredChannels(): array
    {
        $path = CorpusCaseRun::repositoryRoot() . '/' . self::DECLARED_CHANNELS;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read the declared-channel fixture at %s.', $path));
        }

        $keys = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line);
            self::assertNotFalse($fields, \sprintf('Malformed fixture line: "%s".', $line));
            $keys[] = $fields[0];
        }

        return $keys;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function observe(): array
    {
        self::measure();
        \assert(self::$observed !== null);

        return self::$observed;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function declared(): array
    {
        self::measure();
        \assert(self::$declared !== null);

        return self::$declared;
    }

    /**
     * One pass over the corpus, feeding both halves of the comparison: the
     * findings a case emits, and what the container declares under that same
     * case's configuration — the computed family is resolvable only once a
     * configuration has been.
     */
    private static function measure(): void
    {
        if (self::$observed !== null) {
            return;
        }

        $container = (new ContainerFactory())->create();
        $observed = [];
        $declared = [];
        self::$runtime = [];
        self::$recognisedForms = [];

        foreach (CorpusCaseRun::cases() as $directory => $case) {
            $channelsInCase = [];

            foreach (self::findingsOf($directory, $case) as $finding) {
                $channel = $finding['channel'];
                $level = self::levelOf($finding['subject']);
                $observed[$channel][] = $level;
                $channelsInCase[$channel] = true;
            }

            foreach (self::declarationsFor($container, $directory, $case, array_keys($channelsInCase)) as $channel => $levels) {
                $declared[$channel] = [...($declared[$channel] ?? []), ...$levels];
            }
        }

        self::$observed = array_map(self::canonical(...), $observed);
        self::$declared = array_map(self::canonical(...), $declared);
    }

    /**
     * @param array<string, mixed> $case
     * @param list<string> $channels
     *
     * @return array<string, list<string>>
     */
    private static function declarationsFor(
        ContainerInterface $container,
        string $directory,
        array $case,
        array $channels,
    ): array {
        $pipeline = $container->get(ConfigurationPipelineInterface::class);
        \assert($pipeline instanceof ConfigurationPipelineInterface);

        $computed = $container->get(ComputedMetricConfiguratorInterface::class);
        \assert($computed instanceof ComputedMetricConfiguratorInterface);

        $registry = $container->get(ChannelDeclarationRegistryInterface::class);
        \assert($registry instanceof ChannelDeclarationRegistryInterface);

        $document = $pipeline->resolve(
            // The config path is resolved against the process working
            // directory, which is the repository root here and the case
            // directory when the corpus is run; naming it absolutely makes
            // both readings the same file.
            new ConfigurationResolutionRequest(
                AbsolutePath::fromString($directory),
                $directory . '/' . CorpusCaseRun::stringField($case, 'config'),
                [],
                [],
            ),
        );
        $definitions = $computed->resolve($document);
        $computed->replace($definitions);

        foreach ($definitions->all() as $definition) {
            if (!\in_array($definition->name, self::$runtime ?? [], true)) {
                self::$runtime[] = $definition->name;
            }
        }

        $declarations = [];

        foreach ($channels as $channel) {
            $declaration = $registry->declarationFor(new FindingChannel($channel));

            if ($declaration === null) {
                continue;
            }

            $declarations[$channel] = array_map(static fn(SymbolLevel $level): string => $level->value, $declaration->levels);
        }

        return $declarations;
    }

    /**
     * The level a finding reports at, read from its subject — the same place
     * every formatter reads it from, and the only place 53 of the 63
     * channels carry it at all.
     *
     * Deliberately its own parser rather than a call into the product's
     * subject-to-level derivation: a derivation sharing code with what it
     * checks would agree with the product by construction, not by
     * observation, and stop being a witness. There is also no such product
     * path to call — `SymbolLevelProjection` maps a `SymbolType`, not this
     * text — so sharing it would mean writing one to serve this test. See
     * Ш5c′ in `docs/internal/plans/rule-vocabulary/PLAN.md` for the rejected
     * alternative (`r10-claude-04`) and {@see RECOGNISED_FORMS} for this
     * parser's own completeness guard.
     */
    private static function levelOf(string $subject): string
    {
        $head = explode(':', $subject)[0];

        $unrecognised = static fn(): never => throw new RuntimeException(
            \sprintf('Unrecognised finding subject "%s".', $subject),
        );

        return match ($head) {
            'declaration' => match (explode(':', $subject)[1] ?? '') {
                'callable' => self::recognise('declaration:callable', 'callable'),
                // A method and a global function are two declaration kinds
                // that report at one level, and the subject text is where
                // that shows: the corpus reaches both spellings.
                'func' => self::recognise('declaration:func', 'callable'),
                'class' => self::recognise('declaration:class', 'class'),
                default => $unrecognised(),
            },
            'ns' => self::recognise('ns', 'namespace'),
            'file' => self::recognise('file', 'file'),
            'project' => self::recognise('project', 'project'),
            default => $unrecognised(),
        };
    }

    /**
     * Records that the arm returning {@see $level} for {@see $form} fired,
     * for {@see itRecognisesEveryFindingSubjectFormTheCorpusReaches()} to
     * check against {@see RECOGNISED_FORMS}.
     */
    private static function recognise(string $form, string $level): string
    {
        self::$recognisedForms[$form] = true;

        return $level;
    }

    /**
     * The findings a case emits, narrowed to the two fields this guard reads
     * off them. The run itself, and the refusal to accept a partial one as an
     * observation, live in {@see CorpusCaseRun}.
     *
     * @param array<string, mixed> $case
     *
     * @return list<array{channel: string, subject: string}>
     */
    private static function findingsOf(string $directory, array $case): array
    {
        $findings = [];

        foreach (CorpusCaseRun::findings($directory, $case) as $finding) {
            $channel = $finding['channel'] ?? null;
            $subject = $finding['subject'] ?? null;

            if (!\is_string($channel) || !\is_string($subject)) {
                throw new RuntimeException(\sprintf(
                    'A finding of the corpus case in %s carries no channel or no subject.',
                    $directory,
                ));
            }

            $findings[] = ['channel' => $channel, 'subject' => $subject];
        }

        return $findings;
    }

    /**
     * Deduplicated and ordered by {@see SymbolLevel} case order, the order a
     * {@see ChannelDeclaration} stores its own levels in, so that the two
     * sides of every comparison here are comparable at all.
     *
     * @param list<string> $levels
     *
     * @return list<string>
     */
    private static function canonical(array $levels): array
    {
        $canonical = [];

        foreach (SymbolLevel::cases() as $case) {
            if (\in_array($case->value, $levels, true)) {
                $canonical[] = $case->value;
            }
        }

        return $canonical;
    }

}
