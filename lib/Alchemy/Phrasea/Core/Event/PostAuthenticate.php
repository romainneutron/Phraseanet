<?php

namespace Alchemy\Phrasea\Core\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\Event as SfEvent;

class PostAuthenticate extends SfEvent
{
    private $user;
    private $request;
    private $response;

    public function __construct(Request $request, Response $response, \User_Adapter $user)
    {
        $this->request = $request;
        $this->response = $response;
        $this->user = $user;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function setResponse(Response $response)
    {
        $this->response = $response;
    }
}
