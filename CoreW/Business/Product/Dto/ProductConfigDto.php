<?php

namespace CoreW\Business\Product\Dto;

class ProductConfigDto
{
    public int $productId;

    public int $userId = 0;

    public string $key;

    public array $values;

    public function __construct(array $data)
    {
        isset($data['productId']) && $this->productId = (int)$data['productId'];
        isset($data['userId']) && $this->userId = (int)$data['userId'];
        isset($data['key']) && $this->key = $data['key'];
        (isset($data['values']) && is_array($data['values'])) && $this->values = $data['values'];
    }
}