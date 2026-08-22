<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
     * Files legitimately allowed to hold a rule-name or channel-code literal
     * outside the capability that owns it, each with why it cannot be
     * derived instead. An entry with no argument is indistinguishable from
     * an oversight in six months — see
     * `dvizh-vr-workflow:agent-instructions` on decisions without reasons.
     *
     * @var array<string, string>
     */
    private const array ALLOWED_FILES = [
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

    #[Test]
    public function noProductionFileOutsideARuleOrChannelsOwningCapabilityHoldsItsLiteral(): void
    {
        $container = (new ContainerFactory())->create();

        $ownerByRuleName = self::ownerByRuleName($container);

        $ruleExecution = $container->get(RuleExecutionInterface::class);
        \assert($ruleExecution instanceof RuleExecutionInterface);
        $registeredNames = array_map(static fn($metadata) => $metadata->name, $ruleExecution->allRules());

        self::assertSame(
            [],
            array_values(array_diff($registeredNames, array_keys($ownerByRuleName))),
            'RuleExecutionInterface::allRules() names a rule that RuleRegistryInterface::getClasses() does not'
            . ' — the two enumerations of "every registered rule" disagree.',
        );

        $universe = $container->get(ChannelUniverseInterface::class);
        \assert($universe instanceof ChannelUniverseInterface);

        $ownerByLiteral = $ownerByRuleName;

        foreach ($universe->channels() as $channel) {
            $producer = $universe->producerOf($channel->violationCode);
            self::assertNotNull($producer, \sprintf('Channel "%s" names no producer.', $channel->violationCode));
            self::assertArrayHasKey(
                $producer,
                $ownerByRuleName,
                \sprintf('Channel "%s" is produced by "%s", which names no registered rule.', $channel->violationCode, $producer),
            );
            $ownerByLiteral[$channel->violationCode] ??= $ownerByRuleName[$producer];
        }

        self::assertNotEmpty($ownerByLiteral);

        $root = self::projectRoot();
        $violations = [];

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

                $violations[] = \sprintf(
                    '%s holds literal "%s", which belongs to %s.',
                    $relative,
                    $literal,
                    $literalOwner,
                );
            }
        }

        self::assertSame([], $violations, "\n" . implode("\n", $violations));
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
