<?php

namespace PHPPM;

use React\EventLoop\LoopInterface;
use React\Socket\Server;
use React\Socket\UnixServer;

class SocketFactory
{
    public static function getServer($host, $port, LoopInterface $loop)
    {
        $uri = '';
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $uri = 'tcp://' . $host . ':' . $port;
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // enclose IPv6 addresses in square brackets before appending port
            $uri = 'tcp://[' . $host . ']:' . $port;
        } elseif (preg_match('#^unix://#', $host)) {
            $uri = $host;
        }

        $server = preg_match('#^unix://#', $uri)
            ? new UnixServer($uri, $loop)
            : new Server($uri, $loop);

        return $server;
    }
}
