<?php

namespace CoreW\RateLimiter;

use CoreW\RateLimiter\Storage\Storage;

/**
 * 基于令牌桶算法限流
 *
 * Class RateLimiter
 * @package CoreW\RateLimiter
 */
class RateLimiter
{
    /**
     * 限流器的名称
     * @var
     */
    public $name;

    /**
     * 限流器允许的最大请求数
     * @var int
     */
    public $maxAllowance;

    /**
     * 时间段，以秒为单位，在该时间段内允许执行最大请求数
     * @var int
     */
    public $period;

    /**
     * 存储对象，用于存储限流器的状态信息，例如令牌桶的状态
     * @var Storage
     */
    private $storage;

    /**
     * RateLimiter constructor.
     * @param $name
     * @param $maxAllowance int  最大请求次数:令牌产生速率
     * @param $period int 时间点（单位：s)
     * @param Storage $storage
     */
    public function __construct($name, $maxAllowance, $period, Storage $storage)
    {
        $this->name = $name;
        $this->maxAllowance = $maxAllowance;
        $this->period = $period;
        $this->storage = $storage;
    }

    /**
     * 限速校验,以确保在给定时间段内不超过最大请求数
     * 确保了在每秒内不超过 $maxAllowance 个请求被接受，并且剩余令牌会累积下来，以适应时间段内的不均匀请求
     * @param $id 请求唯一标识：比如当前账号、当前客户端ip
     * @param float $use 请求占用的令牌数
     * @return int
     */
    public function check($id, $use = 1.0)
    {
        $key = $this->getKey($id);
        $value = $this->storage->get($key); // 获取存储中的令牌桶状态
        if ($value === false) {
            $this->storage->set($key, $this->packValue($this->maxAllowance - $use, time()), $this->period);

            return $this->maxAllowance;
        }

        $rate = $this->maxAllowance / $this->period; //每秒内允许生成的令牌数量： 每s请求占用的令牌数：2 / 3600 = 0.0005
        list($allowance, $lastCheckTime) = $this->unpackValue($value);

        $timePassed = time() - $lastCheckTime; // 计算时间过去多久：当前时间 与上一次生成token的时间的差值
        // 通过速率计算已经生成的令牌数量，这个数量是累积的，它表示在 $timePassed 秒内生成的令牌数量
        $allowance += $timePassed * $rate; // 表示这段时间差内累计新生成的token数：累计的+新生成的 【精确到每秒剩余的次数，向下取整】

        // 限制令牌数量不超过令牌桶容量，令牌数量不超过桶容量
        $allowance = min($allowance, $this->maxAllowance);
        // 如果令牌数量不足 可用次数（默认为1）
        if ($allowance < $use) {
            // 如果剩余的令牌数量小于请求所需的令牌数 $use，则请求被拒绝，因为令牌不足
            $this->storage->set($key, $this->packValue($allowance, time()), $this->period);

            return 0;
        }

        // 否则，扣除请求所需的令牌数量，更新令牌桶状态，返回剩余令牌数，通常向下取整
        $this->storage->set($key, $this->packValue($allowance - $use, time()), $this->period);

        return (int)floor($allowance);
    }

    /**
     * 获取给定标识的令牌桶中剩余的令牌数。它实际上是调用 check 方法，但不占用任何令牌，从而返回当前剩余令牌数。
     * @param $id
     * @return false|float|int
     */
    public function getAllowance($id)
    {
        $this->check($id, 0);
        $value = $this->storage->get($this->getKey($id));
        if ($value !== false) {
            list($allowance) = $this->unpackValue($value);

            return floor($allowance);
        }

        return $this->maxAllowance;
    }

    /**
     * 更新令牌桶状态，以支持特定的阈值
     * @param $id 请求标识
     * @param $threshold 阈值：如果为正数，它会将令牌桶中的剩余令牌数量增加该值；如果为负数，它会减少令牌桶中的剩余令牌数量，但不会减到负数。这个方法用于手动调整令牌桶中的令牌数量
     * @return int|string
     */
    public function updateAllowance($id, $threshold)
    {
        $key = $this->getKey($id);
        $value = $this->storage->get($key);
        if ($value !== false) {
            list($allowance, $lastCheckTime) = $this->unpackValue($value);
            $updatedAllowance = ($allowance + $threshold) > 0 ? ($allowance + $threshold) : 0;
        } else {
            $updatedAllowance = $threshold > 0 ? $threshold : 0;
        }

        $this->storage->set($this->getKey($id), $this->packValue($updatedAllowance, time()), $this->period);

        return $updatedAllowance;
    }

    public function getMaxAllowance()
    {
        return $this->maxAllowance;
    }

    /**
     * 获取剩余次数
     *
     * @param $id
     * @return false|float|int
     */
    public function getAllow($id)
    {
        return $this->getAllowance($id);
    }

    /**
     * 清除指定标识的令牌桶状态，从存储中删除与该标识相关的数据，通常用于清除某个标识的限流状态，以重新开始计数
     *
     * @param $id
     */
    public function purge($id)
    {
        $this->storage->del($this->getKey($id));
    }

    /**
     * 将令牌桶的状态打包成一个字符串
     *
     * @param $allowance 令牌的数量
     * @param $lastCheckTime 上次检查的时间
     * @return string
     */
    protected function packValue($allowance, $lastCheckTime)
    {
        return $allowance . ',' . $lastCheckTime;
    }

    /**
     * 解析存储的令牌桶状态字符串，将其分解为令牌数量和上次检查的时间
     * 例如，如果传递字符串 "10,1634850000" 给 unpackValue 方法，它将返回数组 ['10', '1634850000']。
     * @param $value
     * @return false|string[]
     */
    protected function unpackValue($value)
    {
        return explode(',', $value, 2);
    }

    protected function getKey($id)
    {
        return 'rate-limit:' . $this->name . ':' . $id;
    }
}
