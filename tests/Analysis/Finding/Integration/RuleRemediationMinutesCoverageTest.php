<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleRemediationMinutesReader;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionClass;

/**
 * "Every rule declares its remediation estimate" is only an invariant if
 * something checks it on every registered rule, not just on the ones a human
 * remembered to look at — mirrors {@see RuleDocsPageCoverageTest}, and see
 * `docs/internal/plans/sarif-channel-descriptions.md`, package P5.
 *
 * The number of registered rules is asserted, not assumed, so a rule quietly
 * dropped from registration cannot shrink the swept set and pass by vacuous
 * agreement — obtained via `bin/qmx rules --no-ansi` ("42 rules available").
 */
#[CoversClass(RuleRemediationMinutesReader::class)]
final class RuleRemediationMinutesCoverageTest extends TestCase
{
    private const int REGISTERED_RULE_COUNT = 42;

    #[Test]
    public function everyRegisteredRuleDeclaresItsOwnRemediationMinutes(): void
    {
        $ruleClasses = self::ruleClasses();
        self::assertCount(self::REGISTERED_RULE_COUNT, $ruleClasses);

        foreach ($ruleClasses as $ruleClass) {
            $reflection = new ReflectionClass($ruleClass);
            self::assertTrue(
                $reflection->hasConstant('REMEDIATION_MINUTES'),
                \sprintf('%s does not declare a REMEDIATION_MINUTES constant.', $ruleClass),
            );

            $constant = $reflection->getReflectionConstant('REMEDIATION_MINUTES');
            self::assertNotFalse($constant, $ruleClass);
            self::assertSame(
                $ruleClass,
                $constant->getDeclaringClass()->getName(),
                \sprintf(
                    '%s does not declare its own REMEDIATION_MINUTES — it only inherits one from %s.',
                    $ruleClass,
                    $constant->getDeclaringClass()->getName(),
                ),
            );
        }
    }

    /**
     * The cross-view page (`website/docs/reference/remediation-time.md`)
     * exists so a reader can compare estimates across rules without visiting
     * 42 files — the same trade the project already makes for default
     * thresholds. A page that drifts from the constants it summarises is
     * worse than no page, so every number on it is checked against the rule
     * that declares it, not merely trusted to have been transcribed right.
     */
    #[Test]
    public function everyRulesRemediationMinutesMatchTheReferencePage(): void
    {
        $page = self::readFile(self::docsRoot() . '/reference/remediation-time.md');

        $missing = [];
        $mismatched = [];

        foreach (self::ruleClasses() as $ruleClass) {
            $ruleName = RuleNameReader::read($ruleClass);
            $declared = RuleRemediationMinutesReader::read($ruleClass);

            if (preg_match('/`' . preg_quote($ruleName, '/') . '`\s*\|\s*(\d+)\s*\|/', $page, $match) !== 1) {
                $missing[] = $ruleName;

                continue;
            }

            $documented = (int) $match[1];

            if ($documented !== $declared) {
                $mismatched[] = \sprintf('%s: page says %d, rule declares %d', $ruleName, $documented, $declared);
            }
        }

        self::assertSame([], $missing, "Rules missing from website/docs/reference/remediation-time.md:\n" . implode("\n", $missing));
        self::assertSame([], $mismatched, "Rules whose page value disagrees with the declared constant:\n" . implode("\n", $mismatched));
    }

    private static function readFile(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, $path);

        return $content;
    }

    private static function docsRoot(): string
    {
        return \dirname(__DIR__, 4) . '/website/docs';
    }

    /** @return list<class-string> */
    private static function ruleClasses(): array
    {
        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        return $registry->getClasses();
    }
}
