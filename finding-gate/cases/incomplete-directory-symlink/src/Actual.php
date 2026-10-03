<?php

namespace Corpus\IncompleteDirectorySymlink;

final class Actual
{
    public function label(bool $enabled): string
    {
        return $enabled ? 'enabled' : 'disabled';
    }
}
