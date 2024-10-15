<?php


namespace CoreW\Business\Attachment\Implementors;


use CoreW\Business\Attachment\Exception\AttachmentException;

class FileImplementorFactory
{
    private static $implements = [];

    /**
     * @param $type
     * @param array $options
     * @return FileImplementor
     */
    public static function make($type, array $options = [])
    {
        $class = sprintf("%s\\Impl\\%sFileImplementor", __NAMESPACE__, ucwords($type));
        if (!class_exists($class)) {
            throw AttachmentException::IMPLEMENTOR_NOT_ALLOWED();
        }

        if (!isset(self::$implements[$type])) {
            self::$implements[$type] = new $class($options);
        }

        return self::$implements[$type];
    }
}
