<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FormatOptionKeys;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Binds the output-format schemas `website/docs/usage/output-formats.md`
 * publishes to what the formatters actually emit.
 *
 * `DocumentationConsistencyTest` binds rule names and CLI aliases;
 * `RuleDocsPageCoverageTest` binds a rule's page and its anchor. Neither reads
 * a format's key set, so `composer check` stayed green while four published
 * schema facts were wrong — on exactly the surfaces users parse with scripts,
 * where a wrong description breaks a consumer rather than merely confusing a
 * reader.
 *
 * The comparison runs in both directions: a key the product grows and the page
 * does not list is a failure, and so is a key the page lists and the product
 * does not emit.
 *
 * **What a green run here does not prove.** Three blindnesses are structural,
 * and naming them is cheaper than pretending they are closed:
 *
 * 1. The observed key set is a property of `fixture x config x flags`. A key
 *    emitted only under a branch no scenario reaches is invisible here.
 *    {@see itReachesEveryDocumentedBranch()} keeps the scenarios honest about
 *    the branches this page documents, but it cannot know about a branch the
 *    page never mentions. Closing this needs a schema each formatter
 *    *declares*, which is separate work.
 * 2. Nodes listed in {@see SchemaNodeInventory::DATA_KEYED_SCALAR_PATHS} are
 *    not inspected at all: their keys are rule, metric and mechanism names.
 * 3. An absence cannot be refuted by observation. "There is no `callable`
 *    type" is bound to the enum in the code, never to a run, because a value
 *    that never appears is indistinguishable from a fixture that never reached
 *    it.
 *
 * Also deliberately out of scope, so that a green run is not misread as "the
 * page is checked": the illustrative *values* inside fenced examples; the
 * `html` format's embedded coverage data; JSON paths published on other pages
 * such as `guides/architecture-investigation.md`; the `text` format, whose
 * comparison-table cell says `Parseable` rather than `Yes` — it can be grepped,
 * but it promises no key set; and, of the "Analysis coverage in every format"
 * table, only the `json`/`metrics` rows and SARIF's `executionSuccessful` are
 * bound — the per-format coverage projections for `gitlab`, `checkstyle`,
 * `github` and `html` are described there and checked nowhere.
 */
final class OutputFormatSchemaConsistencyTest extends TestCase
{
    /**
     * Formats the page's own comparison table marks as machine-readable, plus
     * the ones whose contract CI parses even though the table says otherwise.
     *
     * @var list<string>
     */
    private const array STRUCTURED_JSON_FORMATS = ['json', 'metrics', 'sarif', 'gitlab', 'suppressed'];

    /**
     * Nodes whose key list a sentence claims to be complete, per page.
     *
     * A sentence that says "Top-level keys:" promises the whole set, so it is
     * compared exactly rather than folded into the union of everything the
     * page says. Measured: while every source was unioned, dropping
     * `topIssues` from that very sentence left the guard green, because the
     * fenced example below it still showed the key — one source silently
     * covering for another, which is how three of the four defects that
     * motivated this guard would have slipped through again.
     *
     * @var array<string, array<string, array<string, list<string>>>>
     */
    private static array $completenessClaims = [];

    /**
     * @return list<OutputFormatScenario>
     */
    public static function scenarios(): array
    {
        return [
            new OutputFormatScenario(
                name: 'full',
                directory: 'full',
                args: [],
                expectedExit: 2,
                expectedCoverage: ['complete' => true, 'failed' => 0],
                contentRequirements: [
                    'a project-level finding, so a SARIF result has no `locations` and a top issue has no `file`',
                    'a finding carrying a typed `edge`',
                    'a top issue with a non-null `coupling.class-rank` and one without',
                    'a global function, so the `metrics` `type` enum can publish `function`',
                ],
            ),
            new OutputFormatScenario(
                name: 'group-by-class',
                directory: 'full',
                args: ['--group-by=class'],
                expectedExit: 2,
                expectedCoverage: ['complete' => true],
                contentRequirements: [
                    'a class FQCN group key, a file-path group key and the empty project key',
                ],
            ),
            new OutputFormatScenario(
                name: 'group-by-namespace',
                directory: 'full',
                args: ['--group-by=namespace'],
                expectedExit: 2,
                expectedCoverage: ['complete' => true],
                contentRequirements: [
                    'a namespace group key, `<global>` for the class outside every namespace, and `__PROJECT__`',
                ],
            ),
            new OutputFormatScenario(
                name: 'broken',
                directory: 'broken',
                args: [],
                expectedExit: 4,
                expectedCoverage: ['complete' => false, 'failed' => 1, 'discovered' => 2, 'analyzed' => 1],
                contentRequirements: ['one unparsable file, so `coverage.failures[]` is not empty'],
            ),
            new OutputFormatScenario(
                name: 'empty',
                directory: 'empty',
                args: ['--only-rule=code-smell.debug-code'],
                expectedExit: 0,
                expectedCoverage: ['complete' => true, 'failed' => 0],
                contentRequirements: ['a complete run that finds nothing at all'],
            ),
            new OutputFormatScenario(
                name: 'suppressed',
                directory: 'suppressed',
                args: [],
                expectedExit: 2,
                expectedCoverage: ['complete' => true],
                contentRequirements: [
                    'a finding an inline `@qmx-ignore` held back, and a configured suppressor that matched nothing',
                ],
            ),
            new OutputFormatScenario(
                name: 'breach',
                directory: 'breach',
                args: ['--baseline=baseline.json'],
                expectedExit: 2,
                expectedCoverage: ['complete' => true],
                contentRequirements: ['a finding whose own identity group exceeds its accepted level'],
            ),
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideStructuredFormats(): iterable
    {
        foreach (self::STRUCTURED_JSON_FORMATS as $format) {
            yield $format => [$format];
        }
    }

    #[Test]
    #[DataProvider('provideStructuredFormats')]
    public function itPublishesFormatSchemasThatMatchTheFormatterOutput(string $format): void
    {
        $observed = self::observedNodes($format);
        $errors = [];

        // Each language is checked on its own. Taking the union of both pages
        // would let one of them cover for the other: measured, dropping
        // `infoCount` from the English page left this guard green because the
        // Russian page still named it, which is precisely the drift it exists
        // to catch.
        foreach ([self::PAGE_EN, self::PAGE_RU] as $page) {
            foreach (self::schemaErrors($format, $observed, self::publishedNodes($format, $page)) as $error) {
                $errors[] = $page . ': ' . $error;
            }

            foreach (self::$completenessClaims[$page][$format] ?? [] as $node => $claimed) {
                $emitted = $observed[$node] ?? [];
                sort($claimed);
                sort($emitted);

                if ($claimed !== $emitted) {
                    $errors[] = \sprintf(
                        '%s: `%s` is published as a complete list [%s] but the product emits [%s].',
                        $page,
                        $node,
                        implode(', ', $claimed),
                        implode(', ', $emitted),
                    );
                }
            }
        }

        self::assertSame([], $errors, implode("\n", $errors));
    }

    /**
     * A key the product grows and the page never gains must redden the guard.
     * Without this the comparison could be vacuous and nobody would know.
     */
    #[Test]
    public function itRejectsProductOnlySchemaDrift(): void
    {
        $observed = self::observedNodes('json');
        $published = self::publishedNodes('json', self::PAGE_EN);

        self::assertSame([], self::schemaErrors('json', $observed, $published), 'The unmutated comparison must be green first.');

        $observed['violations[]'][] = 'newlyGrownKey';

        self::assertNotSame([], self::schemaErrors('json', $observed, $published));
    }

    /**
     * And so must a page that keeps describing a key the product dropped — the
     * direction that leaves a consumer parsing for something never sent.
     */
    #[Test]
    public function itRejectsDocumentationOnlySchemaDrift(): void
    {
        $observed = self::observedNodes('json');
        $published = self::publishedNodes('json', self::PAGE_EN);

        $published['violations[]'][] = 'keyThePageInvented';
        self::assertNotSame([], self::schemaErrors('json', $observed, $published), 'A key only the page knows must be reported.');

        $published = self::publishedNodes('json', self::PAGE_EN);
        $published['violations[]'] = array_values(array_diff($published['violations[]'], ['acceptedLevel']));

        self::assertNotSame([], self::schemaErrors('json', $observed, $published), 'A key the page stops naming must be reported.');
    }

    /**
     * A node the page never mentions at all is the case that found four of the
     * defects this guard was built for, so it gets its own control.
     */
    #[Test]
    public function itRejectsAnEntirelyUndescribedNode(): void
    {
        $observed = self::observedNodes('json');
        $published = self::publishedNodes('json', self::PAGE_EN);
        $observed['violations[].somethingNew'] = ['a', 'b'];

        self::assertNotSame([], self::schemaErrors('json', $observed, $published));
    }

    /**
     * @param array<string, list<string>> $observed
     * @param array<string, list<string>> $published
     *
     * @return list<string>
     */
    private static function schemaErrors(string $format, array $observed, array $published): array
    {
        $errors = [];

        // Both node sets are walked, not just the observed one: a node the
        // page describes and the product never emits is the same defect seen
        // from the other side, and iterating only what was observed made that
        // half of the promise silently vacuous.
        $nodes = array_unique([...array_keys($observed), ...array_keys($published)]);
        sort($nodes);

        foreach ($nodes as $node) {
            $keys = $observed[$node] ?? [];

            if (!isset($published[$node])) {
                $errors[] = \sprintf(
                    '%s: the product emits node `%s` (keys: %s) that the page describes nowhere.',
                    $format,
                    $node,
                    implode(', ', $keys),
                );

                continue;
            }

            if (!isset($observed[$node])) {
                $errors[] = \sprintf(
                    '%s: the page describes node `%s` (keys: %s) that no scenario observed the product emitting.',
                    $format,
                    $node,
                    implode(', ', $published[$node]),
                );

                continue;
            }

            $undocumented = array_values(array_diff($keys, $published[$node]));
            $unemitted = array_values(array_diff($published[$node], $keys));

            if ($undocumented !== []) {
                $errors[] = \sprintf(
                    '%s `%s`: emitted but not documented: %s',
                    $format,
                    $node,
                    implode(', ', $undocumented),
                );
            }

            if ($unemitted !== []) {
                $errors[] = \sprintf(
                    '%s `%s`: documented but not emitted: %s',
                    $format,
                    $node,
                    implode(', ', $unemitted),
                );
            }
        }

        return $errors;
    }

    /**
     * The `metrics` symbol `type` enum.
     *
     * Key sets alone would not have caught the defect this guard was built
     * for: the page listed a `callable` type the product has never emitted,
     * while `method`, `function` and `project` went unmentioned. That is a
     * statement about *values*, so it needs its own comparison — and the
     * fixture carries a global function precisely so `function` is reachable.
     */
    #[Test]
    public function itPublishesTheSymbolTypesItEmits(): void
    {
        $emitted = [];

        foreach (self::scenarios() as $scenario) {
            $report = OutputFormatObservation::json($scenario, 'metrics');
            /** @var list<array<string, mixed>> $symbols */
            $symbols = $report['symbols'] ?? [];

            foreach ($symbols as $symbol) {
                $emitted[(string) $symbol['type']] = true;
            }
        }

        $observed = array_keys($emitted);
        sort($observed);

        foreach ([
            [self::PAGE_EN, '/`type`: (?<types>[a-z\/]+)/u'],
            [self::PAGE_RU, '/`type`: (?<types>[a-z\/]+)/u'],
        ] as [$page, $pattern]) {
            $section = PublishedSchema::sections($page)['metrics'] ?? '';
            $found = preg_match_all($pattern, $section, $matches, \PREG_SET_ORDER);

            self::assertSame(1, $found, \sprintf('Expected one symbol-type enum statement in %s; found %d.', $page, $found));

            $documented = explode('/', $matches[0]['types']);
            sort($documented);

            self::assertSame($documented, $observed, \sprintf(
                'The symbol types %s publishes differ from the ones the product emits.',
                $page,
            ));
        }
    }

    /**
     * The `suppressed` mechanism names, for the same reason: they are values,
     * and the page states their number in words as well as listing them.
     */
    #[Test]
    public function itPublishesTheSuppressionMechanismsItEmits(): void
    {
        $report = OutputFormatObservation::json(self::scenario('suppressed'), 'suppressed');
        /** @var list<string> $emitted */
        $emitted = $report['mechanisms'] ?? [];
        sort($emitted);

        self::assertNotSame([], $emitted, 'The suppressed format published no mechanism list to compare.');

        foreach ([self::PAGE_EN, self::PAGE_RU] as $page) {
            $section = PublishedSchema::sections($page)['suppressed'] ?? '';

            foreach ($emitted as $mechanism) {
                self::assertStringContainsString('`' . $mechanism . '`', $section, \sprintf(
                    'The product reports the `%s` mechanism but %s never names it.',
                    $mechanism,
                    $page,
                ));
            }

            preg_match_all('/`([a-z-]+-suppression|suppression|baseline|git-scope)`/', $section, $named);
            $extra = array_values(array_diff(array_unique($named[1]), $emitted));

            self::assertSame([], $extra, \sprintf(
                '%s names suppression mechanisms the product does not report: %s.',
                $page,
                implode(', ', $extra),
            ));
        }
    }

    /**
     * `locations` must be observed as *sometimes* absent, because that is what
     * the page says about it.
     *
     * This is the one claim the required/optional split exists for. Everywhere
     * else the two halves are merged, but an optionality statement cannot be
     * checked against a merged set: a run where every result happened to carry
     * `locations` would agree with the page just as well as one where some did
     * not, and the sentence would stop being checked without anything moving.
     */
    #[Test]
    public function itObservesTheSarifLocationsOptionalityThePagePublishes(): void
    {
        $report = OutputFormatObservation::json(self::scenario('full'), 'sarif');
        $nodes = SchemaNodeInventory::of($report);
        $results = $nodes['runs[].results[]'] ?? null;

        self::assertIsArray($results, 'No SARIF result node was observed.');
        self::assertContains('locations', $results['optional'], 'The page says `locations` is not on every result, but this run has it on all of them.');
        self::assertNotContains('locations', $results['required']);

        foreach ([self::PAGE_EN, self::PAGE_RU] as $page) {
            self::assertMatchesRegularExpression(
                '/`locations`[^.]{0,80}(is not present on every result|есть не у каждой записи)/u',
                PublishedSchema::sections($page)['sarif'] ?? '',
                \sprintf('%s no longer states that `locations` is optional.', $page),
            );
        }
    }

    /**
     * The two language pages must publish the same schema.
     *
     * This exists because of a measured false green: while the comparison took
     * the union of both pages, removing `infoCount` from the English example
     * left the guard green, because the Russian one still named it. Checking
     * each page against the product separately fixed that, and this test makes
     * the invariant explicit rather than leaving it to be re-derived.
     */
    #[Test]
    #[DataProvider('provideStructuredFormats')]
    public function itPublishesTheSameSchemaInBothLanguages(string $format): void
    {
        $english = self::publishedNodes($format, self::PAGE_EN);
        $russian = self::publishedNodes($format, self::PAGE_RU);
        $differences = [];

        foreach (array_unique([...array_keys($english), ...array_keys($russian)]) as $node) {
            $inEnglish = $english[$node] ?? [];
            $inRussian = $russian[$node] ?? [];
            sort($inEnglish);
            sort($inRussian);

            if ($inEnglish !== $inRussian) {
                $differences[] = \sprintf(
                    '%s `%s`: EN publishes [%s], RU publishes [%s].',
                    $format,
                    $node,
                    implode(', ', $inEnglish),
                    implode(', ', $inRussian),
                );
            }
        }

        self::assertSame([], $differences, implode("\n", $differences));
    }

    /**
     * A scenario that stopped producing what it was built to produce makes the
     * guard weaker without making it red, so the obligations are checked
     * directly: a poorer fixture must not be the easier one to pass.
     */
    #[Test]
    public function itReachesEveryDocumentedBranch(): void
    {
        $unreached = [];

        $full = OutputFormatObservation::json(self::scenario('full'), 'json');
        $sarif = OutputFormatObservation::json(self::scenario('full'), 'sarif');
        $byClass = OutputFormatObservation::json(self::scenario('group-by-class'), 'json');
        $byNamespace = OutputFormatObservation::json(self::scenario('group-by-namespace'), 'json');
        $broken = OutputFormatObservation::json(self::scenario('broken'), 'json');
        $empty = OutputFormatObservation::json(self::scenario('empty'), 'json');
        $breach = OutputFormatObservation::json(self::scenario('breach'), 'json');

        /** @var list<array<string, mixed>> $violations */
        $violations = $full['violations'];
        /** @var list<array<string, mixed>> $topIssues */
        $topIssues = $full['topIssues'];
        /** @var array<int, array<string, mixed>> $results */
        $results = $sarif['runs'][0]['results'];

        // Keyed by "<scenario>: <obligation>", and the obligations are the
        // scenarios' own `$contentRequirements`. Two hand-kept lists would
        // drift: an obligation could be written and never checked, or a check
        // could outlive the obligation it was for.
        $reached = [
            'full: a project-level finding, so a SARIF result has no `locations` and a top issue has no `file`' => self::anyEntry($violations, static fn(array $v): bool => $v['file'] === null)
                && self::anyEntry($results, static fn(array $r): bool => !isset($r['locations']))
                && self::anyEntry($results, static fn(array $r): bool => isset($r['locations'])),
            'full: a finding carrying a typed `edge`' => self::anyEntry($violations, static fn(array $v): bool => \is_array($v['edge']) && isset($v['edge']['type'])),
            'full: a top issue with a non-null `coupling.class-rank` and one without' => self::anyEntry($topIssues, static fn(array $t): bool => $t['coupling.class-rank'] !== null)
                && self::anyEntry($topIssues, static fn(array $t): bool => $t['coupling.class-rank'] === null),
            'full: a global function, so the `metrics` `type` enum can publish `function`' => self::anyEntry(
                self::symbolsOf(OutputFormatObservation::json(self::scenario('full'), 'metrics')),
                static fn(array $symbol): bool => $symbol['type'] === 'function',
            ),
            'group-by-class: a class FQCN group key, a file-path group key and the empty project key' => \array_key_exists('', self::violationGroupsOf($byClass))
                && self::anyGroupKey(self::violationGroupsOf($byClass), static fn(string $k): bool => str_ends_with($k, '.php'))
                && self::anyGroupKey(self::violationGroupsOf($byClass), static fn(string $k): bool => str_contains($k, '\\')),
            'group-by-namespace: a namespace group key, `<global>` for the class outside every namespace, and `__PROJECT__`' => \array_key_exists('<global>', self::violationGroupsOf($byNamespace))
                && \array_key_exists('__PROJECT__', self::violationGroupsOf($byNamespace))
                && self::anyGroupKey(self::violationGroupsOf($byNamespace), static fn(string $k): bool => str_contains($k, '\\')),
            'broken: one unparsable file, so `coverage.failures[]` is not empty' => $broken['coverage']['failures'] !== [],
            'empty: a complete run that finds nothing at all' => $empty['violations'] === [],
            'suppressed: a finding an inline `@qmx-ignore` held back, and a configured suppressor that matched nothing' => self::suppressionsWereObserved(),
            'breach: a finding whose own identity group exceeds its accepted level' => self::anyEntry($breach['violations'], static fn(array $v): bool => $v['acceptedLevel'] !== null),
        ];

        foreach ($reached as $branch => $wasReached) {
            if (!$wasReached) {
                $unreached[] = $branch;
            }
        }

        self::assertSame([], $unreached, "The fixtures no longer reach:\n" . implode("\n", $unreached));

        $declared = [];

        foreach (self::scenarios() as $scenario) {
            foreach ($scenario->contentRequirements as $requirement) {
                $declared[] = $scenario->name . ': ' . $requirement;
            }
        }

        $checked = array_keys($reached);
        sort($declared);
        sort($checked);

        self::assertSame($declared, $checked, 'Every content requirement a scenario declares must have a check, and vice versa.');
    }

    /**
     * Every format the page calls machine-readable must be bound by this
     * guard, and the page's table must agree with what the product accepts.
     *
     * The set comes from two witnesses that can disagree: the product's own
     * refusal message, which enumerates what it will accept, and the page's
     * comparison table. Taking it from a list inside this test instead would
     * mean a new format could be added, documented, and left unguarded without
     * anything noticing.
     *
     * `text-verbose` is the one deliberate difference: it is deprecated and
     * hidden from the product's listing while the page still documents it.
     */
    #[Test]
    public function itCoversEveryFormatThePageCallsMachineReadable(): void
    {
        $accepted = self::formatsTheProductAccepts();
        $tabled = self::formatsTheComparisonTableLists();

        self::assertSame(
            $accepted,
            array_values(array_diff($tabled, ['text-verbose'])),
            'The comparison table and the formats the product accepts have diverged.',
        );

        // The table has three values in this column, not two. `Yes` is a
        // published contract a script parses; `Parseable` (only `text`) means
        // the output can be grepped, which is not a promise about keys and is
        // deliberately not bound here; `No` is prose for humans.
        $machineReadable = [];

        foreach ($tabled as $format) {
            if (self::comparisonTableCell($format, 2) === 'Yes') {
                $machineReadable[] = $format;
            }
        }

        $bound = [...self::STRUCTURED_JSON_FORMATS, 'checkstyle', 'github'];
        $unbound = array_values(array_diff($machineReadable, $bound));

        self::assertSame([], $unbound, \sprintf(
            'The page calls these machine-readable but nothing here binds their schema: %s.',
            implode(', ', $unbound),
        ));
    }

    /**
     * Checkstyle publishes its schema as XML attribute names rather than JSON
     * keys, and CI parses them just as literally. Same comparison, different
     * extractor.
     */
    #[Test]
    public function itPublishesTheCheckstyleAttributesItEmits(): void
    {
        $xml = new SimpleXMLElement(OutputFormatObservation::raw(self::scenario('full'), 'checkstyle'));
        $observed = ['file' => [], 'error' => []];
        $files = $xml->xpath('//file');

        foreach ($files === false || $files === null ? [] : $files as $file) {
            // Accumulated, not assigned: the fixture emits several `<file>`
            // elements with several `<error>` children each, and reading only
            // the last of each would hide an attribute the formatter stops
            // emitting everywhere else.
            $observed['file'] = array_merge($observed['file'], self::attributeNames($file));

            foreach ($file->error ?? [] as $error) {
                $observed['error'] = array_merge($observed['error'], self::attributeNames($error));
            }
        }

        $observed = array_map(
            static function (array $names): array {
                $unique = array_values(array_unique($names));
                sort($unique);

                return $unique;
            },
            $observed,
        );

        foreach ([self::PAGE_EN, self::PAGE_RU] as $page) {
            $section = PublishedSchema::sections($page)['checkstyle'] ?? '';
            $found = preg_match_all('/`<file (?<file>[^`]*)>`.*?`<error (?<error>[^`]*)\/>`/su', $section, $all, \PREG_SET_ORDER);

            self::assertSame(1, $found, \sprintf('Expected exactly one Checkstyle shape statement in %s; found %d.', $page, $found));

            $matches = $all[0];

            foreach (['file', 'error'] as $element) {
                preg_match_all('/([a-zA-Z]+)=/', $matches[$element], $attributes);
                $documented = $attributes[1];
                sort($documented);
                $emitted = $observed[$element];

                self::assertSame($documented, $emitted, \sprintf(
                    'Checkstyle `<%s>` attributes differ from %s.',
                    $element,
                    $page,
                ));
            }
        }
    }

    /**
     * The GitHub format is a line grammar, and an annotation that silently
     * stops appearing is the failure mode — no error, just nothing in the diff.
     */
    #[Test]
    public function itPublishesTheGithubAnnotationGrammarItEmits(): void
    {
        $lines = array_filter(
            explode("\n", OutputFormatObservation::raw(self::scenario('full'), 'github')),
            static fn(string $line): bool => trim($line) !== '',
        );

        self::assertNotSame([], $lines, 'The github scenario emitted no annotation to compare.');

        $emittedLevels = [];
        $perLine = [];

        foreach ($lines as $line) {
            $found = preg_match('/^::(?<level>[a-z]+) (?<params>[^:]*)::/', $line, $matches);
            self::assertSame(1, $found, \sprintf('Unparseable annotation: %s', $line));

            $emittedLevels[$matches['level']] = true;
            $parameters = [];

            foreach (explode(',', $matches['params']) as $parameter) {
                $parameters[] = explode('=', $parameter)[0];
            }

            $perLine[] = $parameters;
        }

        // A parameter on only some lines is optional, and publishing it as
        // part of the one grammar would be wrong: a project-level finding has
        // no source position and carries neither `file=` nor `line=`.
        $emitted = array_values(array_unique(array_merge(...$perLine)));
        $always = array_values(array_intersect(...$perLine));
        sort($emitted);
        sort($always);
        $conditional = array_values(array_diff($emitted, $always));

        foreach ([self::PAGE_EN, self::PAGE_RU] as $page) {
            $section = PublishedSchema::sections($page)['github'] ?? '';
            $found = preg_match_all('/`::<level> (?<params>[^`]*),title=<rule>::<message>`/su', $section, $all, \PREG_SET_ORDER);

            self::assertSame(1, $found, \sprintf('Expected exactly one full github grammar statement in %s; found %d.', $page, $found));

            $matches = $all[0];
            $matches['params'] .= ',title=';

            preg_match_all('/([a-zA-Z]+)=/', $matches['params'], $parameters);
            $documented = $parameters[1];
            sort($documented);

            self::assertSame($documented, $emitted, \sprintf('The github annotation parameters differ from %s.', $page));

            if ($conditional === []) {
                continue;
            }

            // The reduced form must be published too, or the page states one
            // grammar for output that has two.
            $reduced = [];

            foreach (self::githubGrammars($section) as $grammar) {
                if ($grammar !== $documented) {
                    $reduced[] = $grammar;
                }
            }

            self::assertContains($always, $reduced, \sprintf(
                '%s publishes one annotation grammar, but %s only appear on some lines; the form carrying just [%s] is undocumented.',
                $page,
                implode(', ', $conditional),
                implode(', ', $always),
            ));

            foreach (array_keys($emittedLevels) as $level) {
                self::assertStringContainsString(
                    '`::' . $level . '`',
                    $section,
                    \sprintf('%s emits `::%s` but %s does not document that level.', 'github', $level, $page),
                );
            }
        }
    }

    /**
     * The pipeline must refuse a run that is not an observation. Without this,
     * the comparison tests would pass on silence.
     */
    #[Test]
    public function itRejectsABrokenObservationPipeline(): void
    {
        $wrongExit = new OutputFormatScenario(
            name: 'wrong-exit',
            directory: 'full',
            args: [],
            expectedExit: 0,
            expectedCoverage: [],
            contentRequirements: [],
        );

        $this->expectExceptionMessageMatches('/exited 2, expected 0/');
        OutputFormatObservation::json($wrongExit, 'json');
    }

    #[Test]
    public function itRejectsAnUnmetCoverageExpectation(): void
    {
        $wrongCoverage = new OutputFormatScenario(
            name: 'wrong-coverage',
            directory: 'full',
            args: [],
            expectedExit: 2,
            expectedCoverage: ['failed' => 7],
            contentRequirements: [],
        );

        $this->expectExceptionMessageMatches('/coverage\.failed = 0, expected 7/');
        OutputFormatObservation::json($wrongCoverage, 'json');
    }

    #[Test]
    public function itRejectsARunWhoseInputsDoNotResolve(): void
    {
        $missingBaseline = new OutputFormatScenario(
            name: 'missing-baseline',
            directory: 'full',
            args: ['--baseline=no-such-baseline.json'],
            expectedExit: 2,
            expectedCoverage: [],
            contentRequirements: [],
        );

        // Named by the exit the product actually gives for an input it cannot
        // resolve, so the control cannot pass on some other failure.
        $this->expectExceptionMessageMatches('/exited 3, expected 2/');
        OutputFormatObservation::json($missingBaseline, 'json');
    }

    /**
     * The union of what every source on both language pages says about each
     * node. Neither voice is complete alone: the JSON example omits `coverage`,
     * which only the prose lists.
     *
     * @return array<string, list<string>>
     */
    private static function publishedNodes(string $format, string $page): array
    {
        $nodes = [];

        foreach (PublishedSchema::nodesFromExamples($page, $format) as $node => $keys) {
            $nodes[$node] = array_values(array_unique(array_merge($nodes[$node] ?? [], $keys)));
        }

        foreach (self::proseNodes($format, $page) as $node => $keys) {
            $nodes[$node] = array_values(array_unique(array_merge($nodes[$node] ?? [], $keys)));
        }

        return $nodes;
    }

    /**
     * Statements the page makes in prose rather than in an example.
     *
     * @return array<string, list<string>>
     */
    private static function proseNodes(string $format, string $onlyPage): array
    {
        $nodes = [];

        foreach (self::proseStatements()[$format] ?? [] as $node => $sources) {
            foreach ($sources as $source) {
                [$page, $section, $pattern] = $source;
                $claimsCompleteness = $source[3] ?? false;

                if ($page !== $onlyPage) {
                    continue;
                }

                $text = PublishedSchema::sections($page)[$section] ?? '';

                self::assertNotSame('', $text, \sprintf('Section "%s" is missing from %s.', $section, $page));

                $found = preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER);

                self::assertSame(1, $found, \sprintf(
                    'Expected exactly one statement about `%s` in the "%s" section of %s; found %d. '
                    . 'A re-wrapped paragraph stops being checked silently.',
                    $node,
                    $section,
                    $page,
                    $found,
                ));

                foreach (PublishedSchema::nodesIn($matches[0]['keys'], $node) as $path => $keys) {
                    $nodes[$path] = array_values(array_unique(array_merge($nodes[$path] ?? [], $keys)));

                    if ($claimsCompleteness && $path === $node) {
                        self::$completenessClaims[$onlyPage][$format][$node] = $keys;
                    }
                }
            }
        }

        return $nodes;
    }

    /**
     * Prose statements that publish a node's key set, scoped to the section
     * they live in — `**Top-level keys:**` appears once per format, so a
     * page-wide match would find three and bind none of them.
     *
     * Every pattern tolerates the page's hard wrap (`\s+`, never a literal
     * space) and must match exactly once inside its section.
     *
     * @return array<string, array<string, list<array{string, string, string}>>>
     */
    private static function proseStatements(): array
    {
        $coverageTable = [
            'coverage' => [
                [self::PAGE_EN, 'Analysis', '/\|\s*`json`\s*\|\s*Top-level `coverage` object:(?<keys>[^|]*)\|/u'],
                [self::PAGE_RU, 'Покрытие', '/\|\s*`json`\s*\|\s*Объект `coverage` верхнего уровня:(?<keys>[^|]*)\|/u'],
            ],
            'coverage.failures[]' => [
                [self::PAGE_EN, 'Analysis', '/each `failures\[\]` item has(?<keys>.*?)\.\s+Human formats/su'],
                [self::PAGE_RU, 'Покрытие', '/каждый элемент `failures\[\]` содержит(?<keys>.*?)\.\s+Текстовые/su'],
            ],
        ];

        return [
            'json' => [
                '(root)' => [
                    [self::PAGE_EN, 'json', '/\*\*Top-level keys:\*\*(?<keys>.*?`violationGroups`)/su', true],
                    [self::PAGE_RU, 'json', '/\*\*Ключи верхнего уровня:\*\*(?<keys>.*?`violationGroups`)/su', true],
                ],
                'violationGroups.{}' => [
                    [self::PAGE_EN, 'json', '/Each group is\s+(?<keys>`\{count, violations\}`)/su'],
                    [self::PAGE_RU, 'json', '/Каждая группа — это\s+(?<keys>`\{count, violations\}`)/su'],
                ],
                'worstNamespaces[]' => [
                    [self::PAGE_EN, 'json', '/The `worstNamespaces` and `worstClasses` entries include a\s+(?<keys>`violationDensity`) field/su'],
                    [self::PAGE_RU, 'json', '/Записи `worstNamespaces` и `worstClasses` включают поле\s+(?<keys>`violationDensity`)/su'],
                ],
                'worstClasses[]' => [
                    [self::PAGE_EN, 'json', '/The `worstNamespaces` and `worstClasses` entries include a\s+(?<keys>`violationDensity`) field/su'],
                    [self::PAGE_RU, 'json', '/Записи `worstNamespaces` и `worstClasses` включают поле\s+(?<keys>`violationDensity`)/su'],
                ],
                'violations[].edge' => [
                    [self::PAGE_EN, 'json', '/a typed edge is\s+`(?<keys>\{[^`]*\})`/su'],
                    [self::PAGE_RU, 'json', '/типизированное —\s+`(?<keys>\{[^`]*\})`/su'],
                ],
                'violations[].acceptedLevel' => [
                    [self::PAGE_EN, 'json', '/carries\s+`(?<keys>\{"shape".*?\})`/su'],
                    [self::PAGE_RU, 'json', '/несёт `(?<keys>\{"shape".*?\})`/su'],
                ],
                ...$coverageTable,
            ],
            'sarif' => [
                // Two sentences, two sources: capturing the whole paragraph
                // would also sweep up `executionSuccessful` and `uriBaseId`,
                // which belong to nodes further down, not to `runs[]`.
                'runs[]' => [
                    [self::PAGE_EN, 'sarif', '/(?<keys>`runs\[\]\.invocations\[0\]`) reports/su'],
                    [self::PAGE_EN, 'sarif', '/(?<keys>`runs\[\]\.originalUriBaseIds`) declares/su'],
                    [self::PAGE_RU, 'sarif', '/(?<keys>`runs\[\]\.invocations\[0\]`) сообщает/su'],
                    [self::PAGE_RU, 'sarif', '/(?<keys>`runs\[\]\.originalUriBaseIds`) объявляет/su'],
                ],
                'runs[].results[]' => [
                    [self::PAGE_EN, 'sarif', '/spec — `runs\[\]\.results\[\]` entries with(?<keys>.*?)\.\s+`locations` is not/su', true],
                    [self::PAGE_RU, 'sarif', '/SARIF 2\.1\.0: `runs\[\]\.results\[\]` с(?<keys>.*?)\.\s+`locations` есть/su', true],
                ],
                'runs[].tool.driver' => [
                    [self::PAGE_EN, 'sarif', '/describes the tool itself:(?<keys>.*?)catalogue/su'],
                    // The Russian sentence puts `rules[]` *after* the word
                    // "каталог", so the boundary is its own, not a translation
                    // of the English one.
                    [self::PAGE_RU, 'sarif', '/описывает сам инструмент:(?<keys>.*?`rules\[\]`)/su'],
                ],
                'runs[].tool.driver.rules[]' => [
                    [self::PAGE_EN, 'sarif', '/Each rules entry is\s+`(?<keys>\{"id".*?\}\})`/su'],
                    [self::PAGE_RU, 'sarif', '/Каждая запись каталога —\s+`(?<keys>\{"id".*?\}\})`/su'],
                ],
                'runs[].invocations[]' => [
                    [self::PAGE_EN, 'sarif', '/`runs\[\]\.invocations\[\]` entry is\s+`(?<keys>\{"executionSuccessful".*?\})`/su'],
                    [self::PAGE_RU, 'sarif', '/Каждая запись `runs\[\]\.invocations\[\]` —\s+`(?<keys>\{"executionSuccessful".*?\})`/su'],
                ],
                'runs[].invocations[].toolExecutionNotifications[]' => [
                    [self::PAGE_EN, 'sarif', '/notification in it is\s+`(?<keys>\{"descriptor".*?\}\})`/su'],
                    [self::PAGE_RU, 'sarif', '/уведомление в ней —\s+`(?<keys>\{"descriptor".*?\}\})`/su'],
                ],
                'runs[].originalUriBaseIds' => [
                    [self::PAGE_EN, 'sarif', '/maps (?<keys>`%SRCROOT%`) to/su'],
                    [self::PAGE_RU, 'sarif', '/отображает (?<keys>`%SRCROOT%`) в/su'],
                ],
                'runs[].originalUriBaseIds.%SRCROOT%' => [
                    [self::PAGE_EN, 'sarif', '/`%SRCROOT%` to\s+`(?<keys>\{"uri".*?\})`/su'],
                    [self::PAGE_RU, 'sarif', '/`%SRCROOT%` в\s+`(?<keys>\{"uri".*?\})`/su'],
                ],
            ],
            'gitlab' => [
                '[]' => [
                    [self::PAGE_EN, 'gitlab', '/Array of objects with(?<keys>.*?)\.\s+Severity mapping/su', true],
                    [self::PAGE_RU, 'gitlab', '/Массив объектов с(?<keys>.*?)\.\s+Маппинг/su', true],
                ],
            ],
            'metrics' => [
                '(root)' => [
                    [self::PAGE_EN, 'metrics', '/\*\*Top-level keys:\*\*(?<keys>.*?`coverage`,\s*`summary`)\./su', true],
                    [self::PAGE_RU, 'metrics', '/\*\*Ключи верхнего уровня:\*\*(?<keys>.*?`coverage`,\s*`summary`)\./su', true],
                ],
                'symbols[]' => [
                    [self::PAGE_EN, 'metrics', '/`symbols\[\]`\s*\((?<keys>each with.*?)\)/su'],
                    [self::PAGE_RU, 'metrics', '/`symbols\[\]`\s*\((?<keys>каждый с.*?)\)/su'],
                ],
                ...$coverageTable,
            ],
            'suppressed' => [
                '(root)' => [
                    [self::PAGE_EN, 'suppressed', '/\*\*Top-level keys:\*\*(?<keys>.*?`neverMatched`)\./su', true],
                    [self::PAGE_RU, 'suppressed', '/\*\*Ключи верхнего уровня:\*\*(?<keys>.*?`neverMatched`)\./su', true],
                ],
            ],
        ];
    }

    private const string PAGE_EN = PublishedSchema::PAGE_EN;
    private const string PAGE_RU = PublishedSchema::PAGE_RU;

    /**
     * @return array<string, list<string>>
     */
    private static function observedNodes(string $format): array
    {
        $nodes = [];

        foreach (self::scenarios() as $scenario) {
            if ($format === 'suppressed' && $scenario->name !== 'suppressed') {
                continue;
            }

            $report = OutputFormatObservation::json($scenario, $format);
            $inventory = SchemaNodeInventory::of($report);

            foreach ($inventory as $node => $halves) {
                $keys = array_merge($halves['required'], $halves['optional']);
                $nodes[$node] = array_values(array_unique(array_merge($nodes[$node] ?? [], $keys)));
            }
        }

        foreach ($nodes as $node => $keys) {
            sort($keys);
            $nodes[$node] = $keys;
        }

        ksort($nodes);

        return $nodes;
    }

    /**
     * The formats the product itself says it accepts, read from its refusal.
     *
     * @return list<string>
     */
    private static function formatsTheProductAccepts(): array
    {
        $scenario = new OutputFormatScenario(
            name: 'format-refusal',
            directory: 'full',
            args: [],
            expectedExit: 3,
            expectedCoverage: [],
            contentRequirements: [],
        );

        $output = OutputFormatObservation::refusal($scenario, 'nosuchformat');
        $found = preg_match('/is not one of:(?<formats>[^.]*)\./', $output, $matches);

        self::assertSame(1, $found, \sprintf('The product no longer enumerates formats when refusing one: %s', $output));

        $formats = array_map(trim(...), explode(',', $matches['formats']));
        sort($formats);

        return $formats;
    }

    /**
     * @return list<string>
     */
    private static function formatsTheComparisonTableLists(): array
    {
        $perPage = [];

        foreach ([self::PAGE_EN => 'Comparison', self::PAGE_RU => 'Сравнительная'] as $page => $section) {
            preg_match_all(
                '/^\| `([a-z-]+)`\s*\|/mu',
                PublishedSchema::sections($page)[$section] ?? '',
                $matches,
            );

            $formats = $matches[1];
            sort($formats);
            $perPage[$page] = $formats;
        }

        // Read both, or the Russian table could list a different set of
        // formats indefinitely without anything noticing.
        self::assertSame(
            $perPage[self::PAGE_EN],
            $perPage[self::PAGE_RU],
            'The comparison tables on the two language pages list different formats.',
        );

        return $perPage[self::PAGE_EN];
    }

    private static function comparisonTableCell(string $format, int $column): string
    {
        $found = preg_match_all(
            '/^\| `' . preg_quote($format, '/') . '`\s*\|(?<cells>.*)$/mu',
            PublishedSchema::sections(self::PAGE_EN)['Comparison'] ?? '',
            $all,
            \PREG_SET_ORDER,
        );

        self::assertSame(1, $found, \sprintf('Expected exactly one comparison-table row for `%s`; found %d.', $format, $found));

        $matches = $all[0];

        $cells = array_map(trim(...), explode('|', $matches['cells']));

        return $cells[$column - 1] ?? '';
    }

    /**
     * @return list<string>
     */
    private static function attributeNames(SimpleXMLElement $element): array
    {
        $names = [];

        foreach ($element->attributes() ?? [] as $name => $_value) {
            $names[] = (string) $name;
        }

        return $names;
    }

    /**
     * Every annotation grammar the section publishes, as its parameter names.
     *
     * @return list<list<string>>
     */
    private static function githubGrammars(string $section): array
    {
        preg_match_all('/`::<level>([^`]*)::<message>`/u', $section, $matches, \PREG_SET_ORDER);
        $grammars = [];

        foreach ($matches as $match) {
            preg_match_all('/([a-zA-Z]+)=/', $match[1], $parameters);
            $names = $parameters[1];
            sort($names);
            $grammars[] = $names;
        }

        return $grammars;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<array<string, mixed>>
     */
    private static function symbolsOf(array $report): array
    {
        /** @var list<array<string, mixed>> $symbols */
        $symbols = $report['symbols'] ?? [];

        return $symbols;
    }

    private static function suppressionsWereObserved(): bool
    {
        $report = OutputFormatObservation::json(self::scenario('suppressed'), 'suppressed');

        return ($report['suppressed'] ?? []) !== [] && ($report['neverMatched'] ?? []) !== [];
    }

    private static function scenario(string $name): OutputFormatScenario
    {
        foreach (self::scenarios() as $scenario) {
            if ($scenario->name === $name) {
                return $scenario;
            }
        }

        self::fail(\sprintf('No scenario named "%s".', $name));
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private static function violationGroupsOf(array $report): array
    {
        $groups = $report['violationGroups'] ?? [];

        return \is_array($groups) ? $groups : [];
    }

    /**
     * @param array<array-key, array<string, mixed>> $entries
     * @param callable(array<string, mixed>): bool $predicate
     */
    private static function anyEntry(array $entries, callable $predicate): bool
    {
        foreach ($entries as $entry) {
            if ($predicate($entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entries
     * @param callable(string): bool $predicate
     */
    private static function anyGroupKey(array $entries, callable $predicate): bool
    {
        foreach (array_keys($entries) as $key) {
            if ($predicate((string) $key)) {
                return true;
            }
        }

        return false;
    }
}
