<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/**
 * What a producer's {@see Violation::$metricValue} means for baseline purposes,
 * across every channel it declares.
 *
 * The baseline never reads a rule's options or its severity ladder — only
 * this one declared property of the producer. It is not a per-channel
 * declaration: {@see ChannelDeclaration} carries only the direction that
 * makes a `magnitude` producer's number comparable (present exactly when the
 * producer is `magnitude`), plus levels and configuration-error status.
 * `computed.health` is why the two are not one type — its direction is
 * per-channel runtime data (`inverted` per metric definition), while its
 * shape is uniformly `magnitude` for every dimension.
 *
 * A rule declares its shape via {@see \Qualimetrix\Analysis\Finding\Rule\RuleInterface::shape()};
 * a validator via {@see ConfigurationValidatorInterface::shape()}. Registry
 * assembly refuses a producer whose declared shape disagrees with whether its
 * own channels carry a direction, and refuses two classes sharing one
 * producer name that declare different shapes.
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
