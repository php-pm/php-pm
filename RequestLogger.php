<?php

namespace PHPPM;

use Aerys\InternalRequest;
use Aerys\Middleware;

class RequestLogger implements Middleware {
    const DEFAULT_FORMAT = '[$time_local] $remote_addr - $remote_user "$request" $status $bytes_sent "$http_referer"';

    private $logger;
    private $logFormat;

    public function __construct(callable $logger, string $logFormat = self::DEFAULT_FORMAT) {
        $this->logger = $logger;
        $this->logFormat = $logFormat;
    }

    public function do(InternalRequest $ireq) {
        $timeLocal = date('d/M/Y:H:i:s O', $ireq->time);

        $requestString = $ireq->method . ' ' . \strtok($ireq->uriPath, '?') . ' HTTP/' . $ireq->protocol;

        $headers = yield;

        $statusCode = $headers[":status"];

        if ($statusCode < 400) {
            $requestString = "<info>$requestString</info>";
            $statusCode = "<info>$statusCode</info>";
        }

        $chunk = $headers;
        $bytes = 0;

        while (null !== $chunk = yield $chunk) {
            $bytes += strlen($chunk);
        }

        $message = str_replace([
            '$remote_addr',
            '$remote_user',
            '$time_local',
            '$request',
            '$status',
            '$bytes_sent',
            '$http_referer',
            '$http_user_agent',
        ], [
            $ireq->headers["x-php-pm-remote-ip"][0] ?? '-',
            '-', // todo remote_user
            $timeLocal,
            $requestString,
            $statusCode,
            $bytes,
            $ireq->headers["referer"][0] ?? '-',
            $ireq->headers["user-agent"][0] ?? '-',
        ], $this->logFormat);

        if ($statusCode >= 400) {
            $message = "<error>$message</error>";
        }

        ($this->logger)($message);
    }
}