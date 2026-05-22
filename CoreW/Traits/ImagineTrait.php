<?php

namespace CoreW\Traits;

trait ImagineTrait
{
    /**
     * @return \Imagine\Gd\Imagine|\Imagine\Gmagick\Imagine|\Imagine\Imagick\Imagine
     */
    protected function getImagine()
    {
        if (extension_loaded('imagick')) {
            return new \Imagine\Imagick\Imagine();
        }

        if (extension_loaded('gmagick')) {
            return new \Imagine\Gmagick\Imagine();
        }

        return new \Imagine\Gd\Imagine();
    }
}