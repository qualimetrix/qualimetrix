<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** One corpus case, as `case.json` declares it. */
final class CaseDefinition
{
    private const KNOWN_KEYS = ['id', 'description', 'paths', 'config', 'args', 'channels', 'explainSubjects'];

    /**
     * @param list<string> $paths
     * @param list<string> $args
     * @param list<string> $channels
     * @param list<string> $explainSubjects
     */
    private function __construct(
        public readonly string $id,
        public readonly string $directory,
        public readonly string $description,
        public readonly array $paths,
        public readonly string $config,
        public readonly array $args,
        public readonly array $channels,
        public readonly array $explainSubjects,
    ) {}

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

        $case = new self(
            self::string($decoded, 'id', $file),
            $directory,
            self::string($decoded, 'description', $file),
            self::strings($decoded, 'paths', $file),
            self::string($decoded, 'config', $file),
            self::strings($decoded, 'args', $file, optional: true),
            self::strings($decoded, 'channels', $file),
            self::strings($decoded, 'explainSubjects', $file, optional: true),
        );

        if ($case->id !== $id) {
            throw new GateError(\sprintf('%s declares id "%s" but lives in directory "%s".', $file, $case->id, $id));
        }

        if (!is_file($directory . '/' . $case->config)) {
            throw new GateError(\sprintf('%s names config "%s", which does not exist.', $file, $case->config));
        }

        foreach ($case->paths as $path) {
            if (str_starts_with($path, '/') || str_contains($path, '..')) {
                throw new GateError(\sprintf('%s names path "%s" outside its own directory.', $file, $path));
            }
        }

        return $case;
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
