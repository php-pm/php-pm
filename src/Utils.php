<?php

namespace PHPPM;

/**
 * Helper class to avoid creating closures in static context
 * See https://bugs.php.net/bug.php?id=64761
 */
class ClosureHelper
{
    /**
     * Return a closure that assigns a property value
     */
    public function getPropertyAccessor($propertyName, $newValue) {
        return function () use ($propertyName, $newValue) {
            $this->$propertyName = $newValue;
        };
    }
}

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
        $closure = (new ClosureHelper())->getPropertyAccessor($propertyName, $newValue);
        Utils::bindAndCall($closure, $object);
    }

    /**
     * Generates stronger session ids for session handling.
     *
     * @return string
     */
    public static function generateSessionId()
    {
        return \bin2hex(\random_bytes(32));
    }

    /**
     * @return int bytes
     */
    public static function getMaxMemory()
    {
        $memoryLimit = ini_get('memory_limit');

        // if no limit
        if (-1 == $memoryLimit) {
            return 134217728; //128 * 1024 * 1024 default 128mb
        }

        // if set to exact byte
        if (is_numeric($memoryLimit)) {
            return (int) $memoryLimit;
        }

        // if short hand version http://php.net/manual/en/faq.using.php#faq.using.shorthandbytes
        return substr($memoryLimit, 0, -1) * [
            'g' => 1073741824, //1024 * 1024 * 1024
            'm' => 1048576, //1024 * 1024
            'k' => 1024
        ][strtolower(substr($memoryLimit, -1))];
    }

    /**
     * @param string $path
     *
     * @return string|boolean false when path resolution resolved to out of range
     */
    public static function parseQueryPath($path)
    {
        $path = '/' . ltrim($path, '/');
        $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);

        //examples:
        //1.> /images/../foo.png
        //2.> /foo.png

        //1.> /images/../../foo.png
        //2.> /foo.png
        //3.> false
        while (false !== $pos = strpos($path, '/../')) {
            $leftSlashNext = strrpos(substr($path, 0, $pos), '/');

            if (false === $leftSlashNext) {
                // one /../ too much, without space to the left/up
                return false;
            }

            $path = substr($path, 0, $leftSlashNext + 1) . substr($path, $pos + 4);
        }

        return $path;
    }
}
