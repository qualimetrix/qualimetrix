<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;

/**
 * One source's contribution to the document, as written.
 *
 * `positioned` is false for a source whose values have no key path an author
 * could look up — the command line: a refusal then names the option through
 * the node's locator and carries no position.
 */
final readonly class AuthoredLayer
{
    public function __construct(
        public ConfigurationOrigin $origin,
        public AuthoredNode $root,
        public bool $positioned = true,
    ) {}
}
