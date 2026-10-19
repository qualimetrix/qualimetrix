<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Publication;

enum PublicationKind
{
    case JsonDocument;
    case XmlDocument;
    case HtmlDocument;
    case Prose;
}
