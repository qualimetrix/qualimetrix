<?php

namespace Corpus\Health\Engine\Stage;

class Normalizer
{
    private $last;
    private $mode;

    public function normalize($value, $mode)
    {
        $this->mode = $mode;
        if ($value === null) {
            $this->last = '';
        } elseif (is_array($value)) {
            $this->last = implode(',', $value);
        } elseif ($mode === 'trim') {
            $this->last = trim((string) $value);
        } else {
            $this->last = (string) $value;
        }

        return $this->last;
    }

    public function mode()
    {
        return $this->mode;
    }
}
