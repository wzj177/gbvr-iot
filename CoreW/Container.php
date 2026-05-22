<?php


namespace CoreW;


use Webman\Exception\NotFoundException;

class Container extends \Webman\Container
{
    public function set($name, $args = []) : object
    {
        if (!class_exists($name)) {
            throw new NotFoundException("Class '$name' not found");
        }

        $reflection = new \ReflectionClass($name);

        $this->instances[$name] = $reflection->newInstance($args);

        return $this->instances[$name];
    }
}