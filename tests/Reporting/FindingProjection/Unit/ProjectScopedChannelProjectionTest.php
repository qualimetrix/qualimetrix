<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\FindingProjection\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Git\ReportingGitScopeQuery;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A channel a capability declared project-scoped survives **every** stage
 * that narrows the report by file — asserted per stage, and over the declared
 * list rather than over a channel this test picked.
 *
 * Both halves matter. A capability answers "is this finding about a file?"
 * once, in its `PROJECT_SCOPED_CHANNELS`, and every narrowing stage is then
 * supposed to honour that one answer; the defect this guards against was a
 * stage deciding for itself. `architecture.unassigned-class` was exempt from
 * `exclude_paths` and `exclude_namespaces` and dropped by a report narrowed
 * to a git range, which silently turned an enabled gate into a green build.
 * Reading the list from the declarations is what extends the guarantee to the
 * next project-scoped channel without anyone editing this file.
 *
 * The findings are given a real file and namespace, and every channel is
 * declared an ordinary occurrence here — deliberately not a configuration
 * error, which the projection withholds from the stages for its own separate
 * reason ({@see ConfigurationErrorProjectionTest}). Scope is then the only
 * thing that can keep a finding alive.
 */
#[CoversClass(FindingProjector::class)]
final class ProjectScopedChannelProjectionTest extends TestCase
{
    private const string FILE = 'src/Service/UserService.php';

    private const string NAMESPACE = 'App\\Service';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeDirectory($dir);
        }

        $this->tempDirs = [];
    }

    #[Test]
    public function itSurvivesPathExclusionCoveringTheFile(): void
    {
        $this->assertEveryDeclaredChannelSurvives(new FindingProjectionOptions(excludePaths: ['src/**']));
    }

    #[Test]
    public function itSurvivesNamespaceExclusionCoveringTheNamespace(): void
    {
        $this->assertEveryDeclaredChannelSurvives(new FindingProjectionOptions(excludeNamespaces: ['App\\**']));
    }

    #[Test]
    public function itSurvivesAReportNarrowedToAGitScopeContainingNothing(): void
    {
        $this->assertEveryDeclaredChannelSurvives(new FindingProjectionOptions(gitScope: $this->createEmptyGitScope()));
    }

    private function assertEveryDeclaredChannelSurvives(FindingProjectionOptions $options): void
    {
        foreach (self::declaredProjectScopedKeys() as $key) {
            $channel = FindingChannel::fromKey($key);
            $result = $this->createProjector()->project([$this->finding($channel)], [], $options);

            self::assertCount(
                1,
                $result->findings,
                \sprintf('%s is declared project-scoped but the projection dropped it', $key),
            );
        }
    }

    /** @return list<string> */
    private static function declaredProjectScopedKeys(): array
    {
        return [
            ...LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS,
            ...CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS,
        ];
    }

    private function createProjector(): FindingProjector
    {
        $declarations = new StubChannelDeclarationRegistry();
        foreach (self::declaredProjectScopedKeys() as $key) {
            $declarations->declare($key, ChannelDeclaration::occurrence(SymbolLevel::Class_));
        }

        return new FindingProjector(
            new SuppressionFilter(),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new ReportingGitScopeQuery(),
        );
    }

    private function finding(FindingChannel $channel): Finding
    {
        $path = RelativePath::fromString(self::FILE);
        $symbol = SymbolPath::forClass(self::NAMESPACE, 'UserService');

        return new Finding(
            location: new Location($path, 10),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $path, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: $channel->ruleName,
            code: $channel->code,
            message: 'A statement about the project',
            severity: Severity::Error,
        );
    }

    private function createEmptyGitScope(): GitScopeRequest
    {
        $dir = realpath(sys_get_temp_dir()) . '/qmx-project-scoped-git-' . uniqid();
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        $commands = ['git init', 'git config user.email "test@example.com"', 'git config user.name "Test User"'];
        foreach ($commands as $command) {
            $process = Process::fromShellCommandline($command, $dir);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException(\sprintf('Command failed: %s', $process->getErrorOutput()));
            }
        }

        return new GitScopeRequest(
            reference: 'staged',
            projectRoot: AbsolutePath::fromString($dir),
            includeParentNamespaces: true,
        );
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
