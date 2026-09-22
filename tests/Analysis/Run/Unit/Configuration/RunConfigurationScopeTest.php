<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;

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
        $narrowed = self::configuration()->narrowedTo(
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
    }

    /**
     * The verdict is the choice of method, not a flag on one. A transfer that
     * took only the paths would leave the wider run's `true` in place, which
     * is the silent inheritance the field has no default to prevent.
     */
    #[Test]
    public function itTakesTheCoverageAnswerFromTheCallerRatherThanInheritingIt(): void
    {
        self::assertTrue(self::configuration()->coversProjectScope);
        self::assertFalse(
            self::configuration()->narrowedTo([AbsolutePath::fromString('/project/src/Domain')])->coversProjectScope,
        );
        self::assertTrue(
            self::configuration()->narrowedTo([AbsolutePath::fromString('/project/src')])
                ->coveringProjectScope([AbsolutePath::fromString('/project/src')])->coversProjectScope,
        );
    }

    private static function configuration(): RunConfiguration
    {
        return new RunConfiguration(
            paths: [AbsolutePath::fromString('/project/src')],
            pathExcludes: [self::pathPattern('vendor'), self::pathPattern('Legacy')],
            projectRoot: AbsolutePath::fromString('/project'),
            generatedFilePolicy: GeneratedFilePolicy::Include,
            coversProjectScope: true,
            authoredPathExcludes: [self::pathPattern('Legacy')],
        );
    }

    private static function pathPattern(string $value): PathPattern
    {
        return new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $value));
    }
}
