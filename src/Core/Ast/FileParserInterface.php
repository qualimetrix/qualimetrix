<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Ast;

use PhpParser\Node;
use Qualimetrix\Core\Exception\ParseException;
use SplFileInfo;

interface FileParserInterface
{
    /**
     * Parses PHP file into AST.
     *
     *
     * @throws ParseException
     *
     * @return Node[]
     */
    public function parse(SplFileInfo $file): array;

    /**
     * Parse source bytes that were already read from the original file.
     *
     * Implementations must use $file for diagnostics and source identity.
     *
     * @throws ParseException
     *
     * @return Node[]
     */
    public function parseContent(SplFileInfo $file, string $content): array;
}
