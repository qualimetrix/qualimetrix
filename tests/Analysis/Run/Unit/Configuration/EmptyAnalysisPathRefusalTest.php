<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Discovery\ComposerReader;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * The empty path used to be answered by the path factory in the vocabulary of
 * the CLI, which misnames the door whenever the value came from `paths:` in a
 * document — and without the product's refusal framing either way.
 */
final class EmptyAnalysisPathRefusalTest extends TestCase
{
    #[Test]
    public function itRefusesAnEmptyPathWithTheProductFraming(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve(['']);
    }

    #[Test]
    public function itRefusesAnEmptyPathAmongLawfulOnes(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve(['src', '']);
    }

    #[Test]
    public function itStillAcceptsLawfulPaths(): void
    {
        self::assertNotSame([], self::resolve(['src'])->paths);
    }

    #[Test]
    public function itStillDefaultsToTheWorkingDirectory(): void
    {
        $document = new ConfigurationDocument([], AbsolutePath::fromString('/project'));
        $resolved = (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerReader())))->resolve($document);

        self::assertSame(['/project'], array_map(static fn($path): string => $path->value(), $resolved->paths));
    }

    /** @param list<string> $paths */
    private static function resolve(array $paths): \Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration
    {
        $document = new ConfigurationDocument(
            [['source' => 'cli', 'values' => ['paths' => $paths]]],
            AbsolutePath::fromString('/project'),
        );

        return (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerReader())))->resolve($document);
    }
}
