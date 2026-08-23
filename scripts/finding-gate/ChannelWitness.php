<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The declared channel set, and the second witness to its static half.
 *
 * Static declarations come from the candidate container; the open
 * `computed.*` / `health.*` family is resolvable only once a case's
 * configuration has resolved, so it is asked per case. The tracked fixture
 * `tests/Analysis/Finding/Fixtures/Channels/declared.txt` is asked the same
 * question independently: two artifacts disagreeing is the cheapest detector we
 * have, so their disagreement is its own failure rather than a tie broken
 * silently.
 */
final class ChannelWitness
{
    private const FIXTURE = 'tests/Analysis/Finding/Fixtures/Channels/declared.txt';

    /** @var list<string>|null */
    private ?array $static = null;

    /** @var array<string, list<string>> */
    private array $computed = [];

    public function __construct(private readonly string $treeRoot) {}

    /** @return list<string> */
    public function staticChannels(): array
    {
        return $this->static ??= $this->probe(null)['static'];
    }

    /** @return list<string> */
    public function computedChannels(CaseDefinition $case): array
    {
        return $this->computed[$case->id] ??= $this->probe($case)['computed'];
    }

    /** @return list<string> */
    public function fixtureChannels(): array
    {
        $keys = [];

        foreach (explode("\n", Fs::read($this->treeRoot . '/' . self::FIXTURE)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line);
            $keys[] = $fields === false ? $line : $fields[0];
        }

        sort($keys);

        return $keys;
    }

    /** @return array{static: list<string>, computed: list<string>} */
    private function probe(?CaseDefinition $case): array
    {
        $directory = $case?->directory ?? $this->treeRoot;
        $configuration = $case?->config ?? 'qmx.yaml';
        $result = Process::run(
            [\PHP_BINARY, __DIR__ . '/probe-channels.php', $this->treeRoot, $directory, $configuration],
            $directory,
        );

        if ($result['exit'] !== 0) {
            throw new GateError(\sprintf("Channel probe failed (exit %d):\n%s", $result['exit'], $result['stderr']));
        }

        $decoded = json_decode($result['stdout'], true);

        if (!\is_array($decoded) || !\is_array($decoded['static'] ?? null) || !\is_array($decoded['computed'] ?? null)) {
            throw new GateError('Channel probe produced no usable answer: ' . $result['stdout']);
        }

        /** @var array{static: list<string>, computed: list<string>} */
        return ['static' => array_values($decoded['static']), 'computed' => array_values($decoded['computed'])];
    }
}
