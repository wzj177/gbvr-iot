<?php

namespace CoreW\Business\Product\Dto;

class PlaneGraphMarkersDto
{
    public int $productId;

    public int $userId = 0;

    public string $currentIp = "";

    public string $imgUrl = "";
    public ?array $gisParam = null;

    public array $markers;

    public array $center
        = [
            "position" => ["x" => 0, "y" => 0],
            "scale"    => ["scaleW" => 1, "scaleY" => 1],
        ];

    public string $rotation = "0deg";

    public string $type = "default";

    public function __construct(array $data)
    {
        isset($data['productId']) && $this->productId = (int)$data['productId'];
        isset($data['userId']) && $this->userId = (int)$data['userId'];
        isset($data['type']) && $this->type = $data['type'];
        isset($data['imgUrl']) && $this->imgUrl = $data['imgUrl'];
        !empty($data['gisParam']) && $this->gisParam = $data['gisParam'];
        isset($data['currentIp']) && $this->currentIp = $data['currentIp'];
        (isset($data['markers']) && is_array($data['markers'])) && $this->markers = $data['markers'];
        (isset($data['center']) && is_array($data['center'])) && $this->center = $data['center'];
        isset($data['rotation']) && $this->rotation = $data['rotation'];
    }
}