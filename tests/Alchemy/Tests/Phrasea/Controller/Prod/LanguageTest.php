<?php

namespace Alchemy\Tests\Phrasea\Controller\Prod;

class LanguageTest extends \PhraseanetWebTestCase
{
    protected $client;

    public function testRootPost()
    {
        $route = '/prod/language/';

        self::$DI['client']->request("GET", $route);
        $this->assertTrue(self::$DI['client']->getResponse()->isOk());
        $this->assertEquals("application/json", self::$DI['client']->getResponse()->headers->get("content-type"));
        $pageContent = json_decode(self::$DI['client']->getResponse()->getContent());
        $this->assertTrue(is_object($pageContent));
    }
}
