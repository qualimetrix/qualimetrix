<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

enum ManifestReadState: string
{
    case Absent = 'absent';
    case Unreadable = 'unreadable';
    case Invalid = 'invalid';
    case Read = 'read';
}
