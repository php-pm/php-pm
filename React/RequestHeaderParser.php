<?php

namespace React\Http;

use Evenement\EventEmitter;
use Exception;
use RingCentral\Psr7 as g7;

/**
 * @event headers
 * @event error
 *
 * @internal
 */
class RequestHeaderParser extends EventEmitter
{
    private $buffer = '';
    private $maxSize = 4096;

    private $localSocketUri;
    private $remoteSocketUri;

    public function __construct($localSocketUri = null, $remoteSocketUri = null)
    {
        $this->localSocketUri = $localSocketUri;
        $this->remoteSocketUri = $remoteSocketUri;
    }

    public function feed($data)
    {
        $this->buffer .= $data;

        $endOfHeader = strpos($this->buffer, "\r\n\r\n");

        if (false !== $endOfHeader) {
            $currentHeaderSize = $endOfHeader;
        } else {
            $currentHeaderSize = strlen($this->buffer);
        }

        if ($currentHeaderSize > $this->maxSize) {
            $this->emit('error', array(new \OverflowException("Maximum header size of {$this->maxSize} exceeded.", 431), $this));
            $this->removeAllListeners();
            return;
        }

        if (false !== $endOfHeader) {
            try {
                $this->parseAndEmitRequest();
            } catch (Exception $exception) {
                $this->emit('error', array($exception));
            }
            $this->removeAllListeners();
        }
    }

    private function parseAndEmitRequest()
    {
        list($request, $bodyBuffer) = $this->parseRequest($this->buffer);
        $this->emit('headers', array($request, $bodyBuffer));
    }

    private function parseRequest($data)
    {
        list($headers, $bodyBuffer) = explode("\r\n\r\n", $data, 2);

        // parser does not support asterisk-form and authority-form
        // remember original target and temporarily replace and re-apply below
        $originalTarget = null;
        if (strpos($headers, 'OPTIONS * ') === 0) {
            $originalTarget = '*';
            $headers = 'OPTIONS / ' . substr($headers, 10);
        } elseif (strpos($headers, 'CONNECT ') === 0) {
            $parts = explode(' ', $headers, 3);
            $uri = parse_url('tcp://' . $parts[1]);

            // check this is a valid authority-form request-target (host:port)
            if (isset($uri['scheme'], $uri['host'], $uri['port']) && count($uri) === 3) {
                $originalTarget = $parts[1];
                $parts[1] = '/';
                $headers = implode(' ', $parts);
            }
        }

        // parse request headers into obj implementing RequestInterface
        $request = g7\parse_request($headers);

        // create new obj implementing ServerRequestInterface by preserving all
        // previous properties and restoring original request-target
        $serverParams = array(
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true)
        );

        if ($this->remoteSocketUri !== null) {
            $remoteAddress = parse_url($this->remoteSocketUri);
            $serverParams['REMOTE_ADDR'] = $remoteAddress['host'];
//            $serverParams['REMOTE_PORT'] = $remoteAddress['port'];
        }

        if ($this->localSocketUri !== null) {
            $localAddress = parse_url($this->localSocketUri);
            $serverParams['SERVER_ADDR'] = $localAddress['host'];
//            $serverParams['SERVER_PORT'] = $localAddress['port'];
            if (isset($localAddress['scheme']) && $localAddress['scheme'] === 'https') {
                $serverParams['HTTPS'] = 'on';
            }
        }

        $target = $request->getRequestTarget();
        $request = new ServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->getHeaders(),
            $request->getBody(),
            $request->getProtocolVersion(),
            $serverParams
        );
        $request = $request->withRequestTarget($target);

        // re-apply actual request target from above
        if ($originalTarget !== null) {
            $uri = $request->getUri()->withPath('');

            // re-apply host and port from request-target if given
            $parts = parse_url('tcp://' . $originalTarget);
            if (isset($parts['host'], $parts['port'])) {
                $uri = $uri->withHost($parts['host'])->withPort($parts['port']);
            }

            $request = $request->withUri(
                $uri,
                true
            )->withRequestTarget($originalTarget);
        }

        // only support HTTP/1.1 and HTTP/1.0 requests
        if ($request->getProtocolVersion() !== '1.1' && $request->getProtocolVersion() !== '1.0') {
            throw new \InvalidArgumentException('Received request with invalid protocol version', 505);
        }

        // ensure absolute-form request-target contains a valid URI
        if (strpos($request->getRequestTarget(), '://') !== false) {
            $parts = parse_url($request->getRequestTarget());

            // make sure value contains valid host component (IP or hostname), but no fragment
            if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'http' || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('Invalid absolute-form request-target');
            }
        }

        // Optional Host header value MUST be valid (host and optional port)
        if ($request->hasHeader('Host')) {
            $parts = parse_url('http://' . $request->getHeaderLine('Host'));

            // make sure value contains valid host component (IP or hostname)
            if (!$parts || !isset($parts['scheme'], $parts['host'])) {
                $parts = false;
            }

            // make sure value does not contain any other URI component
            unset($parts['scheme'], $parts['host'], $parts['port']);
            if ($parts === false || $parts) {
                throw new \InvalidArgumentException('Invalid Host header value');
            }
        }

        // set URI components from socket address if not already filled via Host header
        if ($request->getUri()->getHost() === '') {
            $parts = parse_url($this->localSocketUri);

            $request = $request->withUri(
                $request->getUri()->withScheme('http')->withHost($parts['host'])->withPort($parts['port']),
                true
            );
        }

        // Do not assume this is HTTPS when this happens to be port 443
        // detecting HTTPS is left up to the socket layer (TLS detection)
        if ($request->getUri()->getScheme() === 'https') {
            $request = $request->withUri(
                $request->getUri()->withScheme('http')->withPort(443),
                true
            );
        }

        // Update request URI to "https" scheme if the connection is encrypted
        $parts = parse_url($this->localSocketUri);
        if (isset($parts['scheme']) && $parts['scheme'] === 'https') {
            // The request URI may omit default ports here, so try to parse port
            // from Host header field (if possible)
            $port = $request->getUri()->getPort();
            if ($port === null) {
                $port = parse_url('tcp://' . $request->getHeaderLine('Host'), PHP_URL_PORT); // @codeCoverageIgnore
            }

            $request = $request->withUri(
                $request->getUri()->withScheme('https')->withPort($port),
                true
            );
        }

        // always sanitize Host header because it contains critical routing information
        $request = $request->withUri($request->getUri()->withUserInfo('u')->withUserInfo(''));

        return array($request, $bodyBuffer);
    }
}
