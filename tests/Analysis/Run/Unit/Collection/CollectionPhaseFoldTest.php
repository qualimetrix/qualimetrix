<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Run\Collection\CollectionPhaseFold;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessingResult;
use Qualimetrix\Analysis\Run\Contract\Collection\SuccessfulFileProcessing;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CollectionPhaseFold::class)]
final class CollectionPhaseFoldTest extends TestCase
{
    #[Test]
    public function itFoldsClassLikeDeclarationsInFileArrivalOrder(): void
    {
        $first = $this->declaration('App\\First', 'src/First.php');
        $second = $this->declaration('App\\Second', 'src/Second.php');
        $fold = new CollectionPhaseFold();
        $fold->absorb(FileProcessingResult::success(
            RelativePath::fromString('src/First.php'),
            new SuccessfulFileProcessing(new MetricBag(), classLikeDeclarations: [$first]),
        ));
        $fold->absorb(FileProcessingResult::success(
            RelativePath::fromString('src/Second.php'),
            new SuccessfulFileProcessing(new MetricBag(), classLikeDeclarations: [$second]),
        ));

        self::assertSame([$first, $second], $fold->output()->classLikeDeclarations);
    }

    private function declaration(string $fqn, string $file): ClassLikeDeclaration
    {
        return ClassLikeDeclaration::of(
            DeclarationPath::of(
                SymbolPath::fromClassFqn($fqn),
                RelativePath::fromString($file),
                DeclarationOrdinal::fromRank(0),
            ),
            ClassType::Class_,
            false,
            false,
        );
    }
}
