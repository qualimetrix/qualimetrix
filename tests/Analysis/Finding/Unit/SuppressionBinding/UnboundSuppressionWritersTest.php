<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\SuppressionBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfiguredValueWriters;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\GlobalValueWriters;
use Qualimetrix\Analysis\Finding\Contract\Population\RuleAbstention;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ExcludeSelectorVerdict;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeDoor;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement;
use Qualimetrix\Analysis\Finding\Contract\ProjectScope\RemovedCodeNamespaces;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RulePublicationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleSuppression;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Finding\SuppressionBinding\ConfiguredValueJudge;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule;
use Qualimetrix\Analysis\Finding\SuppressionBinding\ValueScopeJudgement;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;

/**
 * An unbound suppression value only installed files wrote, and no later layer
 * can take away, is not judged; the same value written by any file of the
 * project is, and says who wrote it.
 */
#[CoversClass(UnboundSuppressionAudit::class)]
#[CoversClass(ConfiguredValueJudge::class)]
#[CoversClass(GlobalValueWriters::class)]
final class UnboundSuppressionWritersTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-unbound-writers-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir . '/src', 0777, true);
        file_put_contents($this->tempDir . '/composer.json', json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }

    #[Test]
    public function itDoesNotJudgeAGlobalValueOnlyInstalledFilesWrote(): void
    {
        $path = $this->path('src/Gone');
        $namespace = $this->namespace('Sample\\Gone');
        $writers = new GlobalValueWriters([$path->definition->display() => self::writtenBy(self::vendor())], [$namespace->definition->display() => self::writtenBy(self::vendor())]);
        $audit = $this->audit();
        $scope = $this->scope();
        $files = self::files();
        $publication = self::publication();

        $result = $audit->auditResult([$path], [$namespace], $files, ['Sample'], $scope, $publication, $writers);

        self::assertSame([], $result['findings']);
        self::assertSame(
            [
                ['channel' => UnboundSuppressionOptions::UNMATCHED_PATH, 'option' => 'suppress_paths', 'pattern' => 'subtree:src/Gone'],
                ['channel' => UnboundSuppressionOptions::UNMATCHED_NAMESPACE, 'option' => 'suppress_namespaces', 'pattern' => 'exact:Sample\\Gone'],
            ],
            $audit->unjudgedValues([$path], [$namespace], $files, ['Sample'], $scope, $writers),
        );
        self::assertSame([], $audit->judgedChannels([$path], [$namespace], $files, ['Sample'], $scope, $writers));
        self::assertSame([self::vendor()->describe()], $audit->installedConfigurationSources([$path], [$namespace], $files, ['Sample'], $scope, $writers));
        self::assertSame(2, $result['population']->unjudgedCount());
        self::assertSame(['installed-writer', 'installed-writer'], array_map(static fn(RuleAbstention $absence): string => $absence->gate, $result['population']->abstentions()));
        self::assertSame([false, false], array_map(static fn(RuleAbstention $absence): bool => $absence->dataGap, $result['population']->abstentions()), 'a boundary of the project, not a gap in the data');
        foreach ($result['population']->abstentions() as $absence) {
            self::assertStringEndsWith('The value lies outside what this project can change.', $absence->reason);
        }
    }

    /**
     * Only a value that would be reported is withheld for its writers: one that
     * binds is judged and bound, as it is when the project writes it.
     */
    #[Test]
    public function itJudgesABoundValueOnlyInstalledFilesWrote(): void
    {
        $path = $this->path('src');
        $namespace = $this->namespace('Sample');
        $writers = new GlobalValueWriters([$path->definition->display() => self::writtenBy(self::vendor())], [$namespace->definition->display() => self::writtenBy(self::vendor())]);
        $audit = $this->audit();
        $scope = $this->scope();
        $files = self::files();

        $result = $audit->auditResult([$path], [$namespace], $files, ['Sample'], $scope, self::publication(), $writers);

        self::assertSame([], $result['findings']);
        self::assertSame([], $audit->unjudgedValues([$path], [$namespace], $files, ['Sample'], $scope, $writers));
        self::assertSame([UnboundSuppressionOptions::UNMATCHED_PATH, UnboundSuppressionOptions::UNMATCHED_NAMESPACE], $audit->judgedChannels([$path], [$namespace], $files, ['Sample'], $scope, $writers));
        self::assertSame([], $audit->installedConfigurationSources([$path], [$namespace], $files, ['Sample'], $scope, $writers));
        self::assertSame(0, $result['population']->unjudgedCount());
    }

    #[Test]
    public function itJudgesTheSameValueWhateverProjectFileOrOptionWroteIt(): void
    {
        $path = $this->path('src/Gone');
        $unknown = $this->audit()->auditResult([$path], [], self::files(), ['Sample'], $this->scope(), self::publication());
        self::assertCount(1, $unknown['findings']);
        self::assertStringNotContainsString('Written by', $unknown['findings'][0]->message, 'unknown writers are judged and carry no sentence');

        $keys = [];
        foreach ([
            'root' => ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'),
            'project file' => ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'config/base.yaml')->importedThrough(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')),
            'command line' => ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--suppress-path'),
        ] as $label => $origin) {
            $result = $this->audit()->auditResult([$path], [], self::files(), ['Sample'], $this->scope(), self::publication(), new GlobalValueWriters([$path->definition->display() => self::writtenBy($origin)]));
            self::assertCount(1, $result['findings'], $label);
            self::assertStringEndsWith(' Written by ' . $origin->describe() . '.', $result['findings'][0]->message, $label);
            self::assertSame(0, $result['population']->unjudgedCount(), $label);
            $keys[$label] = $result['findings'][0]->occurrenceKey;
        }
        self::assertEquals($keys['root'], $keys['project file']);
        self::assertEquals($keys['root'], $keys['command line']);
        self::assertEquals($keys['root'], $unknown['findings'][0]->occurrenceKey, 'writers are not part of the identity');
    }

    #[Test]
    public function itJudgesAGlobalValueAnInstalledFileAndTheProjectBothWrote(): void
    {
        $namespace = $this->namespace('Sample\\Gone');
        $writers = self::writtenBy(self::vendor())->with(self::writtenBy(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')));

        $result = $this->audit()->auditResult([], [$namespace], self::files(), ['Sample'], $this->scope(), self::publication(), new GlobalValueWriters(namespaces: [$namespace->definition->display() => $writers]));

        self::assertCount(1, $result['findings']);
        self::assertStringEndsWith(' Written by ' . self::vendor()->describe() . ' and configuration file "qmx.yaml".', $result['findings'][0]->message);
        self::assertSame([], $this->audit()->installedConfigurationSources([], [$namespace], self::files(), ['Sample'], $this->scope(), new GlobalValueWriters(namespaces: [$namespace->definition->display() => $writers])));
    }

    #[Test]
    public function itAsksTheScopeQuestionBeforeTheWritersQuestion(): void
    {
        $path = $this->path('elsewhere/Gone');
        $writers = new GlobalValueWriters([$path->definition->display() => self::writtenBy(self::vendor())]);
        $audit = $this->audit();
        $scope = $this->scope(analysed: [$this->tempDir . '/src']);
        mkdir($this->tempDir . '/elsewhere');

        $result = $audit->auditResult([$path], [], self::files(), ['Sample'], $scope, self::publication(), $writers);

        self::assertSame([], $result['findings']);
        self::assertSame([], $audit->installedConfigurationSources([$path], [], self::files(), ['Sample'], $scope, $writers), 'a value out of reach names no installed source');
        self::assertSame(['suppressionPathJudged'], array_map(static fn(RuleAbstention $absence): string => $absence->gate, $result['population']->abstentions()));
        self::assertTrue($result['population']->abstentions()[0]->dataGap);
    }

    /**
     * A per-rule list is replaced whole, `[]` included, so the project can take
     * an installed one away; a `suppress_namespace_channels` entry cannot be
     * dropped by a later layer, so it is treated like a global value.
     */
    #[Test]
    public function itKeepsJudgingPerRuleListsAnInstalledFileWroteButNotAChannelEntry(): void
    {
        $snapshot = $this->ledgerSnapshot(self::writtenBy(self::vendor()), 'Sample\\Other');
        $audit = $this->audit($snapshot);

        $result = $audit->auditResult([], [], self::files(), ['Sample'], $this->scope(), self::publication());

        self::assertSame(['The suppress_paths pattern', 'The suppress_namespaces pattern'], array_map(static fn($finding): string => implode(' ', \array_slice(explode(' ', $finding->message), 0, 3)), $result['findings']));
        foreach ($result['findings'] as $finding) {
            self::assertStringEndsWith(' Written by ' . self::vendor()->describe() . '.', $finding->message);
        }
        $entry = ['channel' => UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER, 'option' => 'rules.complexity.ccn.suppress_namespace_channels.coupling.cbo:namespace', 'pattern' => 'exact:Sample\\Other'];
        self::assertSame([$entry], $audit->unjudgedValues([], [], self::files(), ['Sample'], $this->scope()));
        self::assertSame([self::vendor()->describe()], $audit->installedConfigurationSources([], [], self::files(), ['Sample'], $this->scope(), new GlobalValueWriters()));
        self::assertSame(['installed-writer'], array_map(static fn(RuleAbstention $absence): string => $absence->gate, $result['population']->abstentions()));
        self::assertFalse($result['population']->abstentions()[0]->dataGap);
    }

    #[Test]
    public function itJudgesAChannelEntryTheProjectWroteOrThatBinds(): void
    {
        $own = $this->audit($this->ledgerSnapshot(self::writtenBy(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')), 'Sample\\Other'));
        $bound = $this->audit($this->ledgerSnapshot(self::writtenBy(self::vendor()), 'Sample'));

        $ownResult = $own->auditResult([], [], self::files(), ['Sample'], $this->scope(), self::publication());
        $boundResult = $bound->auditResult([], [], self::files(), ['Sample'], $this->scope(), self::publication());

        self::assertCount(3, $ownResult['findings'], 'the project wrote the entry');
        self::assertSame(0, $ownResult['population']->unjudgedCount());
        self::assertCount(2, $boundResult['findings'], 'a bound entry is judged and bound');
        self::assertSame(0, $boundResult['population']->unjudgedCount());
        self::assertSame([], $bound->unjudgedValues([], [], self::files(), ['Sample'], $this->scope()));
        self::assertSame([], $bound->installedConfigurationSources([], [], self::files(), ['Sample'], $this->scope(), new GlobalValueWriters()));
    }

    #[Test]
    public function itDoesNotAdviseNarrowingTheExclusionForAnInstalledValue(): void
    {
        $removed = ExcludeSelectorVerdict::fromMeasuredFacts(
            new PathPattern(SelectorDefinition::fromKindAndValue('exact', 'src/Removed.php')),
            [ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml')],
            ['src/Removed.php'],
            ['src/Removed.php'],
            'php-file',
            null,
            [],
            true,
        );
        $judgement = new ProjectScopeJudgement([ProjectScopeDoor::Exclude], [], [$removed], RemovedCodeNamespaces::known(['Sample\\Removed', 'Sample\\Removed\\Deep']), [self::vendor()->describe()]);
        $own = $this->namespace('Sample\\Removed');
        $installed = $this->namespace('Sample\\Removed\\Deep');
        $writers = new GlobalValueWriters(namespaces: [$installed->definition->display() => self::writtenBy(self::vendor())]);

        $withheld = $this->audit()->withheldForRemovedCode([], [$own, $installed], self::files(), ['Sample'], $this->scope($judgement), $writers);

        self::assertSame(['exact:Sample\\Removed'], array_column($withheld, 'pattern'));
    }

    private static function vendor(): ConfigurationOrigin
    {
        return ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'vendor/acme/std/base.yaml')->importedThrough(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'));
    }

    private static function writtenBy(ConfigurationOrigin $origin): ConfiguredValueWriters
    {
        return new ConfiguredValueWriters([$origin]);
    }

    /** Every per-rule option under `complexity.ccn`, the lists unbound and written by the vendor. */
    private function ledgerSnapshot(ConfiguredValueWriters $channelWriters, string $channelNamespace): ResolvedRuleOptions
    {
        $producer = UnboundSuppressionRule::NAME;
        $vendor = self::writtenBy(self::vendor());

        return new ResolvedRuleOptions(
            [$producer => new UnboundSuppressionOptions(), 'complexity.ccn' => new UnboundSuppressionOptions()],
            [
                $producer => new RuleSuppression(),
                'complexity.ccn' => new RuleSuppression(
                    paths: [$this->path('src/Gone')],
                    namespaces: [$this->namespace('Sample\\Gone')],
                    namespaceChannels: ['coupling.cbo:namespace' => [$this->namespace($channelNamespace)]],
                    pathWriters: $vendor,
                    namespaceWriters: $vendor,
                    namespaceChannelWriters: ['coupling.cbo:namespace' => $channelWriters],
                ),
            ],
        );
    }

    private function audit(?ResolvedRuleOptions $snapshot = null): UnboundSuppressionAudit
    {
        $producer = UnboundSuppressionRule::NAME;
        $execution = self::createStub(RulePublicationInterface::class);
        $execution->method('publishable')->willReturnArgument(0);
        $configuration = self::createStub(RuleConfigurationInterface::class);
        $configuration->method('resolvedOptions')->willReturn($snapshot ?? new ResolvedRuleOptions([$producer => new UnboundSuppressionOptions()], [$producer => new RuleSuppression()]));

        return new UnboundSuppressionAudit($execution, $configuration);
    }

    /** @param ?list<string> $analysed */
    private function scope(?ProjectScopeJudgement $judgement = null, ?array $analysed = null): ValueScopeJudgement
    {
        return new ValueScopeJudgement(
            $this->tempDir,
            (new ComposerManifestReader())->read(AbsolutePath::fromString($this->tempDir))->psr4Roots(),
            $analysed ?? [$this->tempDir . '/src'],
            projectDeclared: true,
            scope: $judgement ?? new ProjectScopeJudgement(removedCode: RemovedCodeNamespaces::none(), installedConfigurationSources: [self::vendor()->describe()]),
        );
    }

    /** @return array{analysed: list<RelativePath>, failedFiles: list<RelativePath>, failedDirectories: list<RelativePath>, generatedExcluded: list<RelativePath>} */
    private static function files(): array
    {
        return ['analysed' => [RelativePath::fromString('src/Service.php')], 'failedFiles' => [], 'failedDirectories' => [], 'generatedExcluded' => []];
    }

    private function path(string $subtree): PathPattern
    {
        return new PathPattern(SelectorDefinition::fromKindAndValue('subtree', $subtree));
    }

    private function namespace(string $exact): NamespacePattern
    {
        return new NamespacePattern(SelectorDefinition::fromKindAndValue('exact', $exact));
    }

    private static function publication(): ChannelPublication
    {
        $decisions = [];
        foreach (UnboundSuppressionRule::channelDeclarations() as $name => $declaration) {
            foreach ($declaration->levels as $level) {
                $decisions[] = new EnablementDecision(
                    new SelectionCellAddress(UnboundSuppressionRule::NAME, new FindingChannel($name), $level, ChannelSelectionRole::Selectable),
                    new AuthoredCellDecision(CellSwitch::On, CellAdmission::Direct),
                );
            }
        }

        return new ChannelPublication(new RuleEnablement($decisions, null));
    }
}
