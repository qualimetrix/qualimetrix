<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

/**
 * What a channel's {@see Violation::$metricValue} means for baseline purposes.
 *
 * The baseline never reads a rule's options or its severity ladder — only
 * the shape a channel declares for the number (if any) its findings report
 * about themselves. See {@see ChannelDeclaration}, which pairs a shape with
 * the direction that makes a `magnitude` shape comparable.
 */
enum ChannelShape: string
{
    /**
     * The reported value is a real measured magnitude — a boundary a
     * ceiling can compare a later run's value against.
     */
    case Magnitude = 'magnitude';

    /**
     * The reported value, if any, is not a magnitude on this channel's own
     * terms (a fixed marker such as `1.0`, or absent entirely). Only the
     * number of findings sharing one identity is meaningful.
     */
    case Occurrence = 'occurrence';
}
