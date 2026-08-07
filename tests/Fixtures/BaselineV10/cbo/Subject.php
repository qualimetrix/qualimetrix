<?php

declare(strict_types=1);

namespace BaselineFixture\Coupling;

final class Subject
{
    public function make(): Dependency
    {
        return new Dependency();
    }
}

final class Dependency {}
