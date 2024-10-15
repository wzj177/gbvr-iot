<?php


namespace CoreW\Business\Ip2Region;


class Ip2Region
{
    /**
     * 查询实例对象
     * @var XdbSearcher
     */
    private $searcher;

    /**
     * 初始化构造方法
     * @throws Exception
     */
    public function __construct()
    {
        $this->searcher = XdbSearcher::newWithFileOnly(__DIR__ . '/ip2region.xdb');
    }

    /**
     * 兼容原 memorySearch 查询
     * @param string $ip
     * @return array
     * @throws Exception
     */
    public function memorySearch($ip)
    {
        return ['city_id' => 0, 'region' => $this->searcher->search($ip)];
    }

    /**
     * 兼容原 binarySearch 查询
     * @param string $ip
     * @return array
     * @throws Exception
     */
    public function binarySearch($ip)
    {
        return $this->memorySearch($ip);
    }

    /**
     * 兼容原 btreeSearch 查询
     * @param string $ip
     * @return array
     * @throws Exception
     */
    public function btreeSearch($ip)
    {
        return $this->memorySearch($ip);
    }

    /**
     * ip 解析为行政区域
     *
     * @param $ip
     * @return mixed|string
     */
    public function parseIpToArea($ip)
    {
        $info = $this->btreeSearch($ip);
        $city = explode('|', $info['region']);

        if (empty($city[2])) {
            $region = $city[3];
        } else {
            $region = $city[2] . $city[3] . $city[4];
        }

        return $region;
    }

    /**
     * destruct method
     * resource destroy
     */
    public function __destruct()
    {
        $this->searcher->close();
        unset($this->searcher);
    }
}