<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Ast;

use AiMessDetector\Core\Exception\ParseException;
use PhpParser\Node;
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
