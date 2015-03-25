<?php

namespace PHPPM\Bridges;

interface BridgeInterface
{
    public function bootstrap($appBootstrap, $appenv);

    /**
     * @param \React\Http\Request $request
     * @param \React\Http\Response $response
     * @param array $postData
     * @return void
     */
    public function onRequest(\React\Http\Request $request, \React\Http\Response $response, array $postData);
}
