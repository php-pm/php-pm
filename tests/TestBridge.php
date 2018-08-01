<?php

namespace PHPPM\Tests;

use Psr\Http\Message\ServerRequestInterface;
use RingCentral\Psr7;
use PHPPM\Bridges\StaticBridge;

class TestBridge extends StaticBridge
{
    /**
     * {@inheritdoc}
     */
    public function handle(ServerRequestInterface $request)
    {

        $params = $request->getQueryParams();
        if(@$params['exit_prematurely'] == '1') {
            exit();
        }
        if(isset($params['sleep'])) {
            sleep($params['sleep']);
        }
        return new Psr7\Response(404, ['Content-type' => 'text/plain'], 'Not found');
    }
}
