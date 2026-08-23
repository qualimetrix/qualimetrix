<?php

namespace Corpus\Annotations;

class Directives
{
    /**
     * @qmx-ignore no.such.rule — names a rule that does not exist
     */
    public function unresolved(): void
    {
    }

    /**
     * @qmx-threshold annotation.directive warning=1 error=2 — retunes a rule that declares no threshold
     */
    public function unsupported(): void
    {
    }

    /**
     * @qmx-threshold complexity.cyclomatic warning=notanumber — unparseable threshold value
     */
    public function invalid(): void
    {
    }

    /**
     * @qmx-ignore code-smell.eval — suppresses a finding that is not here
     */
    public function unused(): void
    {
    }
}
