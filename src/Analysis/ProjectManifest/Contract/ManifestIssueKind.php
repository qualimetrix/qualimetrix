<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

enum ManifestIssueKind: string
{
    case Absent = 'absent';
    case Unreadable = 'unreadable';
    case InvalidJson = 'invalid-json';
    case InvalidRoot = 'invalid-root';
    case InvalidField = 'invalid-field';
    case DroppedRecord = 'dropped-record';
}
