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
    public static function isWindows()
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Generates stronger session ids for session handling.
     *
     * @return string
     */
    public static function generateSessionId()
    {
        $entropy = '';

        $entropy .= uniqid(mt_rand(), true);
        $entropy .= microtime(true);

        if (function_exists('openssl_random_pseudo_bytes')) {
            $entropy .= openssl_random_pseudo_bytes(32, $strong);
        }
        
        if (function_exists('mcrypt_create_iv')) {
            $entropy .= mcrypt_create_iv(32, MCRYPT_DEV_URANDOM);
        }

        $hash = hash('whirlpool', $entropy);

        return $hash;
    }
}