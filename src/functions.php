<?php
declare(strict_types=1);

if (!function_exists('WpQueue')) {
    function WpQueue(string $instanceName): \Tekod\WpQueue\Queue {
        // caching instances
        static $instances = [];
        if (!isset($instances[$instanceName])) {
            $instances[$instanceName] = new \Tekod\WpQueue\Queue($instanceName);
        }
        // return cached instance
        return $instances[$instanceName];
    }
}
