<?php

namespace PHPPM\Tests;

use PHPPM\Utils;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UtilsTest extends PhpPmTestCase
{
    public function providePaths()
    {
        return [
            ['/images/foo.png', '/images/foo.png'],
            ['/images/../foo.png', '/foo.png'],
            ['/images/sub/../../foo.png', '/foo.png'],
            ['/images/sub/../foo.png', '/images/foo.png'],

            ['/../foo.png', false],
            ['../foo.png', false],
            ['//images/d/../../foo.png', '/foo.png'],
            ['/images//../foo.png', '/images/foo.png'],
            ["/images/\0/../foo.png", '/images/foo.png'],
            ["/images/\0../foo.png", '/foo.png'],
        ];
    }

    /**
     * @dataProvider providePaths
     * @param string $path
     * @param string $expected
     */
    public function testParseQueryPath($path, $expected)
    {
        $this->assertEquals($expected, Utils::parseQueryPath($path));
    }

    public function testHijackProperty()
    {
        $object = new \PHPPM\SlavePool($this->createMock(LoopInterface::class), $this->createMock(OutputInterface::class));
        Utils::hijackProperty($object, 'slaves', ['SOME VALUE']);

        $r = new \ReflectionObject($object);
        $p = $r->getProperty('slaves');
        $p->setAccessible(true);
        $this->assertEquals(['SOME VALUE'], $p->getValue($object));
    }
}
