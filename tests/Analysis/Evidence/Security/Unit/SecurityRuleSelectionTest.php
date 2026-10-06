<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Security\CommandInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\HardcodedCredentialsOptions;
use Qualimetrix\Analysis\Evidence\Security\HardcodedCredentialsRule;
use Qualimetrix\Analysis\Evidence\Security\SecurityPatternOptions;
use Qualimetrix\Analysis\Evidence\Security\SensitiveParameterOptions;
use Qualimetrix\Analysis\Evidence\Security\SensitiveParameterRule;
use Qualimetrix\Analysis\Evidence\Security\SqlInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\XssRule;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Finding\FindingPublication;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(FindingPublication::class)]
#[CoversClass(HardcodedCredentialsRule::class)]
#[CoversClass(SensitiveParameterRule::class)]
#[CoversClass(CommandInjectionRule::class)]
#[CoversClass(SqlInjectionRule::class)]
#[CoversClass(XssRule::class)]
final class SecurityRuleSelectionTest extends TestCase
{
    /**
     * @param class-string<HardcodedCredentialsRule|SensitiveParameterRule|SqlInjectionRule|XssRule|CommandInjectionRule> $ruleClass
     * @param array<string, int|string> $entry
     */
    #[Test]
    #[DataProvider('producedLevels')]
    public function itPublishesEachProducedCredentialOrSecurityPatternLevel(
        string $ruleClass,
        string $metric,
        array $entry,
    ): void {
        $path = RelativePath::fromString('src/Security/Example.php');
        $fileSymbol = SymbolPath::forFile($path);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([new SymbolInfo($fileSymbol, $path, null)]);
        $repository->method('get')->willReturn((new MetricBag())->withEntry($metric, $entry));

        $options = match ($ruleClass) {
            HardcodedCredentialsRule::class => new HardcodedCredentialsOptions(),
            SensitiveParameterRule::class => new SensitiveParameterOptions(),
            default => new SecurityPatternOptions(),
        };
        $rule = new $ruleClass($options);
        $findings = $rule->analyze(new AnalysisContext($repository));
        self::assertCount(1, $findings);
        self::assertSame($findings[0]->subject->toSymbolPath()->toString(), $findings[0]->symbolPath->toString());

        $decisions = [];
        foreach ($ruleClass::channelDeclarations()[$rule->getName()]->levels as $level) {
            $decisions[] = new EnablementDecision(
                new SelectionCellAddress($rule->getName(), new FindingChannel($rule->getName()), $level, ChannelSelectionRole::Selectable),
                new AuthoredCellDecision(CellSwitch::On, CellAdmission::Direct),
            );
        }
        $enablement = new RuleEnablement($decisions, null);
        $universe = self::createStub(ChannelUniverseInterface::class);
        $universe->method('producerOf')->willReturn($rule->getName());
        $configuration = self::createStub(RuleConfigurationInterface::class);
        $configuration->method('channelUniverse')->willReturn($universe);
        $publication = new FindingPublication($configuration);
        $publication->begin();
        $removed = [];
        self::assertSame($findings, $publication->published($rule->getName(), $findings, $enablement, null, $removed));
        self::assertSame([], $removed);
    }

    /** @return iterable<string, array{class-string, string, array<string, int|string>}> */
    public static function producedLevels(): iterable
    {
        $file = ['subjectKind' => 'file', 'line' => 3];
        $method = ['subjectKind' => 'declaration', 'logicalKind' => 'method', 'namespace' => 'App', 'class' => 'Example', 'member' => 'run', 'line' => 4];
        $class = ['subjectKind' => 'declaration', 'logicalKind' => 'class', 'namespace' => 'App', 'class' => 'Example', 'line' => 5];
        yield 'credential file' => [HardcodedCredentialsRule::class, 'security.hardcoded-credentials', $file + ['pattern' => 'variable']];
        yield 'credential callable' => [HardcodedCredentialsRule::class, 'security.hardcoded-credentials', $method + ['pattern' => 'variable']];
        yield 'credential class' => [HardcodedCredentialsRule::class, 'security.hardcoded-credentials', $class + ['pattern' => 'property']];
        yield 'sensitive anonymous file' => [SensitiveParameterRule::class, 'security.sensitive-parameter', $file + ['paramName' => 'password']];
        yield 'sensitive named callable' => [SensitiveParameterRule::class, 'security.sensitive-parameter', $method + ['paramName' => 'password']];
        yield 'SQL file' => [SqlInjectionRule::class, 'security.sql_injection', $file];
        yield 'XSS file' => [XssRule::class, 'security.xss', $file];
        yield 'command file' => [CommandInjectionRule::class, 'security.command_injection', $file];
    }
}
