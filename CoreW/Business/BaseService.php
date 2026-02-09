<?php


namespace CoreW\Business;

use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\User\CurrentUser;
use CoreW\Exception\AbstractBizException;
use CoreW\Exception\ServiceException;
use CoreW\Bfw;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;

class BaseService
{

    protected $bfw;

    public function __construct(Bfw $bfw)
    {
        $this->bfw = $bfw;
    }

    protected function createDao($alias)
    {
        return $this->bfw->dao($alias);
    }

    protected function createService($alias)
    {
        return $this->bfw->service($alias);
    }

    /**
     * @return EventDispatcherInterface
     */
    private function getDispatcher(): EventDispatcherInterface
    {
        return $this->bfw['dispatcher'];
    }

    /**
     * @param string      $eventName
     * @param Event|mixed $subject
     *
     * @return object
     */
    protected function dispatchEvent(string $eventName, $subject, $arguments = []): object
    {
        if ($subject instanceof Event) {
            $event = $subject;
        } else {
            $event = new Event($subject, $arguments);
        }

        return $this->getDispatcher()->dispatch($event, $eventName);
    }

    protected function beginTransaction()
    {
        $this->bfw['db']->beginTransaction();
    }

    protected function commit()
    {
        $this->bfw['db']->commit();
    }

    protected function rollback()
    {
        $this->bfw['db']->rollback();
    }

    protected function createNewException($e)
    {
        if ($e instanceof AbstractBizException) {
            throw $e;
        }

        throw new \Exception();
    }

    /**
     * @return SystemLogService
     */
    protected function getLogService()
    {
        return $this->createService('SystemLog:SystemLogService');
    }

    /**
     * @return CurrentUser|null
     */
    protected function getCurrentUser(): ?CurrentUser
    {
        return $this->bfw['user'];
    }
}
