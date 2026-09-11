<?php

declare(strict_types=1);

namespace Probe\Coupled;

use Probe\Tree\Child1;
use Probe\Tree\Child2;
use Probe\Tree\Child3;
use Probe\Tree\Child4;
use Probe\Tree\Child5;
use Probe\Tree\Child6;
use Probe\Tree\Child7;
use Probe\Tree\Child8;
use Probe\Tree\Child9;
use Probe\Tree\Child10;
use Probe\Tree\Child11;
use Probe\Tree\Child12;
use Probe\Tree\Level1;
use Probe\Tree\Level2;
use Probe\Tree\Level3;
use Probe\Tree\Level4;
use Probe\Tree\Level5;
use Probe\Complex\Knotty;
use Probe\Complex\Plain;

class Hub
{
    /** @var list<object> */
    private array $seen = [];

    public function useLevel1(Level1 $c): void
    {
        $this->seen[] = $c;
    }

    public function useLevel2(Level2 $c): void
    {
        $this->seen[] = $c;
    }

    public function useLevel3(Level3 $c): void
    {
        $this->seen[] = $c;
    }

    public function useLevel4(Level4 $c): void
    {
        $this->seen[] = $c;
    }

    public function useLevel5(Level5 $c): void
    {
        $this->seen[] = $c;
    }

    public function useKnotty(Knotty $c): void
    {
        $this->seen[] = $c;
    }

    public function usePlain(Plain $c): void
    {
        $this->seen[] = $c;
    }

    public function take1(Child1 $c): void
    {
        $this->seen[] = $c;
    }

    public function take2(Child2 $c): void
    {
        $this->seen[] = $c;
    }

    public function take3(Child3 $c): void
    {
        $this->seen[] = $c;
    }

    public function take4(Child4 $c): void
    {
        $this->seen[] = $c;
    }

    public function take5(Child5 $c): void
    {
        $this->seen[] = $c;
    }

    public function take6(Child6 $c): void
    {
        $this->seen[] = $c;
    }

    public function take7(Child7 $c): void
    {
        $this->seen[] = $c;
    }

    public function take8(Child8 $c): void
    {
        $this->seen[] = $c;
    }

    public function take9(Child9 $c): void
    {
        $this->seen[] = $c;
    }

    public function take10(Child10 $c): void
    {
        $this->seen[] = $c;
    }

    public function take11(Child11 $c): void
    {
        $this->seen[] = $c;
    }

    public function take12(Child12 $c): void
    {
        $this->seen[] = $c;
    }
}
