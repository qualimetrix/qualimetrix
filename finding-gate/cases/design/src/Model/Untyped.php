<?php

namespace Corpus\Design\Model;

class Untyped
{
    public $name;
    protected $code;
    public $extra;

    public function describe($prefix, $suffix)
    {
        return $prefix . $this->name . $this->code . $this->extra . $suffix;
    }

    public function rename($value)
    {
        $this->name = $value;
    }
}
