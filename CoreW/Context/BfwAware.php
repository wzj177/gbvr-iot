<?php


namespace CoreW\Context;


use CoreW\Bfw;

trait BfwAware
{
    /**
     * @var Bfw
     */
    protected $bfw;

    /**
     * @param Bfw $bfw
     */
    public function setBfw(Bfw $bfw)
    {
        $this->bfw = $bfw;
    }
}