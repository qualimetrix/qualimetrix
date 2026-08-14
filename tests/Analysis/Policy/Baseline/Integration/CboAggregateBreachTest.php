<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

use PHPUnit\Framework\Attributes\Test;

use PHPUnit\Framework\TestCase;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\BaselineCliFixture;
use Symfony\Component\Console\Command\Command;

/**
 * ADR 0017 at the analysis-to-baseline seam.
 *
 * CBO includes afferent couplings. Editing Other.php to depend on Subject
 * therefore changes the value attached to the existing Subject entry although
 * Subject.php stays byte-for-byte unchanged.
 */
final class CboAggregateBreachTest extends TestCase
{
    #[Test]
    public function itBreachesAClassCboEntryAfterAnotherFileChanges(): void
    {
        $project = BaselineCliFixture::from('cbo');

        try {
            $paths = [$project->root];
            $bare = $project->check($paths);
            self::assertStringContainsString('coupling.cbo.class', $bare->getDisplay());

            $generated = $project->generate($paths);
            self::assertSame(Command::SUCCESS, $generated->getStatusCode(), $generated->getDisplay());
            self::assertStringContainsString('coupling.cbo#coupling.cbo.class', (string) file_get_contents($project->baselinePath));

            file_put_contents(
                $project->root . '/Other.php',
                <<<'PHP'
                <?php

                namespace BaselineFixture\Coupling;

                use BaselineFixture\Coupling\Subject;

                final class Other
                {
                    public function make(): Subject
                    {
                        return new Subject();
                    }
                }
                PHP,
            );

            $checked = $project->check($paths, ['--baseline' => $project->baselinePath]);

            self::assertSame(2, $checked->getStatusCode(), $checked->getDisplay());
            self::assertStringContainsString('1 error', $checked->getDisplay());
            self::assertStringContainsString('coupling.cbo.class', $checked->getDisplay());
        } finally {
            $project->remove();
        }
    }
}
