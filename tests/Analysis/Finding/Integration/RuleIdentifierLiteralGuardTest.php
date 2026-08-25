<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A consumer holding a table keyed by another module's rule names or channel
 * codes is the exact defect class
 * `docs/internal/plans/sarif-channel-descriptions.md` was written to remove —
 * measured there twice: `SarifRuleCollector`'s `getRuleDescription()` `match`
 * and `CATEGORY_DOCS_MAP`, and `RemediationTimeRegistry`'s `MINUTES_BY_RULE`
 * and `INVERTED_RULES`. Both had already drifted from the rule/declaration
 * they copied before anyone noticed.
 *
 * This guard makes a third occurrence fail a build instead of drifting
 * quietly. It does not grep for a name prefix — the plan's own retrospective
 * names that as a false-positive machine (`ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES
 * = 'coupling.framework_namespaces'` contains "coupling." as a mere prefix
 * of a config key, not a channel code). Instead it builds the exact set of
 * registered rule names and emitted channel codes from the live container —
 * {@see RuleExecutionInterface::allRules()} and
 * {@see ChannelUniverseInterface::channels()} — and only ever compares a
 * source file's string literals against **exact membership** in that set.
 *
 * Ownership is read from where PHP itself resolves each name: a rule's own
 * class (via {@see RuleRegistryInterface::getClasses()} and
 * {@see RuleNameReader}) sits under its owning capability root by the
 * project's own PSR-4 mapping (`Qualimetrix\` -> `src/`), so the capability
 * root of a rule class and of a production file are computed by the same
 * path-segment rule (ADR 0022: `src/Analysis/{Evidence,Policy}/{Capability}`,
 * else the first one or two `src/` segments). A channel's owner is its
 * producing rule's owner, read via
 * {@see ChannelUniverseInterface::producerOf()} — never its own `ruleName`
 * half, which for architecture/annotation diagnostics names no rule class at
 * all (see {@see \Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface}).
 */
final class RuleIdentifierLiteralGuardTest extends TestCase
{
    /**
     * The owner of every producer the computed-metric family declares without
     * a class — the capability that declares them, computed the same way
     * {@see capabilityRootFromClass()} computes a rule class's.
     */
    private const string COMPUTED_METRICS_CAPABILITY_ROOT = 'Analysis/Evidence/ComputedMetrics';

    /**
     * Files legitimately allowed to hold a rule-name or channel-code literal
     * outside the capability that owns it, each with why it cannot be
     * derived instead. An entry with no argument is indistinguishable from
     * an oversight in six months — see
     * `dvizh-vr-workflow:agent-instructions` on decisions without reasons.
     *
     * @var array<string, string>
     */
    private const array ALLOWED_FILES = [
        'src/Analysis/Finding/Contract/Rule/RuleCategory.php' =>
            'Not a copy of a producer name — a coincidence between two vocabularies. The enum\'s backing'
            . ' values are the display groups `qmx rules --group` accepts, and one of them ("computed")'
            . ' happens to be spelled like the open producer of the computed-metric family, because both'
            . ' name the same idea from different sides. A category is deliberately not addressable (see'
            . ' the enum\'s own docblock), so nothing resolves this literal as a rule name, and deriving'
            . ' the group label from a producer name would make the name space\'s spelling a behavioural'
            . ' contract again — the exact thing that docblock records having removed.',
        'src/Analysis/Finding/RuleConfiguration/RuleThresholdKeyGroupRegistry.php' =>
            'Declared, audited hand-kept copy of each rule\'s ThresholdParser::parse()'
            . ' key spelling. Its own docblock argues why it cannot be derived at'
            . ' configuration-merge time: RuleOptionThresholdModeResolver runs before'
            . ' any rule\'s Options::fromArray() is invoked, and Options classes live'
            . ' with their owning rule capability, which Configuration may not depend'
            . ' on. Every entry is exercised end-to-end by RuleOptionsFactoryTest /'
            . ' ConfigurationMergerTest. See'
            . ' docs/internal/plans/sarif-channel-descriptions.md, "Two sites checked'
            . ' and cleared".',
    ];

    /**
     * Non-PHP files carrying rule names or finding codes by hand — a
     * fixture, a formatted-output example — have no owning capability to
     * check *ownership* against, but they still name something real: a
     * hand-spelled code that names no registered rule or channel is exactly
     * the drift `fix(reporting): remove hand-spelled finding codes from
     * dev.html and docs` swept once by hand (severity suffixes mistaken for
     * sub-codes, a nonexistent `size.class-loc`, a stale
     * `size.method-count.class` — see `docs/internal/plans/sarif-channel-descriptions.md`,
     * package P6). Nothing stopped it from coming back the same way, so this
     * checks existence rather than ownership for exactly the files P6 swept.
     *
     * @var list<string>
     */
    private const array EXISTENCE_CHECKED_FILES = [
        'src/Reporting/Template/dev.html',
        'website/docs/usage/output-formats.md',
        'website/docs/usage/output-formats.ru.md',
    ];

    #[Test]
    public function noProductionFileOutsideARuleOrChannelsOwningCapabilityHoldsItsLiteral(): void
    {
        $container = (new ContainerFactory())->create();

        $ownerByRuleName = self::ownerByRuleName($container);

        $ruleExecution = $container->get(RuleExecutionInterface::class);
        \assert($ruleExecution instanceof RuleExecutionInterface);
        $registeredNames = array_map(static fn($metadata) => $metadata->name, $ruleExecution->allRules());

        // Two halves, because a registered producer is no longer the same
        // thing as a rule class. A name that is neither a class nor a declared
        // classless producer means the two enumerations of "every registered
        // rule" have drifted; a classless producer that names no capability
        // owner would leave its literal unguarded everywhere.
        foreach (ComputedMetricChannelFamily::PRODUCER_RULE_NAMES as $producerRuleName) {
            $ownerByRuleName[$producerRuleName] ??= self::COMPUTED_METRICS_CAPABILITY_ROOT;
        }

        self::assertSame(
            [],
            array_values(array_diff($registeredNames, array_keys($ownerByRuleName))),
            'RuleExecutionInterface::allRules() names a producer that is neither a registered rule class nor a'
            . ' declared classless producer of the computed-metric family.',
        );

        self::assertSame(
            [],
            array_values(array_diff(array_keys($ownerByRuleName), $registeredNames)),
            'A rule class or a declared classless producer is missing from RuleExecutionInterface::allRules().',
        );

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $ownerByLiteral = $ownerByRuleName;

        foreach ($universe->channels() as $channel) {
            $producer = $universe->producerOf($channel->code);
            self::assertNotNull($producer, \sprintf('Channel "%s" names no producer.', $channel->code));
            self::assertArrayHasKey(
                $producer,
                $ownerByRuleName,
                \sprintf('Channel "%s" is produced by "%s", which names no registered rule.', $channel->code, $producer),
            );
            $ownerByLiteral[$channel->code] ??= $ownerByRuleName[$producer];
        }

        $root = self::projectRoot();
        $findings = [];

        foreach (self::productionPhpFiles($root) as $absolutePath) {
            $relative = substr($absolutePath, \strlen($root) + 1);

            if (\array_key_exists($relative, self::ALLOWED_FILES)) {
                continue;
            }

            $fileOwner = self::capabilityRootFromRelativePath($relative);

            foreach (self::stringLiterals($absolutePath) as $literal) {
                if (!isset($ownerByLiteral[$literal])) {
                    continue;
                }

                $literalOwner = $ownerByLiteral[$literal];

                if ($literalOwner === $fileOwner) {
                    continue;
                }

                $findings[] = \sprintf(
                    '%s holds literal "%s", which belongs to %s.',
                    $relative,
                    $literal,
                    $literalOwner,
                );
            }
        }

        self::assertSame([], $findings, "\n" . implode("\n", $findings));
    }

    #[Test]
    public function noHandSpelledCodeInAFixtureOrDocPageNamesANonexistentRuleOrChannel(): void
    {
        $container = (new ContainerFactory())->create();

        $knownNames = [...array_keys(self::ownerByRuleName($container)), ...ComputedMetricChannelFamily::PRODUCER_RULE_NAMES];

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);
        $knownCodes = array_map(static fn($channel) => $channel->code, $universe->channels());

        $known = array_flip([...$knownNames, ...$knownCodes]);

        $root = self::projectRoot();
        $findings = [];

        foreach (self::EXISTENCE_CHECKED_FILES as $relative) {
            foreach (self::handSpelledIdentifierLiterals($root . '/' . $relative) as $literal) {
                if (isset($known[$literal])) {
                    continue;
                }

                $findings[] = \sprintf('%s holds "%s", which names no registered rule or channel.', $relative, $literal);
            }
        }

        self::assertSame([], $findings, "\n" . implode("\n", $findings));
    }

    /**
     * Extracts every value that a fixture or a formatted-output example
     * spells as a rule name or a finding code, without requiring these
     * non-PHP files to parse as one well-formed document: `"rule"` /
     * `"ruleName"` / `"code"` / `"violationCode"` values, both halves of a
     * `"channel": "producer#code"` value, and the keys of a `"byRule": {...}`
     * tally block.
     *
     * @return list<string>
     */
    private static function handSpelledIdentifierLiterals(string $absolutePath): array
    {
        $source = file_get_contents($absolutePath);

        if ($source === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $absolutePath));
        }

        $literals = [];

        if (preg_match_all('/"(?:rule|code|ruleName|violationCode)"\s*:\s*"([a-zA-Z][\w.\-]*)"/', $source, $matches) > 0) {
            $literals = [...$literals, ...$matches[1]];
        }

        if (preg_match_all('/"channel"\s*:\s*"([a-zA-Z][\w.\-]*)#([a-zA-Z][\w.\-]*)"/', $source, $matches) > 0) {
            $literals = [...$literals, ...$matches[1], ...$matches[2]];
        }

        if (preg_match('/"byRule"\s*:\s*\{([^}]*)\}/', $source, $block) === 1
            && preg_match_all('/"([a-zA-Z][\w.\-]*)"\s*:/', $block[1], $matches) > 0) {
            $literals = [...$literals, ...$matches[1]];
        }

        return array_values(array_unique($literals));
    }

    /**
     * @return array<string, string> rule name => its capability root
     */
    private static function ownerByRuleName(ContainerBuilder $container): array
    {
        $registry = $container->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        $owners = [];

        foreach ($registry->getClasses() as $ruleClass) {
            $owners[RuleNameReader::read($ruleClass)] = self::capabilityRootFromClass($ruleClass);
        }

        return $owners;
    }

    /**
     * The capability root a class or a `src/`-relative path belongs to,
     * under ADR 0022's physical layout: `Analysis/{Evidence,Policy}/{X}` is
     * rooted three segments deep, every other `Analysis/{X}` two segments
     * deep (`Finding`, `Configuration`, `Run`), and `Core`/`Reporting`/
     * `Infrastructure` one segment deep. Shared so a rule class's owner and a
     * source file's owner are computed by the identical rule — the project's
     * own PSR-4 mapping (`Qualimetrix\` -> `src/`) makes the two path forms
     * equivalent one segment at a time.
     *
     * @param class-string $ruleClass
     */
    private static function capabilityRootFromClass(string $ruleClass): string
    {
        $segments = explode('\\', ltrim($ruleClass, '\\'));
        array_shift($segments); // drop the "Qualimetrix" vendor segment

        return self::capabilityRootFromSegments($segments);
    }

    private static function capabilityRootFromRelativePath(string $relativePath): string
    {
        $segments = explode('/', $relativePath);
        array_shift($segments); // drop the "src" segment

        return self::capabilityRootFromSegments($segments);
    }

    /**
     * @param list<string> $segments
     */
    private static function capabilityRootFromSegments(array $segments): string
    {
        if (($segments[0] ?? null) === 'Analysis' && \in_array($segments[1] ?? null, ['Evidence', 'Policy'], true)) {
            return implode('/', \array_slice($segments, 0, 3));
        }

        if (($segments[0] ?? null) === 'Analysis') {
            return implode('/', \array_slice($segments, 0, 2));
        }

        return $segments[0] ?? '';
    }

    /**
     * Every string literal token in a PHP source file, decoded to its
     * runtime value. Token-based rather than a regex match, and compared by
     * the caller only against exact set membership — never a substring or
     * prefix test, which is what let `ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES`
     * masquerade as a channel-code hit during this plan's own investigation.
     *
     * @return list<string>
     */
    private static function stringLiterals(string $absolutePath): array
    {
        $source = file_get_contents($absolutePath);

        if ($source === false) {
            throw new RuntimeException(\sprintf('Could not read %s.', $absolutePath));
        }

        $literals = [];

        foreach (token_get_all($source) as $token) {
            if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literals[] = self::decodeStringLiteralToken($token[1]);
        }

        return $literals;
    }

    /**
     * Decodes a `T_CONSTANT_ENCAPSED_STRING` token's raw text (quotes
     * included) to the string value PHP would produce. `T_CONSTANT_ENCAPSED_STRING`
     * covers only non-interpolated single- and double-quoted strings — the
     * two escaping rules this decodes.
     */
    private static function decodeStringLiteralToken(string $raw): string
    {
        $quote = $raw[0];
        $body = substr($raw, 1, -1);

        if ($quote === '\'') {
            return str_replace(['\\\\', '\\\''], ['\\', '\''], $body);
        }

        return stripcslashes($body);
    }

    /**
     * @return list<string> absolute paths
     */
    private static function productionPhpFiles(string $root): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
