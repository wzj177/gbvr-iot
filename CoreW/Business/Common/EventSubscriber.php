<?php


namespace CoreW\Business\Common;


use CoreW\Bfw;

class EventSubscriber
{
    /**
     * @var Bfw
     */
    private $biz;

    public function __construct(Bfw $biz)
    {
        $this->biz = $biz;
    }

    /**
     * @return Bfw
     */
    public function getBiz()
    {
        return $this->biz;
    }
}