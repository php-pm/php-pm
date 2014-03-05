<?php

namespace PHPPM\Bridges;

interface BridgeInterface
{
    public function bootstrap($appenv = null);
    public function onRequest(\React\Http\Request $request, \React\Http\Response $response);
}
