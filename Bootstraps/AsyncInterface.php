<?php

namespace PHPPM\Bootstraps;


use React\EventLoop\LoopInterface;

interface AsyncInterface
{
    public function setLoop(LoopInterface $loop);
}