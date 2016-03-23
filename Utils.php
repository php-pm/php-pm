<?php

namespace PHPPM;

/**
 * Nitty gritty helper methods to hijack objects. Useful to reset properties that would otherwise run amok
 * and result in memory leaks.
 */
class Utils
{
    /**
     * Executes a function in the context of an object. This basically bypasses the private/protected check of PHP.
     *
     * @param callable $fn
     * @param object $newThis
     * @param array $args
     * @param string $bindClass
     */
    public static function bindAndCall(callable $fn, $newThis, $args = [], $bindClass = null)
    {
        $func = \Closure::bind($fn, $newThis, $bindClass ?: get_class($newThis));
        if ($args) {
            call_user_func_array($func, $args);
        } else {
            $func(); //faster
        }
    }

    /**
     * Changes a property value of an object. (hijack because you can also change private/protected properties)
     *
     * @param object $object
     * @param string $propertyName
     * @param mixed $newValue
     */
    public static function hijackProperty($object, $propertyName, $newValue)
    {
        Utils::bindAndCall(function () use ($propertyName, $newValue, $object) {
            $object->$propertyName = $newValue;
        }, $object);
    }

    /**
     * @return bool
     */
    public static function isWindows(){
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
}