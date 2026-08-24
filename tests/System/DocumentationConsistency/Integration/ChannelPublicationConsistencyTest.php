<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Documentation;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassRule;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectiveValidator;
use Qualimetrix\Analysis\Policy\Inline\Directive\UnusedDirectiveRule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Binds every prose statement that counts or enumerates violation channels to
 * the machine-readable channel declarations.
 *
 * Counts and channel lists were maintained as prose in eight documentation
 * pages at once, so a package that added `architecture.pending-layer-matched`
 * left five of them saying "four". Two artefacts now have to agree instead:
 * the declarations (`declared.txt`, verified against the emission points by
 * ChannelDeclarationFixtureDriftTest, and `LayerViolationRule` itself) and the
 * prose.
 */
final class ChannelPublicationConsistencyTest extends TestCase
{
    /**
     * Numerals as documentation actually spells them, in both site languages.
     * A count written in a form absent here fails as unreadable rather than
     * passing unchecked.
     *
     * @var array<string, int>
     */
    private const NUMERALS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'один' => 1, 'одна' => 1, 'одной' => 1, 'одну' => 1, 'одного' => 1,
        'два' => 2, 'две' => 2, 'двух' => 2,
        'три' => 3, 'трёх' => 3, 'трех' => 3, 'тремя' => 3,
        'четыре' => 4, 'четырёх' => 4, 'четырех' => 4,
        'пять' => 5, 'пяти' => 5, 'пятью' => 5,
        'шесть' => 6, 'шести' => 6,
        'семь' => 7, 'семи' => 7,
        'восемь' => 8, 'восьми' => 8,
        'девять' => 9, 'девяти' => 9,
        'десять' => 10, 'десяти' => 10,
    ];

    /**
     * Every prose statement that publishes a channel count or a channel list.
     * The inventory is closed: an unregistered statement of the same shape is
     * caught by {@see itRegistersEveryChannelCountStatement()}.
     *
     * `set` names the derived channel set the statement is about. `mode` is
     * `exact` when the enumeration must be the whole set (less `omitted`), and
     * `subset` when the statement deliberately speaks about part of it. Every
     * pattern must capture a `count`: a statement carrying no number publishes
     * nothing this oracle can bind. `count` is `set` when the number counts the
     * whole set and `enumerated` when it counts only what the statement lists.
     *
     * @var list<array{path: string, pattern: string, set: string, mode?: string, omitted?: list<string>, count?: string}>
     */
    private const PUBLICATIONS = [
        [
            'path' => 'website/docs/getting-started/configuration.md',
            'pattern' => '/apply to the (?<count>\S+) layer-policy diagnostics —(?<list>.*?)— which report/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/getting-started/configuration.md',
            'pattern' => '/the (?<count>\S+)\s+layer-policy diagnostics \((?<list>[^)]*)\)/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/getting-started/configuration.md',
            'pattern' => '/the (?<count>\S+) inline-directive\s+diagnostics \((?<list>[^)]*)\)/su',
            'set' => 'inline-directive-config-error',
        ],
        [
            'path' => 'website/docs/getting-started/configuration.ru.md',
            'pattern' => '/(?<count>\S+) диагностик\S*\s+рядом с ним —(?<list>.*?)— сообщают/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/getting-started/configuration.ru.md',
            'pattern' => '/(?<count>\S+) диагностик\S* политики слоёв\s*\((?<list>[^)]*)\)/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/getting-started/configuration.ru.md',
            'pattern' => '/(?<count>\S+) диагностик\S* инлайн-директив\s*\((?<list>[^)]*)\)/su',
            'set' => 'inline-directive-config-error',
        ],
        [
            'path' => 'website/docs/usage/baseline.md',
            'pattern' => '/the (?<count>\S+) layer-policy diagnostics \((?<list>[^)]*)\)/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/usage/baseline.md',
            'pattern' => '/the (?<count>\S+) inline-directive diagnostics \((?<list>[^)]*)\)/su',
            'set' => 'inline-directive-config-error',
        ],
        [
            'path' => 'website/docs/usage/baseline.ru.md',
            'pattern' => '/(?<count>\S+) диагностик\S* layer-policy \((?<list>[^)]*)\)/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/usage/baseline.ru.md',
            'pattern' => '/(?<count>\S+) диагностик\S* inline-директив \((?<list>[^)]*)\)/su',
            'set' => 'inline-directive-config-error',
        ],
        [
            'path' => 'website/docs/rules/annotation.md',
            'pattern' => '/the (?<count>\S+) architecture configuration diagnostics do/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/annotation.md',
            'pattern' => '/The other (?<count>\S+) channels —(?<list>.*?)— have no severity option/su',
            'set' => 'inline-directive-config-error',
        ],
        [
            'path' => 'website/docs/rules/annotation.ru.md',
            'pattern' => '/(?<count>\S+) архитектурных диагностик конфигурации/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/annotation.ru.md',
            'pattern' => '/У остальных (?<count>\S+) каналов —(?<list>.*?)— нет опции severity/su',
            'set' => 'inline-directive-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.md',
            'pattern' => '/is one of (?<count>\S+) architecture diagnostics/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.md',
            'pattern' => '/the others are(?<list>.*?)\. All\s+(?<count>\S+) fail the run unconditionally/su',
            'set' => 'layer-policy-config-error',
            'omitted' => ['architecture.coverage'],
        ],
        [
            'path' => 'website/docs/rules/architecture.md',
            'pattern' => '/none of the (?<count>\S+) can be accepted/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.md',
            'pattern' => '/The (?<count>\S+) architecture configuration diagnostics —(?<list>.*?)— have no severity options/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.md',
            'pattern' => '/Unlike the (?<count>\S+) architecture \*configuration\* diagnostics/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.md',
            'pattern' => '/The layer policy publishes (?<count>\S+) channels, but the other \S+ carry rule names of their own \((?<list>[^)]*)\)/su',
            'set' => 'layer-policy-channels',
            'omitted' => ['architecture.layer-violation'],
        ],
        [
            'path' => 'website/docs/rules/architecture.md',
            'pattern' => '/(?<count>\S+) of those six are configuration errors/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.md',
            'pattern' => '/(?<count>Two) diagnostics catch this:(?<list>.*?)Both are configuration diagnostics/su',
            'set' => 'layer-policy-config-error',
            'mode' => 'subset',
            'count' => 'enumerated',
        ],
        [
            'path' => 'website/docs/rules/architecture.ru.md',
            'pattern' => '/одна из (?<count>\S+) архитектурных диагностик/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.ru.md',
            'pattern' => '/остальные (?<count>\S+) —(?<list>.*?)\. Все\s+\S+ валят прогон безусловно/su',
            'set' => 'layer-policy-config-error',
            'omitted' => ['architecture.coverage'],
            'count' => 'enumerated',
        ],
        [
            'path' => 'website/docs/rules/architecture.ru.md',
            'pattern' => '/ни одну из (?<count>\S+) нельзя/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.ru.md',
            'pattern' => '/(?<count>\S+) архитектурных диагностик конфигурации —(?<list>.*?)— не имеют собственных опций severity/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.ru.md',
            'pattern' => '/В отличие от (?<count>\S+) архитектурных диагностик \*конфигурации\*/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.ru.md',
            'pattern' => '/Политика слоёв публикует (?<count>\S+) каналов, но остальные \S+ несут собственные имена правил \((?<list>[^)]*)\)/su',
            'set' => 'layer-policy-channels',
            'omitted' => ['architecture.layer-violation'],
        ],
        [
            'path' => 'website/docs/rules/architecture.ru.md',
            'pattern' => '/(?<count>\S+) из этих шести суть ошибки конфигурации/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/rules/architecture.ru.md',
            'pattern' => '/(?<count>Две) диагностики ловят это:(?<list>.*?)Обе — диагностики конфигурации/su',
            'set' => 'layer-policy-config-error',
            'mode' => 'subset',
            'count' => 'enumerated',
        ],
        [
            'path' => 'website/docs/usage/baseline.md',
            'pattern' => '/(?<count>\S+) channels can never be suppressed here"\s*(?<list>.*?) are configuration errors/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/usage/baseline.md',
            'pattern' => '/(?<count>\S+) of its four channels are configuration errors/su',
            'set' => 'inline-directive-config-error',
        ],
        [
            'path' => 'website/docs/usage/baseline.md',
            'pattern' => '/Three of its (?<count>\S+) channels are configuration errors/su',
            'set' => 'annotation-channels',
        ],
        [
            'path' => 'website/docs/usage/baseline.ru.md',
            'pattern' => '/(?<count>\S+) каналов здесь никогда нельзя подавить"\s*(?<list>.*?) — это конфигурационные ошибки/su',
            'set' => 'layer-policy-config-error',
        ],
        [
            'path' => 'website/docs/usage/baseline.ru.md',
            'pattern' => '/(?<count>\S+) из четырёх её каналов — конфигурационные ошибки/su',
            'set' => 'inline-directive-config-error',
        ],
        [
            'path' => 'website/docs/usage/baseline.ru.md',
            'pattern' => '/Три из (?<count>\S+) её каналов — конфигурационные ошибки/su',
            'set' => 'annotation-channels',
        ],
        [
            'path' => 'website/docs/rules/index.md',
            'pattern' => '/It reports through (?<count>\S+) channels —(?<list>[^\n]*)/su',
            'set' => 'annotation-channels',
        ],
        [
            'path' => 'website/docs/rules/index.md',
            'pattern' => '/\(reports through (?<count>\S+) channels — see/su',
            'set' => 'annotation-channels',
        ],
        [
            'path' => 'website/docs/rules/index.ru.md',
            'pattern' => '/Оно публикуется через (?<count>\S+) канала —(?<list>[^\n]*)/su',
            'set' => 'annotation-channels',
        ],
        [
            'path' => 'website/docs/rules/index.ru.md',
            'pattern' => '/\(публикуется через (?<count>\S+) канала — см/su',
            'set' => 'annotation-channels',
        ],
    ];

    /**
     * Documentation trees the sweep for unregistered statements covers.
     * `website/docs/changelog.md` is a symlink to the root changelog, and
     * `docs/adr/` holds immutable decision records: both state what was true
     * when they were written, so neither is drift when the code moves on.
     *
     * @var list<string>
     */
    private const SWEPT_ROOTS = ['website/docs', 'docs'];

    /** @var list<string> */
    private const SWEEP_EXCLUDED_PREFIXES = [
        'website/docs/changelog.md',
        'docs/adr/',
        'docs/internal/plans/',
        'docs/internal/generated/',
    ];

    private static string $projectRoot;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = \dirname(__DIR__, 4);
    }

    #[Test]
    public function itPublishesChannelCountsAndListsThatMatchTheDeclarations(): void
    {
        self::assertSame([], $this->publicationErrors($this->channelSets(), $this->readSweptDocumentation()));
    }

    /**
     * A count sentence that names channels but was never registered is drift
     * waiting to happen: the previous round left one in every page nobody
     * remembered to open.
     */
    #[Test]
    public function itRegistersEveryChannelCountStatement(): void
    {
        self::assertSame([], $this->unregisteredStatements($this->readSweptDocumentation()));
    }

    /**
     * The oracle must reject a declaration-only change: a new channel that no
     * page mentions.
     */
    #[Test]
    public function itRejectsDeclarationOnlyChannelDrift(): void
    {
        $sets = $this->channelSets();
        $sets['layer-policy-config-error'][] = 'architecture.newly-declared';
        $sets['layer-policy-channels'][] = 'architecture.newly-declared';

        self::assertNotSame([], $this->publicationErrors($sets, $this->readSweptDocumentation()));
    }

    /**
     * The oracle must reject a documentation-only change, both when the count
     * moves and when a channel is dropped from an enumeration.
     */
    #[Test]
    public function itRejectsDocumentationOnlyChannelDrift(): void
    {
        $documentation = $this->readSweptDocumentation();
        $path = 'website/docs/usage/baseline.md';

        foreach ([
            'count' => ['the five layer-policy diagnostics', 'the four layer-policy diagnostics'],
            'enumeration' => ['`architecture.pending-layer-matched`, ', ''],
            'numeral' => ['the five layer-policy diagnostics', 'the several layer-policy diagnostics'],
        ] as $case => [$search, $replacement]) {
            $mutated = $documentation;
            self::assertStringContainsString($search, $mutated[$path], "The {$case} fixture must apply.");
            $mutated[$path] = str_replace($search, $replacement, $mutated[$path]);

            self::assertNotSame(
                [],
                $this->publicationErrors($this->channelSets(), $mutated),
                "The {$case} mutation must fail the channel oracle.",
            );
        }
    }

    /**
     * A registered statement that disappears or doubles is an error too — the
     * inventory is closed in both directions.
     */
    #[Test]
    public function itRejectsMissingAndDuplicateChannelStatements(): void
    {
        $documentation = $this->readSweptDocumentation();
        $path = 'website/docs/rules/annotation.md';
        $statement = 'the five architecture configuration diagnostics do';

        foreach ([
            'missing' => str_replace($statement, 'they do', $documentation[$path]),
            'duplicate' => $documentation[$path] . "\n" . $statement . "\n",
        ] as $case => $content) {
            $mutated = $documentation;
            $mutated[$path] = $content;

            self::assertNotSame(
                [],
                $this->publicationErrors($this->channelSets(), $mutated),
                "The {$case} mutation must fail the channel oracle.",
            );
        }
    }

    /**
     * A count sentence added to a page outside the inventory must be noticed.
     */
    #[Test]
    public function itRejectsUnregisteredChannelCountStatements(): void
    {
        $documentation = $this->readSweptDocumentation();
        $path = 'website/docs/usage/baseline.md';
        $documentation[$path] .= "\n\nThe two layer-policy diagnostics `architecture.coverage` and `architecture.empty-template` gate the run.\n";

        self::assertNotSame([], $this->unregisteredStatements($documentation));
    }

    /**
     * The derived sets themselves must stay non-degenerate: an empty set would
     * make every enumeration check vacuous.
     *
     * @return array<string, list<string>>
     */
    private function channelSets(): array
    {
        $configErrors = [];

        foreach (explode("\n", $this->readFile('tests/Analysis/Finding/Fixtures/Channels/declared.txt')) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_ends_with($line, ' config-error')) {
                continue;
            }

            $configErrors[] = substr($line, 0, (int) strpos($line, '#'));
        }

        $layerPolicy = [];

        // Every producer of the family: one rule per finding about the code,
        // and the validator for the five about the declaration.
        $layerPolicyKeys = [
            ...array_keys(LayerViolationRule::channelDeclarations()),
            ...array_keys(UnassignedClassRule::channelDeclarations()),
            ...array_keys(LayerDeclarationValidator::channelDeclarations()),
        ];

        foreach ($layerPolicyKeys as $channelKey) {
            $layerPolicy[] = substr($channelKey, 0, (int) strpos($channelKey, '#'));
        }

        $annotationChannels = [];

        $annotationKeys = [
            ...array_keys(UnusedDirectiveRule::channelDeclarations()),
            ...array_keys(InlineDirectiveValidator::channelDeclarations()),
        ];

        foreach ($annotationKeys as $channelKey) {
            $annotationChannels[] = substr($channelKey, 0, (int) strpos($channelKey, '#'));
        }

        $sets = [
            'layer-policy-config-error' => array_values(array_filter(
                $configErrors,
                static fn(string $channel): bool => str_starts_with($channel, 'architecture.'),
            )),
            'inline-directive-config-error' => array_values(array_filter(
                $configErrors,
                static fn(string $channel): bool => str_starts_with($channel, 'annotation.'),
            )),
            'layer-policy-channels' => $layerPolicy,
            'annotation-channels' => $annotationChannels,
        ];

        foreach ($sets as $name => $channels) {
            self::assertNotEmpty($channels, "Derived channel set '{$name}' is empty.");
        }

        return $sets;
    }

    /**
     * @param array<string, list<string>> $sets
     * @param array<string, string> $documentation
     *
     * @return list<string>
     */
    private function publicationErrors(array $sets, array $documentation): array
    {
        $errors = [];

        foreach (self::PUBLICATIONS as $publication) {
            $path = $publication['path'];
            $content = $documentation[$path] ?? null;

            if (!\is_string($content)) {
                $errors[] = "Missing channel publication page {$path}.";
                continue;
            }

            $matches = [];
            $found = preg_match_all($publication['pattern'], $content, $matches, \PREG_SET_ORDER);

            if ($found !== 1) {
                $errors[] = "Expected one channel statement in {$path} matching {$publication['pattern']}; found {$found}.";
                continue;
            }

            $set = $sets[$publication['set']];
            sort($set);
            $expectedList = array_values(array_diff($set, $publication['omitted'] ?? []));

            $enumerated = null;

            if (isset($matches[0]['list'])) {
                $tokens = [];
                preg_match_all('/`([a-z][a-z-]*\.[a-z][a-z-]+)`/', $matches[0]['list'], $tokens);
                $enumerated = array_values(array_unique($tokens[1]));
                sort($enumerated);

                if (($publication['mode'] ?? 'exact') === 'subset') {
                    $unknown = array_values(array_diff($enumerated, $set));

                    if ($unknown !== []) {
                        $errors[] = "Channel statement in {$path} names channels outside its set: " . implode(', ', $unknown) . '.';
                    }
                } elseif ($enumerated !== $expectedList) {
                    $errors[] = "Channel enumeration in {$path} is [" . implode(', ', $enumerated)
                        . ']; expected [' . implode(', ', $expectedList) . '].';
                }
            }

            $published = $this->numeral($matches[0]['count']);

            if ($published === null) {
                $errors[] = "Unreadable channel count '{$matches[0]['count']}' in {$path}.";
                continue;
            }

            $expectedCount = ($publication['count'] ?? 'set') === 'enumerated'
                ? \count($enumerated ?? $expectedList)
                : \count($set);

            if ($published !== $expectedCount) {
                $errors[] = "Channel count in {$path} is {$published}; expected {$expectedCount}.";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $documentation
     *
     * @return list<string>
     */
    private function unregisteredStatements(array $documentation): array
    {
        $numerals = array_keys(self::NUMERALS);
        usort($numerals, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $sweep = '/\b(?:' . implode('|', $numerals) . '|\d+)\b[^\n.;:]{0,80}?(?:diagnostics|channels|диагностик\S*|канал\S*)/iu';
        $channelToken = '/`(?:architecture|annotation)\.[a-z-]+`/';

        $registered = [];

        foreach (self::PUBLICATIONS as $publication) {
            $content = $documentation[$publication['path']] ?? null;

            if (!\is_string($content)) {
                continue;
            }

            $matches = [];
            preg_match_all($publication['pattern'], $content, $matches, \PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$text, $offset]) {
                $registered[$publication['path']][] = [$offset, $offset + \strlen($text)];
            }
        }

        $errors = [];

        foreach ($documentation as $path => $content) {
            $matches = [];
            preg_match_all($sweep, $content, $matches, \PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$text, $offset]) {
                $window = substr($content, max(0, $offset - 200), \strlen($text) + 600);

                // A statement about one channel names no set, so it cannot
                // drift with the set; two named channels make it an enumeration.
                if (($this->numeral($this->leadingNumeral($text)) ?? 1) < 2
                    || preg_match_all($channelToken, $window) < 2) {
                    continue;
                }

                foreach ($registered[$path] ?? [] as [$start, $end]) {
                    if ($offset >= $start && $offset < $end) {
                        continue 2;
                    }
                }

                $errors[] = "Unregistered channel-count statement in {$path}: " . trim($text) . '.';
            }
        }

        return $errors;
    }

    private function leadingNumeral(string $statement): string
    {
        return preg_split('/\s+/u', trim($statement), 2)[0] ?? '';
    }

    private function numeral(string $token): ?int
    {
        $normalized = mb_strtolower(trim($token, " \t\n*_`\"'.,:;—-"));

        if (preg_match('/^\d+$/', $normalized) === 1) {
            return (int) $normalized;
        }

        return self::NUMERALS[$normalized] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function readSweptDocumentation(): array
    {
        $documentation = [];

        foreach (self::SWEPT_ROOTS as $root) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$projectRoot . '/' . $root, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'md') {
                    continue;
                }

                $path = substr($file->getPathname(), \strlen(self::$projectRoot) + 1);

                foreach (self::SWEEP_EXCLUDED_PREFIXES as $excluded) {
                    if (str_starts_with($path, $excluded)) {
                        continue 2;
                    }
                }

                $documentation[$path] = $this->readFile($path);
            }
        }

        ksort($documentation);

        return $documentation;
    }

    private function readFile(string $relativePath): string
    {
        $path = self::$projectRoot . '/' . $relativePath;
        self::assertFileExists($path, "Documentation file not found: {$relativePath}");

        $content = file_get_contents($path);
        \assert($content !== false);

        return $content;
    }
}
