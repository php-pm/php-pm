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
