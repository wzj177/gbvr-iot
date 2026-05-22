<?php


namespace CoreW\Traits;


use CoreW\Exception\InvalidParamException;

trait EnumTrait
{
    /**
     * @param array $items
     * @param string|null $key
     * @return array|string|int|bool
     * @throws InvalidParamException
     */
    public static function getItems(array $items, $key = null)
    {
        if ($key !== null) {
            if (key_exists($key, $items)) {
                return $items[$key];
            }
            throw new InvalidParamException('Unknown key:' . $key);
        }

        return $items;
    }

    /**
     * @param array $items
     * @param string|integer|null $key
     * @param string $defaultValue
     * @return string|integer|null|bool
     */
    public static function getValue(array $items, $key = null, $defaultValue = '')
    {
        return $items[$key] ?? $defaultValue;
    }

    /**
     * @param array $dicts
     * @param string $indexKey
     * @param string $valueKey
     * @return array|array[]
     */
    public static function dictToList(array $dicts, string $indexKey = 'key', string $valueKey = 'value') : array
    {
        $items = [];
        if (empty($dicts)) {
            return $items;
        }
        foreach ($dicts as $key => $value) {
            $items[] = [
                $indexKey => $key,
                $valueKey => $value,
            ];
        }
        return $items;
    }
}