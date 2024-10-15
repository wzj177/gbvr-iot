<?php

namespace CoreW\Gii\Template;

interface TemplateInterface
{
    /**
     * @param array $args
     * @return string
     */
    public function getContext(array $args = []);
}
