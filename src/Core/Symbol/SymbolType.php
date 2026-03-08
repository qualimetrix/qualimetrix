<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Symbol;

enum SymbolType: string
{
    case Method = 'method';
    case Function_ = 'function';
    case Class_ = 'class';
    case File = 'file';
    case Namespace_ = 'namespace';
    case Project = 'project';
}
