<?php

declare(strict_types=1);

namespace BaselineFixture\Dogfood;

final class Legacy
{
    public function inspect(): void
    {
        echo @$this->first();
        // @qmx-ignore-next-line code-smell.error-suppression reviewed legacy call
        echo @$this->second();
    }
}
