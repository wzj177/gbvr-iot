<?php


namespace CoreW\LiveProvider;


use CoreW\Bfw;
use CoreW\Exception\NotFoundException;
use CoreW\LiveProvider\Strategy\LiveProviderStrategy;
class LiveProviderFactory
{
    protected $CoreW;

    public function __construct(Bfw $CoreW)
    {
        $this->CoreW = $CoreW;
    }

    public function __destruct()
    {
        $this->CoreW = null;
    }

    public function createLiveProvider($type, $currentThirdParty = null)
    {
        $liveProviderType = $this->getLiveProviderType($type);

        if (empty($this->CoreW->offsetGet($liveProviderType))) {
            throw new NotFoundException("Live Provider strategy {$liveProviderType} does not exist");
        }
        /** @var LiveProviderStrategy $strategy */
        $strategy = $this->CoreW->offsetGet($liveProviderType)->setCurrentThirdParty($currentThirdParty);

        return $strategy;
    }

    protected function getLiveProviderType($type): string
    {
        return 'live_provider.' . $type;
    }
}