<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** One corpus case, as `case.json` declares it. */
final class CaseDefinition
{
    /**
     * The case owns a channel: it counts towards coverage, and it is the one
     * place that channel may fire.
     */
    public const COVERAGE_AUTHORITATIVE = 'authoritative';

    /**
     * The case exists for an input nothing else exercises — a selector, a
     * non-empty exclusion — so it fires channels an authoritative case already
     * owns. It is compared on every surface and still has to fire exactly what
     * it claims; it is only left out of the coverage and multiplicity
     * arithmetic, which is what keeps "exactly one owner per channel" true.
     */
    public const COVERAGE_AUXILIARY = 'auxiliary';

    private const KNOWN_KEYS = ['id', 'description', 'coverage', 'paths', 'config', 'args', 'channels', 'explainSubjects'];

    /**
     * The product options whose value is a file-system path, by long name, with
     * the shortcut where one exists. The one list the containment rule and
     * {@see argumentPaths()} read; an option missing here is not checked.
     */
    public const PATH_OPTIONS = [
        '--config' => '-c',
        '--preset' => null,
        '--baseline' => null,
        '--output' => '-o',
        '--cache-dir' => null,
        '--log-file' => null,
        '--profile' => null,
        '--working-dir' => '-d',
    ];

    /**
     * @param list<string> $paths
     * @param list<string> $args
     * @param list<string> $channels each entry is a `rule#code@level` pair; see SubjectLevel
     * @param list<string> $explainSubjects
     */
    private function __construct(
        public readonly string $id,
        public readonly string $directory,
        public readonly string $description,
        public readonly string $coverage,
        public readonly array $paths,
        public readonly string $config,
        public readonly array $args,
        public readonly array $channels,
        public readonly array $explainSubjects,
    ) {}

    public function isAuxiliary(): bool
    {
        return $this->coverage === self::COVERAGE_AUXILIARY;
    }

    public static function load(string $directory): self
    {
        $id = basename($directory);
        $file = $directory . '/case.json';
        $decoded = json_decode(Fs::read($file), true);

        if (!\is_array($decoded)) {
            throw new GateError(\sprintf('%s does not contain a JSON object.', $file));
        }

        $unknown = array_diff(array_keys($decoded), self::KNOWN_KEYS);

        if ($unknown !== []) {
            throw new GateError(\sprintf('%s declares unknown key(s): %s.', $file, implode(', ', $unknown)));
        }

        $coverage = $decoded['coverage'] ?? self::COVERAGE_AUTHORITATIVE;

        if (!\in_array($coverage, [self::COVERAGE_AUTHORITATIVE, self::COVERAGE_AUXILIARY], true)) {
            throw new GateError(\sprintf(
                '%s: "coverage" must be "%s" or "%s".',
                $file,
                self::COVERAGE_AUTHORITATIVE,
                self::COVERAGE_AUXILIARY,
            ));
        }

        $case = new self(
            self::string($decoded, 'id', $file),
            $directory,
            self::string($decoded, 'description', $file),
            $coverage,
            self::strings($decoded, 'paths', $file),
            self::string($decoded, 'config', $file),
            self::strings($decoded, 'args', $file, optional: true),
            self::strings($decoded, 'channels', $file),
            self::strings($decoded, 'explainSubjects', $file, optional: true),
        );

        if ($case->id !== $id) {
            throw new GateError(\sprintf('%s declares id "%s" but lives in directory "%s".', $file, $case->id, $id));
        }

        foreach ($case->channels as $entry) {
            SubjectLevel::assertClaim($entry, $file);
        }

        if (\count(array_unique($case->channels)) !== \count($case->channels)) {
            throw new GateError(\sprintf(
                '%s claims the same channel%slevel pair twice. The observed set is deduplicated by pair, so a'
                . ' repeated claim can never be satisfied — and a claim nothing can satisfy is not a claim.',
                $file,
                SubjectLevel::SEPARATOR,
            ));
        }

        foreach ([...$case->paths, $case->config, ...$case->argumentPaths()] as $path) {
            if (str_starts_with($path, '/') || str_contains($path, '..')) {
                throw new GateError(\sprintf('%s names path "%s" outside its own directory.', $file, $path));
            }
        }

        if (!is_file($directory . '/' . $case->config)) {
            throw new GateError(\sprintf('%s names config "%s", which does not exist.', $file, $case->config));
        }

        return $case;
    }

    /**
     * Every file-system path the case's `args` name, as values of
     * {@see self::PATH_OPTIONS}, each comma-separated part on its own.
     *
     * A bare token is accepted only as the separated value of one of those
     * options. Anywhere else it is either a positional analysis path, which
     * belongs in `paths`, or the separated value of an option this list does not
     * know — and without knowing which, no answer about what the case reads
     * would be exact, so the case is refused instead.
     *
     * @return list<string>
     */
    public function argumentPaths(): array
    {
        $file = $this->directory . '/case.json';
        $values = [];
        $pending = null;

        foreach ($this->args as $argument) {
            if ($pending !== null) {
                $pending = null;

                if (!str_starts_with($argument, '-')) {
                    $values[] = $argument;

                    continue;
                }
            }

            if ($argument === '--') {
                throw new GateError(\sprintf('%s: "args" may not end option parsing with "--".', $file));
            }

            if (str_starts_with($argument, '--')) {
                $equals = strpos($argument, '=');
                $name = $equals === false ? $argument : substr($argument, 0, $equals);
                $value = $equals === false ? null : substr($argument, $equals + 1);

                if (\array_key_exists($name, self::PATH_OPTIONS)) {
                    $value === null ? $pending = $name : $values[] = $value;
                }

                continue;
            }

            if (str_starts_with($argument, '-')) {
                // Symfony reads the rest of a short-option cluster as the value
                // of its first option that takes one; any path shortcut in the
                // cluster is treated as that option, which can only over-refuse.
                foreach (str_split(substr($argument, 1)) as $offset => $shortcut) {
                    if (\in_array('-' . $shortcut, self::PATH_OPTIONS, true)) {
                        $value = ltrim(substr($argument, $offset + 2), '=');
                        $value === '' ? $pending = $shortcut : $values[] = $value;

                        break;
                    }
                }

                continue;
            }

            throw new GateError(\sprintf(
                '%s: "args" carries the bare token "%s", which is no value of a path option. Analysis paths'
                . ' belong in "paths"; any other option value is written attached, as --option=value.',
                $file,
                $argument,
            ));
        }

        $paths = [];

        foreach ($values as $value) {
            foreach (explode(',', $value) as $part) {
                $paths[] = $part;
            }
        }

        return $paths;
    }

    /** @param array<array-key, mixed> $decoded */
    private static function string(array $decoded, string $key, string $file): string
    {
        $value = $decoded[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new GateError(\sprintf('%s: "%s" must be a non-empty string.', $file, $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return list<string>
     */
    private static function strings(array $decoded, string $key, string $file, bool $optional = false): array
    {
        $value = $decoded[$key] ?? ($optional ? [] : null);

        if (!\is_array($value)) {
            throw new GateError(\sprintf('%s: "%s" must be an array of strings.', $file, $key));
        }

        $values = [];

        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new GateError(\sprintf('%s: "%s" must be an array of strings.', $file, $key));
            }

            $values[] = $item;
        }

        if (!$optional && $values === []) {
            throw new GateError(\sprintf('%s: "%s" must not be empty.', $file, $key));
        }

        return $values;
    }
}
