<?php

namespace Corpus\Health\Support;

class Registry
{
    private array $entries = [];
    private $missing;
    private int $hits = 0;

    public function lookup($key)
    {
        if (!is_string($key)) {
            return null;
        }
        ++$this->hits;
        if (isset($this->entries[$key])) {
            return $this->entries[$key];
        }
        if ($this->missing !== null) {
            return ($this->missing)($key);
        }

        return null;
    }

    public function put($key, $value): void
    {
        $this->entries[$key] = $value;
    }

    public function hits(): int
    {
        return $this->hits;
    }
}
