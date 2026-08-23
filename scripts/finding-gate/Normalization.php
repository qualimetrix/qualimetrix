<?php

declare(strict_types=1);

namespace QmxFindingGate;

use stdClass;

/**
 * The declared exclusions from comparison, and the only place a field can leave
 * the comparison at all.
 *
 * Two properties make the list a contract rather than a growing blanket. A
 * locator names one field by path, never by substring — SARIF's `version` is the
 * schema constant `2.1.0` and stays compared. And a rule that matches nothing in
 * a whole run is stale: the gate fails instead of carrying an exclusion nobody
 * can justify any more.
 *
 * A rule "fires" when its locator matches a field, not when that field's two
 * values happen to differ. Firing on divergence would make every rule look
 * stale on the run where the nondeterminism did not surface.
 */
final class Normalization
{
    public const COLUMNS = ['surface', 'locator', 'kind', 'reason'];

    public const REDACTED = '<normalized>';

    public const MEASURED_REASON = 'diverged across repeated runs of one unchanged tree';

    private const ENCODE_FLAGS = \JSON_PRETTY_PRINT
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_PRESERVE_ZERO_FRACTION
        | \JSON_THROW_ON_ERROR;

    private const REPORT_DATA_PATTERN = '~(<script type="application/json" id="report-data">)(.*?)(</script>)~s';

    /** @var array<int, int> */
    private array $hits = [];

    /** @param list<NormalizationRule> $rules */
    private function __construct(private readonly array $rules) {}

    public static function load(string $path): self
    {
        $rules = [];

        foreach (Tsv::rows($path, self::COLUMNS) as $row) {
            $rules[] = new NormalizationRule($row['surface'], $row['locator'], $row['kind'], $row['reason']);
        }

        return new self($rules);
    }

    /** @param list<NormalizationRule> $rules */
    public static function fromRules(array $rules): self
    {
        return new self($rules);
    }

    public function normalize(string $surface, string $content): string
    {
        $decoded = json_decode($content, false);

        if ($decoded instanceof stdClass || \is_array($decoded)) {
            $this->applyPaths($surface, NormalizationRule::KIND_JSON_PATH, $decoded);

            return self::encode($decoded);
        }

        return $this->applyLineRegex($surface, $this->normalizeReportData($surface, $content));
    }

    /**
     * The rules that did fire — a tracked row still doing its job.
     *
     * @return list<NormalizationRule>
     */
    public function activeRules(): array
    {
        $active = [];

        foreach ($this->rules as $index => $rule) {
            if (($this->hits[$index] ?? 0) > 0) {
                $active[] = $rule;
            }
        }

        return $active;
    }

    /** @return list<NormalizationRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return list<NormalizationRule> */
    public function staleRules(): array
    {
        $stale = [];

        foreach ($this->rules as $index => $rule) {
            if (($this->hits[$index] ?? 0) === 0) {
                $stale[] = $rule;
            }
        }

        return $stale;
    }

    public function render(): string
    {
        $rows = array_map(static fn(NormalizationRule $rule): array => $rule->row(), $this->rules);
        usort($rows, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return Tsv::render(self::COLUMNS, $rows);
    }

    private function normalizeReportData(string $surface, string $content): string
    {
        $rules = $this->rulesFor($surface, NormalizationRule::KIND_HTML_REPORT_DATA_PATH);

        if ($rules === []) {
            return $content;
        }

        return (string) preg_replace_callback(
            self::REPORT_DATA_PATTERN,
            function (array $matches) use ($surface): string {
                $decoded = json_decode($matches[2], false, flags: \JSON_THROW_ON_ERROR);
                $this->applyPaths($surface, NormalizationRule::KIND_HTML_REPORT_DATA_PATH, $decoded);

                return $matches[1] . self::encode($decoded) . $matches[3];
            },
            $content,
        );
    }

    private function applyLineRegex(string $surface, string $content): string
    {
        foreach ($this->rulesFor($surface, NormalizationRule::KIND_LINE_REGEX) as $index => $rule) {
            $count = 0;
            $content = (string) preg_replace($rule->locator, '${1}' . self::REDACTED . '${2}', $content, -1, $count);
            $this->hits[$index] = ($this->hits[$index] ?? 0) + $count;
        }

        return $content;
    }

    private function applyPaths(string $surface, string $kind, mixed &$data): void
    {
        foreach ($this->rulesFor($surface, $kind) as $index => $rule) {
            $hits = 0;
            self::redact($data, explode('.', $rule->locator), $hits);
            $this->hits[$index] = ($this->hits[$index] ?? 0) + $hits;
        }
    }

    /** @return array<int, NormalizationRule> */
    private function rulesFor(string $surface, string $kind): array
    {
        $matching = [];

        foreach ($this->rules as $index => $rule) {
            if ($rule->surface === $surface && $rule->kind === $kind) {
                $matching[$index] = $rule;
            }
        }

        return $matching;
    }

    /**
     * `*` stands for every key at that depth, which is what makes one row cover
     * a list of findings; any other segment must match a key exactly. Redaction
     * is by reference all the way down because a JSON list decodes to a PHP
     * array, and a value-semantics descent would count a hit while changing
     * nothing.
     *
     * @param list<string> $segments
     */
    private static function redact(mixed &$data, array $segments, int &$hits): void
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        if ($data instanceof stdClass) {
            foreach (self::matchingKeys(array_keys(get_object_vars($data)), $segment) as $key) {
                if ($segments === []) {
                    $data->{$key} = self::REDACTED;
                    ++$hits;

                    continue;
                }

                self::redact($data->{$key}, $segments, $hits);
            }

            return;
        }

        if (!\is_array($data)) {
            return;
        }

        foreach (self::matchingKeys(array_keys($data), $segment) as $key) {
            if ($segments === []) {
                $data[$key] = self::REDACTED;
                ++$hits;

                continue;
            }

            self::redact($data[$key], $segments, $hits);
        }
    }

    /**
     * @param list<string|int> $keys
     *
     * @return list<string|int>
     */
    private static function matchingKeys(array $keys, string $segment): array
    {
        if ($segment === '*') {
            return $keys;
        }

        return \in_array($segment, array_map(strval(...), $keys), true)
            ? [self::sameTypeKey($keys, $segment)]
            : [];
    }

    /**
     * @param list<string|int> $keys
     */
    private static function sameTypeKey(array $keys, string $segment): string|int
    {
        foreach ($keys as $key) {
            if ((string) $key === $segment) {
                return $key;
            }
        }

        return $segment;
    }

    private static function encode(mixed $data): string
    {
        return json_encode($data, self::ENCODE_FLAGS);
    }
}
