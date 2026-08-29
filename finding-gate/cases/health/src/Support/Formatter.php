<?php

namespace Corpus\Health\Support;

class Formatter
{
    private string $prefix = '[';
    private string $suffix = ']';
    private $decorator;

    public function render($value)
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if ($item === null) {
                    continue;
                }
                $parts[] = is_scalar($item) ? (string) $item : gettype($item);
            }
            $value = implode(',', $parts);
        }
        if ($this->decorator !== null) {
            return ($this->decorator)($value);
        }

        return $this->prefix . (string) $value . $this->suffix;
    }

    public function decorate($decorator): void
    {
        $this->decorator = $decorator;
    }
}
