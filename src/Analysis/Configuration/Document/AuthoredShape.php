<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

/** The shape a format loader read a node as. */
enum AuthoredShape
{
    case Scalar;
    case Mapping;
    case Sequence;

    /** An empty collection whose format does not say whether it was `{}` or `[]`. */
    case EmptyCollection;
}
