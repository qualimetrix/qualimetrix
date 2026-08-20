<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use LogicException;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationKey;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Asks the traversal owner's index for the number of a class declaration.
 *
 * Class-level producers answer after the traversal has finished, so the index
 * is held by the producer that was handed it for the current file rather than
 * passed down through the reporting call.
 */
trait DeclarationIndexAwareTrait
{
    private ?FileDeclarationIndex $declarationIndex = null;

    public function useDeclarationIndex(FileDeclarationIndex $index): void
    {
        $this->declarationIndex = $index;
    }

    private function declarationPathOf(SymbolPath $logical, RelativePath $file, int $startFilePos): DeclarationPath
    {
        $index = $this->declarationIndex
            ?? throw new LogicException('Declaration numbering requires the file declaration index of the current traversal');

        return DeclarationPath::of(
            $logical,
            $file,
            $index->ordinalOf(DeclarationKey::forLogical($logical), $startFilePos),
        );
    }
}
