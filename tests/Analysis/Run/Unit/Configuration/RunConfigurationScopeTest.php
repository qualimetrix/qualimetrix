<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use ArgumentCountError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement;
use Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use ReflectionClass;

/**
 * The narrowing transfer, which exists because the positional rebuild it
 * replaced dropped every field added after the call site was written.
 */
#[CoversClass(RunConfiguration::class)]
final class RunConfigurationScopeTest extends TestCase
{
    #[Test]
    public function itCarriesEveryFieldExceptTheScopeItIsGiven(): void
    {
        $root = AbsolutePath::fromString('/project');
        $narrowed = self::narrowedTo(
            [$root->joinRelative(RelativePath::fromString('src/Domain'))],
        );

        self::assertSame(
            ['subtree:vendor', 'subtree:Legacy'],
            array_map(static fn(PathPattern $pattern): string => $pattern->definition->display(), $narrowed->pathExcludes),
        );
        self::assertSame(
            ['subtree:Legacy'],
            array_map(static fn(PathPattern $pattern): string => $pattern->definition->display(), $narrowed->authoredPathExcludes),
        );
        self::assertSame($root->value(), $narrowed->projectRoot->value());
        self::assertSame(GeneratedFilePolicy::Include, $narrowed->generatedFilePolicy);
        self::assertSame(AutoloadDevPolicy::Include, $narrowed->autoloadDevPolicy);
    }

    #[Test]
    public function itTakesTheCoverageAnswerFromTheCallerRatherThanInheritingIt(): void
    {
        self::assertTrue(self::configuration()->coversProjectScope);
        self::assertFalse(
            self::narrowedTo([AbsolutePath::fromString('/project/src/Domain')])->coversProjectScope,
        );
        self::assertTrue(
            self::narrowedTo([AbsolutePath::fromString('/project/src')])->coversProjectScope,
        );
    }

    #[Test]
    public function itRequiresAnExplicitAutoloadDevPolicy(): void
    {
        self::expectException(ArgumentCountError::class);

        (new ReflectionClass(RunConfiguration::class))->newInstanceArgs([
            [],
            AbsolutePath::fromString('/project'),
            GeneratedFilePolicy::Include,
            self::projectScope(),
            [],
        ]);
    }

    /** @param list<AbsolutePath> $paths */
    private static function narrowedTo(array $paths): RunConfiguration
    {
        $configuration = self::configuration();

        return $configuration->withProjectScope(ProjectScopeCoverage::narrow($configuration->projectScope, $paths));
    }

    private static function configuration(): RunConfiguration
    {
        return new RunConfiguration(
            pathExcludes: [self::pathPattern('vendor'), self::pathPattern('Legacy')],
            projectRoot: AbsolutePath::fromString('/project'),
            generatedFilePolicy: GeneratedFilePolicy::Include,
            projectScope: self::projectScope(),
            authoredPathExcludes: [self::pathPattern('Legacy')],
            autoloadDevPolicy: AutoloadDevPolicy::Include,
        );
    }

    private static function projectScope(): ProjectScopeMeasurement
    {
        return new ProjectScopeMeasurement(
            projectRoot: AbsolutePath::fromString('/project'),
            paths: [AbsolutePath::fromString('/project/src')],
            pathsAuthored: true,
            scopeState: ProjectScopeState::Covered,
            denominator: [[
                'target' => 'src/',
                'path' => AbsolutePath::fromString('/project/src'),
            ]],
            uncoveredRoots: [],
            prunedTargets: [],
            reasons: [],
            namespaceMapUsable: true,
            pathResolutions: [],
        );
    }

    private static function pathPattern(string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $value));
    }
}
