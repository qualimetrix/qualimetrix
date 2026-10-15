<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget\Publication;

enum NewName
{
    case Exclusive;
    case LastWriterWins;
}
