<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

enum EntryKind
{
    case RegularFile;
    case Directory;
    case FileLink;
    case DirectoryLink;
    case DanglingLink;
    case Special;
    case StatFailed;
}
