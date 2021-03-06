<?php

namespace Alchemy\Tests\Phrasea\Cache;

use Alchemy\Phrasea\Cache\XcacheCache;

class XcacheCacheTest extends \PhraseanetTestCase
{
    /**
     * @var XcacheCache
     */
    protected $object;

    public function setUp()
    {
        if ( ! function_exists('xcache_info')) {
            $this->markTestSkipped('Xcache not loaded');
        }

        $this->object = new XcacheCache;
    }

    public function testIsServer()
    {
        $this->assertTrue(is_bool($this->object->isServer()));
    }

    public function testGetStats()
    {
        $this->assertTrue(is_array($this->object->getStats()) || is_null($this->object->getStats()));
    }

    public function testGet()
    {
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete(
            'This test has not been implemented yet.'
        );
    }

    public function testDeleteMulti()
    {
        // Remove the following lines when you implement this test.
        $this->markTestIncomplete(
            'This test has not been implemented yet.'
        );
    }
}
