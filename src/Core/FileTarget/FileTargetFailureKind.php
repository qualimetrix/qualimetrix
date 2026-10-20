<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

enum FileTargetFailureKind
{
    case UnsupportedScheme;
    case Directory;
    case DirectoryMissing;
    case ForeignDescriptor;
    case ExposedLink;
    case LinkLoop;
    case OwnerUnknown;
    case Unopenable;
    case NoHardLinks;
    case ExposedStream;
    case IdentityChanged;
    case Appeared;
    case PartialWrite;
}
