<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
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

    /** @var array<string, list<string>>|null channel key => observed level values */
    private static ?array $observed = null;

    /** @var array<string, list<string>>|null channel key => declared level values */
    private static ?array $declared = null;

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

            self::assertSame(
                ComputedMetricRule::NAME,
                ViolationChannel::fromKey($channel)->ruleName,
                \sprintf(
                    'Channel "%s" is observed but absent from %s. Only a user-defined computed metric may be'
                    . ' outside that enumeration; a new static channel must be measured into it.',
                    $channel,
                    self::OBSERVATION_ORACLE,
                ),
            );
        }
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
     * @return array<string, list<string>>
     */
    private static function readOracle(): array
    {
        $path = self::repositoryRoot() . '/' . self::OBSERVATION_ORACLE;
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read the observation oracle at %s.', $path));
        }

        $oracle = [];

        foreach (explode("\n", $contents) as $line) {
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, 'channel\t')) {
                continue;
            }

            $fields = explode("\t", $line);

            if (\count($fields) < 2 || $fields[0] === 'channel') {
                continue;
            }

            $oracle[$fields[0]] = self::canonical(explode(' ', $fields[1]));
        }

        return $oracle;
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

        foreach (self::cases() as $directory => $case) {
            $channelsInCase = [];

            foreach (self::runCase($directory, $case) as $violation) {
                $channel = $violation['channel'];
                $level = self::levelOf($violation['subject']);
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
                $directory . '/' . self::stringField($case, 'config'),
                [],
                [],
            ),
        );
        $computed->replace($computed->resolve($document));

        $declarations = [];

        foreach ($channels as $channel) {
            $declaration = $registry->declarationFor(ViolationChannel::fromKey($channel));

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
     */
    private static function levelOf(string $subject): string
    {
        $head = explode(':', $subject)[0];

        return match ($head) {
            'declaration' => explode(':', $subject)[1] === 'callable' ? 'callable' : 'class',
            'ns' => 'namespace',
            'file' => 'file',
            'project' => 'project',
            default => throw new RuntimeException(\sprintf('Unrecognised finding subject "%s".', $subject)),
        };
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return list<array{channel: string, subject: string}>
     */
    private static function runCase(string $directory, array $case): array
    {
        /** @var list<string> $paths */
        $paths = $case['paths'] ?? [];
        /** @var list<string> $extra */
        $extra = $case['args'] ?? [];

        $command = array_merge(
            [\PHP_BINARY, self::repositoryRoot() . '/bin/qmx', 'check'],
            $paths,
            ['-c', self::stringField($case, 'config'), '--format=json', '--workers=0', '--no-cache', '--no-ansi', '--fail-on=none'],
            $extra,
        );

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );

        if ($process === false) {
            throw new RuntimeException(\sprintf('Could not run the corpus case in %s.', $directory));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        array_map(fclose(...), $pipes);
        proc_close($process);

        $decoded = json_decode((string) $stdout, true);

        if (!\is_array($decoded) || !\is_array($decoded['violations'] ?? null)) {
            throw new RuntimeException(\sprintf(
                "The corpus case in %s produced no JSON report.\n%s",
                $directory,
                (string) $stderr,
            ));
        }

        /** @var list<array{channel: string, subject: string}> */
        return array_values($decoded['violations']);
    }

    /**
     * @return array<string, array<string, mixed>> case directory => case definition
     */
    private static function cases(): array
    {
        $root = self::repositoryRoot() . '/finding-gate/cases';
        $directories = glob($root . '/*', \GLOB_ONLYDIR);

        if ($directories === false || $directories === []) {
            throw new RuntimeException(\sprintf('No corpus cases under %s.', $root));
        }

        $cases = [];

        foreach ($directories as $directory) {
            $definition = file_get_contents($directory . '/case.json');

            if ($definition === false) {
                throw new RuntimeException(\sprintf('Corpus case %s has no case.json.', $directory));
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
            $cases[$directory] = $decoded;
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $case
     */
    private static function stringField(array $case, string $field): string
    {
        $value = $case[$field] ?? null;

        if (!\is_string($value)) {
            throw new RuntimeException(\sprintf('Corpus case is missing the string field "%s".', $field));
        }

        return $value;
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

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
