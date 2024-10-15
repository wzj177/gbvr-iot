<?php

namespace CoreW\Business\Lock;

class FileLock implements LockInterface
{
    /**
     * @param $key
     * @param $fn
     * @param int|null $ex
     * @return mixed
     * @throws \Exception
     */
    public function exec($key, $fn, ?int $ex = null)
    {
        $lockFile = $this->getLockFilePath($key);
        if ($fp = $this->lock($lockFile)) {
            try {
                return $fn();
            } finally {
                $this->releaseLock($fp, $lockFile);
            }
        } else {
            throw new \Exception('Could not acquire file lock.');
        }
    }

    private function releaseLock($fp, $lockFile)
    {
        flock($fp, LOCK_UN);
        fclose($fp);
        unlink($lockFile);
    }

    private function lock($lockFile)
    {
        if ($fp = $this->acquireLock($lockFile)) {
            return $fp;
        }
        usleep(200);
        return $this->acquireLock($lockFile);
    }

    private function acquireLock($lockFile)
    {
        $fp = fopen($lockFile, 'w');
        // flock($fp, LOCK_EX | LOCK_NB) 非阻塞方式获取排他锁:如果锁已经被其他进程持有，它将不会阻塞等待锁的释放，而是立即返回 false 表示获取锁失败。这可以用于实现一种尝试获取锁但不等待的策略。
        //如果你使用 flock($fp, LOCK_EX)，它会以阻塞方式获取排他锁。如果锁已经被其他进程持有，它会等待锁的释放，然后再获取锁。这可以用于实现等待锁可用的策略。
        if (false === $fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            // 无法获取锁
            return false;
        }

        return $fp;
    }

    private function getLockFilePath($key)
    {
        $lockPath = runtime_path('temp/lock');
        !is_dir($lockPath) && mkdir($lockPath, 0777, true);
        return $lockPath . '/' . $key . '.lock';
    }
}