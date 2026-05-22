<?php


namespace CoreW\Gii\Template;


use CoreW\Bfw;

abstract class BaseProcessor
{
    protected $defaultSubPaths
        = [
            'Dao'       => ['Impl'],
            'Exception' => [],
            'Service'   => ['Impl'],
        ];

    protected $namespacePrefix;
    protected $biz;

    public function __construct($namespacePrefix, Bfw $biz)
    {
        $this->namespacePrefix = $namespacePrefix;
        $this->biz = $biz;
    }

    abstract public function render(array $args = []);

    abstract public function getTemplates();
}
