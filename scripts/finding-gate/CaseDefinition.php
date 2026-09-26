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
     * The product options that read a file-system path, by long name, with the
     * shortcut where one exists. The one list the containment rule and
     * {@see argumentPaths()} read; an option missing here is not checked.
     */
    public const INPUT_OPTIONS = [
        '--config' => '-c',
        '--preset' => null,
        '--baseline' => null,
    ];

    /**
     * Refused in `args`: the product resolves every other relative path from
     * the directory this names, while the containment rule resolves them from
     * the case directory the gate runs the case in.
     */
    public const WORKING_DIRECTORY_OPTION = ['--working-dir' => '-d'];

    /**
     * The product's output options. Refused in `args` outright: the run's
     * working directory is the case directory, so a relative destination
     * writes into the tracked corpus, and everything a run publishes the gate
     * captures itself. `--profile` writes only with a value; without one its
     * summary lands on stderr, a compared surface it has no business in.
     */
    public const OUTPUT_OPTIONS = [
        '--output' => '-o',
        '--cache-dir' => null,
        '--log-file' => null,
        '--profile' => null,
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
        self::assertOwnEntry($directory);
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
            $case->assertInside($path);
        }

        if (!is_file($directory . '/' . $case->config)) {
            throw new GateError(\sprintf('%s names config "%s", which does not exist.', $file, $case->config));
        }

        return $case;
    }

    /**
     * The case directory is the root every path of the case is judged
     * against, so it has to be the corpus entry it is named as, not a link to a
     * directory somewhere else — or to another case's.
     */
    private static function assertOwnEntry(string $directory): void
    {
        $corpus = realpath(\dirname($directory));
        $resolved = realpath($directory);

        if ($corpus === false || $resolved !== $corpus . '/' . basename($directory)) {
            throw new GateError(\sprintf(
                'The case directory %s leads to %s, not to its own entry of the corpus; a case directory may not be a'
                . ' link.',
                $directory,
                $resolved === false ? 'nothing' : $resolved,
            ));
        }
    }

    /**
     * Judged where the path leads, not how it is spelled: a link inside the case
     * directory reaches whatever it points at, and `a..b` is a name like any
     * other. A path that does not exist leads nowhere that could be judged.
     * Absolute paths are refused as spelled, because they name a checkout.
     */
    private function assertInside(string $path): void
    {
        $file = $this->directory . '/case.json';

        if (str_starts_with($path, '/')) {
            throw new GateError(\sprintf('%s names the absolute path "%s"; a case names paths inside its own directory.', $file, $path));
        }

        $root = realpath($this->directory);
        $resolved = realpath($this->directory . '/' . $path);

        if ($root === false || $resolved === false) {
            throw new GateError(\sprintf('%s names path "%s", which does not exist.', $file, $path));
        }

        if ($resolved !== $root && !str_starts_with($resolved, $root . '/')) {
            throw new GateError(\sprintf(
                '%s names path "%s", which leads to %s, outside its own directory.',
                $file,
                $path,
                $resolved,
            ));
        }
    }

    /**
     * Every file-system path the case's `args` read, as values of
     * {@see self::INPUT_OPTIONS}. Only `--preset` is a comma-separated list, as
     * the product reads it; a preset that is not spelled as a file is a
     * built-in name, read from the product rather than from the case, by the
     * same test the product applies.
     *
     * A bare token is accepted only as the separated value of one of those
     * options. Anywhere else it is either a positional analysis path, which
     * belongs in `paths`, or the separated value of an option this list does not
     * know — and without knowing which, no answer about what the case reads
     * would be exact, so the case is refused instead. So is any of
     * {@see self::OUTPUT_OPTIONS} and {@see self::WORKING_DIRECTORY_OPTION}.
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
                $option = $pending;
                $pending = null;

                if (!str_starts_with($argument, '-')) {
                    $values[] = [$option, $argument];

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

                if (\array_key_exists($name, self::OUTPUT_OPTIONS)) {
                    throw $this->writes($name);
                }

                if (\array_key_exists($name, self::WORKING_DIRECTORY_OPTION)) {
                    throw $this->movesWorkingDirectory($name);
                }

                if (\array_key_exists($name, self::INPUT_OPTIONS)) {
                    $value === null ? $pending = $name : $values[] = [$name, $value];
                }

                continue;
            }

            if (str_starts_with($argument, '-')) {
                // Symfony reads the rest of a short-option cluster as the value
                // of its first option that takes one; any path shortcut in the
                // cluster is treated as that option, which can only over-refuse.
                foreach (str_split(substr($argument, 1)) as $offset => $shortcut) {
                    if (\in_array('-' . $shortcut, self::OUTPUT_OPTIONS, true)) {
                        throw $this->writes('-' . $shortcut);
                    }

                    if (\in_array('-' . $shortcut, self::WORKING_DIRECTORY_OPTION, true)) {
                        throw $this->movesWorkingDirectory('-' . $shortcut);
                    }

                    $name = array_search('-' . $shortcut, self::INPUT_OPTIONS, true);

                    if (\is_string($name)) {
                        $value = ltrim(substr($argument, $offset + 2), '=');
                        $value === '' ? $pending = $name : $values[] = [$name, $value];

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

        foreach ($values as [$option, $value]) {
            if ($option !== '--preset') {
                $paths[] = $value;

                continue;
            }

            foreach (explode(',', $value) as $preset) {
                if (self::namesPresetFile($preset)) {
                    $paths[] = $preset;
                }
            }
        }

        return $paths;
    }

    /** The product's own test for a preset given as a file rather than by name. */
    private static function namesPresetFile(string $preset): bool
    {
        return str_contains($preset, '/') || str_contains($preset, '\\')
            || str_ends_with($preset, '.yaml') || str_ends_with($preset, '.yml');
    }

    private function writes(string $option): GateError
    {
        return new GateError(\sprintf(
            '%s: "args" carries the output option %s. A case writes nothing: its working directory is the tracked'
            . ' corpus, and the gate captures what a run publishes itself.',
            $this->directory . '/case.json',
            $option,
        ));
    }

    private function movesWorkingDirectory(string $option): GateError
    {
        return new GateError(\sprintf(
            '%s: "args" carries %s. The gate runs a case in its own directory, and every path of the case is judged'
            . ' from there; a second working directory would move what the product reads away from what was judged.',
            $this->directory . '/case.json',
            $option,
        ));
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
