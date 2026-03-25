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
}
