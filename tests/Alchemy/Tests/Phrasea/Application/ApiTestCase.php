<?php

namespace Alchemy\Tests\Phrasea\Application;

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Border\File;
use Alchemy\Phrasea\Core\PhraseaEvents;
use Alchemy\Phrasea\Authentication\Context;
use Alchemy\Phrasea\Model\Entities\Task;
use Alchemy\Phrasea\Model\Entities\LazaretSession;
use Doctrine\Common\Collections\ArrayCollection;
use Guzzle\Common\Exception\GuzzleException;
use Symfony\Component\HttpKernel\Client;
use Symfony\Component\HttpFoundation\Response;

abstract class ApiTestCase extends \PhraseanetWebTestCase
{
    /**
     * @var \API_OAuth2_Token
     */
    private static $token;

    /**
     * @var \API_OAuth2_Account
     */
    private static $account;
    /**
     * @var \API_OAuth2_Application
     */
    private static $oauthApplication;
    /**
     * @var \API_OAuth2_Token
     */
    private static $adminToken;
    /**
     * @var \API_OAuth2_Account
     */
    private static $adminAccount;
    /**
     * @var \API_OAuth2_Application
     */
    private static $adminApplication;
    private static $apiInitialized = false;

    abstract protected function getParameters(array $parameters = []);
    abstract protected function unserialize($data);
    abstract protected function getAcceptMimeType();

    public function tearDown()
    {
        $this->unsetToken();
        parent::tearDown();
    }

    public function setUp()
    {
        parent::setUp();

        self::$DI['app'] = self::$DI->share(function ($DI) {
            return $this->loadApp('lib/Alchemy/Phrasea/Application/Api.php');
        });

        if (!self::$apiInitialized) {
            self::$account = \API_OAuth2_Account::load_with_user(self::$DI['app'], self::$DI['oauth2-app-user_notAdmin'], self::$DI['user_notAdmin']);
            self::$account->set_revoked(false);
            self::$token = self::$account->get_token()->get_value();

            self::$adminAccount = \API_OAuth2_Account::load_with_user(self::$DI['app'], self::$DI['oauth2-app-user'], self::$DI['user']);
            self::$adminAccount->set_revoked(false);
            self::$adminToken = self::$adminAccount->get_token()->get_value();

            self::$apiInitialized = true;
        }
    }

    public static function tearDownAfterClass()
    {
        self::$apiInitialized = false;
        self::$token = self::$account = self::$oauthApplication = self::$adminToken
            = self::$adminAccount = self::$adminApplication = null;

        parent::tearDownAfterClass();
    }

    /**
     * @dataProvider provideEventNames
     */
    public function testThatEventsAreDispatched($eventName, $className, $route, $context)
    {
        $preEvent = 0;
        self::$DI['app']['dispatcher']->addListener($eventName, function ($event) use (&$preEvent, $className, $context) {
            $preEvent++;
            $this->assertInstanceOf($className, $event);
            if (null !== $context) {
                $this->assertEquals($context, $event->getContext()->getContext());
            }
        });

        $this->setToken(self::$token);
        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);

        $this->assertEquals(1, $preEvent);
    }

    public function testThatSessionIsClosedAfterRequest()
    {
        $this->assertCount(0, self::$DI['app']['EM']->getRepository('Phraseanet:Session')->findAll());
        $this->setToken(self::$token);
        self::$DI['client']->request('GET', '/api/v1/databoxes/list/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $this->assertCount(0, self::$DI['app']['EM']->getRepository('Phraseanet:Session')->findAll());
    }

    public function provideEventNames()
    {
        return [
            [PhraseaEvents::PRE_AUTHENTICATE, 'Alchemy\Phrasea\Core\Event\PreAuthenticate', '/api/v1/databoxes/list/', Context::CONTEXT_OAUTH2_TOKEN],
            [PhraseaEvents::API_OAUTH2_START, 'Alchemy\Phrasea\Core\Event\ApiOAuth2StartEvent', '/api/v1/databoxes/list/', null],
            [PhraseaEvents::API_OAUTH2_END, 'Alchemy\Phrasea\Core\Event\ApiOAuth2EndEvent', '/api/v1/databoxes/list/', null],
            [PhraseaEvents::API_RESULT, 'Alchemy\Phrasea\Core\Event\ApiResultEvent', '/api/v1/databoxes/list/', null],
            [PhraseaEvents::API_RESULT, 'Alchemy\Phrasea\Core\Event\ApiResultEvent', '/api/v1/no-route', null],
        ];
    }

    public function testRouteNotFound()
    {
        $route = '/api/v1/nothinghere';
        $this->setToken(self::$token);
        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponseNotFound(self::$DI['client']->getResponse());
        $this->evaluateMetaNotFound($content);
    }

    /**
     * @covers \API_V1_adapter::get_databoxes
     * @covers \API_V1_adapter::list_databoxes
     * @covers \API_V1_adapter::list_databox
     */
    public function testDataboxListRoute()
    {
        $this->setToken(self::$token);
        self::$DI['client']->request('GET', '/api/v1/databoxes/list/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('databoxes', $content['response']);
        foreach ($content['response']['databoxes'] as $databox) {
            $this->assertTrue(is_array($databox), 'Une databox est un objet');
            $this->assertArrayHasKey('databox_id', $databox);
            $this->assertArrayHasKey('name', $databox);
            $this->assertArrayHasKey('viewname', $databox);
            $this->assertArrayHasKey('labels', $databox);
            $this->assertArrayHasKey('fr', $databox['labels']);
            $this->assertArrayHasKey('en', $databox['labels']);
            $this->assertArrayHasKey('de', $databox['labels']);
            $this->assertArrayHasKey('nl', $databox['labels']);
            $this->assertArrayHasKey('version', $databox);
            break;
        }
    }

    public function testCheckNativeApp()
    {
        $value = self::$DI['app']['conf']->get(['registry', 'api-clients', 'navigator-enabled']);
        self::$DI['app']['conf']->set(['registry', 'api-clients', 'navigator-enabled'], false);

        $fail = null;

        try {

            $nativeApp = \API_OAuth2_Application::load_from_client_id(self::$DI['app'], \API_OAuth2_Application_Navigator::CLIENT_ID);

            $account = \API_OAuth2_Account::create(self::$DI['app'], self::$DI['user'], $nativeApp);
            $token = $account->get_token()->get_value();
            $this->setToken($token);
            self::$DI['client']->request('GET', '/api/v1/databoxes/list/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
            $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

            if (403 != $content['meta']['http_code']) {
                $fail = new \Exception('Result does not match expected 403, returns ' . $content['meta']['http_code']);
            }
        } catch (\Exception $e) {
            $fail = $e;
        }

        self::$DI['app']['conf']->set(['registry', 'api-clients', 'navigator-enabled'], false);

        if ($fail) {
            throw $fail;
        }
    }

    /**
     * Covers mustBeAdmin route middleware
     */
    public function testAdminOnlyShedulerState()
    {
        $this->setToken(self::$token);

        self::$DI['client']->request('GET', '/api/v1/monitor/tasks/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->assertEquals(401, $content['meta']['http_code']);

        self::$DI['client']->request('GET', '/api/v1/monitor/scheduler/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->assertEquals(401, $content['meta']['http_code']);

        self::$DI['client']->request('GET', '/api/v1/monitor/task/1/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->assertEquals(401, $content['meta']['http_code']);

        self::$DI['client']->request('POST', '/api/v1/monitor/task/1/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->assertEquals(401, $content['meta']['http_code']);

        self::$DI['client']->request('POST', '/api/v1/monitor/task/1/start/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->assertEquals(401, $content['meta']['http_code']);

        self::$DI['client']->request('POST', '/api/v1/monitor/task/1/stop/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->assertEquals(401, $content['meta']['http_code']);

        self::$DI['client']->request('GET', '/api/v1/monitor/phraseanet/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->assertEquals(401, $content['meta']['http_code']);
    }

    /**
     * Route GET /API/V1/monitor/task
     * @covers API_V1_adapter::get_task_list
     * @covers API_V1_adapter::list_task
     */
    public function testGetMonitorTasks()
    {
        if (null === self::$adminToken) {
            $this->markTestSkipped('there is no user with admin rights');
        }
        $this->setToken(self::$adminToken);

        $route = '/api/v1/monitor/tasks/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);
        $response = $content['response'];

        $tasks = self::$DI['app']['manipulator.task']->getRepository()->findAll();
        $this->assertEquals(count($tasks), count($response['tasks']));

        foreach ($response['tasks'] as $task) {
            $this->evaluateGoodTask($task);
        }
    }

    /**
     * Route GET /API/V1/monitor/scheduler
     * @covers API_V1_adapter::get_scheduler
     */
    public function testGetScheduler()
    {
        if (null === self::$adminToken) {
            $this->markTestSkipped('there is no user with admin rights');
        }
        $this->setToken(self::$adminToken);

        $route = '/api/v1/monitor/scheduler/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);
        $response = $content['response'];

        $this->assertInternalType('array', $response['scheduler']);

        $this->assertArrayHasKey('state', $response['scheduler']);
        $this->assertArrayHasKey('pid', $response['scheduler']);
        $this->assertArrayHasKey('updated_on', $response['scheduler']);
        $this->assertArrayHasKey('status', $response['scheduler']);
        $this->assertArrayHasKey('configuration', $response['scheduler']);
        $this->assertArrayHasKey('process-id', $response['scheduler']);

        $this->assertEquals(6, count($response['scheduler']));

        if (null !== $response['scheduler']['updated_on']) {
            $this->assertDateAtom($response['scheduler']['updated_on']);
        }
        if (null !== $response['scheduler']['pid']) {
            $this->assertTrue(is_int($response['scheduler']['pid']));
        }

        $this->assertTrue('' !== $response['scheduler']['state']);
    }

    protected function evaluateGoodTask($task)
    {
        $this->assertArrayHasKey('id', $task);
        $this->assertArrayHasKey('name', $task);
        $this->assertArrayHasKey('state', $task);
        $this->assertArrayHasKey('status', $task);
        $this->assertArrayHasKey('actual-status', $task);
        $this->assertArrayHasKey('pid', $task);
        $this->assertArrayHasKey('process-id', $task);
        $this->assertArrayHasKey('title', $task);
        $this->assertArrayHasKey('crashed', $task);
        $this->assertArrayHasKey('auto_start', $task);
        $this->assertArrayHasKey('last_exec_time', $task);
        $this->assertArrayHasKey('last_execution', $task);
        $this->assertArrayHasKey('updated', $task);
        $this->assertArrayHasKey('created', $task);
        $this->assertArrayHasKey('period', $task);
        $this->assertArrayHasKey('jobId', $task);

        $this->assertInternalType('integer', $task['id']);

        if (!is_null($task['pid'])) {
            $this->assertInternalType('integer', $task['pid']);
        }

        $av_states = [
            Task::STATUS_STARTED,
            Task::STATUS_STOPPED,
        ];

        $this->assertContains($task['state'], $av_states);
        $this->assertInternalType('string', $task['name']);
        $this->assertInternalType('string', $task['title']);

        if (!is_null($task['last_exec_time'])) {
            $this->assertDateAtom($task['last_exec_time']);
        }
    }

    /**
     * Route GET /API/V1/monitor/task{idTask}
     * @covers API_V1_adapter::get_task
     * @covers API_V1_adapter::list_task
     */
    public function testGetMonitorTaskById()
    {
        $tasks = self::$DI['app']['manipulator.task']->getRepository()->findAll();

        if (null === self::$adminToken) {
            $this->markTestSkipped('there is no user with admin rights');
        }

        if (!count($tasks)) {
            $this->markTestSkipped('no tasks created for the current instance');
        }

        $this->setToken(self::$adminToken);
        $idTask = $tasks[0]->getId();

        $route = '/api/v1/monitor/task/' . $idTask . '/';
        $this->evaluateMethodNotAllowedRoute($route, ['PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('task', $content['response']);
        $this->evaluateGoodTask($content['response']['task']);
    }

    /**
     * Route POST /API/V1/monitor/task{idTask}
     * @covers API_V1_adapter::set_task_property
     */
    public function testPostMonitorTaskById()
    {
        $tasks = self::$DI['app']['manipulator.task']->getRepository()->findAll();

        if (null === self::$adminToken) {
            $this->markTestSkipped('there is no user with admin rights');
        }

        if (!count($tasks)) {
            $this->markTestSkipped('no tasks created for the current instance');
        }

        $this->setToken(self::$adminToken);
        $idTask = $tasks[0]->getId();

        $route = '/api/v1/monitor/task/' . $idTask . '/';
        $this->evaluateMethodNotAllowedRoute($route, ['PUT', 'DELETE']);

        $title = 'newTitle' . mt_rand();

        self::$DI['client']->request('POST', $route, $this->getParameters(['title' => $title]), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('task', $content['response']);
        $this->evaluateGoodTask($content['response']['task']);
        $this->assertEquals($title, $content['response']['task']['title']);
    }

    /**
     * Route GET /API/V1/monitor/task/{idTask}/
     * @covers API_V1_adapter::get_task
     */
    public function testUnknowGetMonitorTaskById()
    {
        if (null === self::$adminToken) {
            $this->markTestSkipped('no tasks created for the current instance');
        }
        $this->setToken(self::$adminToken);
        self::$DI['client']->followRedirects();
        self::$DI['client']->request('GET', '/api/v1/monitor/task/0/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateMetaNotFound($content);
    }

    /**
     * Route POST /API/V1/monitor/task/{idTask}/start
     * @covers API_V1_adapter::start_task
     */
    public function testPostMonitorStartTask()
    {
        if (null === self::$adminToken) {
            $this->markTestSkipped('there is no user with admin rights');
        }

        $tasks = self::$DI['app']['manipulator.task']->getRepository()->findAll();

        if (!count($tasks)) {
            $this->markTestSkipped('no tasks created for the current instance');
        }

        $this->setToken(self::$adminToken);
        $idTask = $tasks[0]->getId();

        $route = '/api/v1/monitor/task/' . $idTask . '/start/';
        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('task', $content['response']);
        $this->evaluateGoodTask($content['response']['task']);

        $task = self::$DI['app']['manipulator.task']->getRepository()->find($idTask);
        $this->assertEquals(Task::STATUS_STARTED, $task->getStatus());
    }

    /**
     * Route POST /API/V1/monitor/task/{idTask}/stop
     * @covers API_V1_adapter::stop_task
     */
    public function testPostMonitorStopTask()
    {
        $tasks = self::$DI['app']['manipulator.task']->getRepository()->findAll();

        if (null === self::$adminToken) {
            $this->markTestSkipped('there is no user with admin rights');
        }

        if (!count($tasks)) {
            $this->markTestSkipped('no tasks created for the current instance');
        }

        $this->setToken(self::$adminToken);
        $idTask = $tasks[0]->getId();

        $route = '/api/v1/monitor/task/' . $idTask . '/stop/';
        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('task', $content['response']);
        $this->evaluateGoodTask($content['response']['task']);

        $task = self::$DI['app']['manipulator.task']->getRepository()->find($idTask);
        $this->assertEquals(Task::STATUS_STOPPED, $task->getStatus());
    }

    /**
     * Route GET /API/V1/monitor/phraseanet
     * @covers API_V1_adapter::get_phraseanet_monitor
     * @covers API_V1_adapter::get_config_info
     * @covers API_V1_adapter::get_cache_info
     * @covers API_V1_adapter::get_gv_info
     */
    public function testgetMonitorPhraseanet()
    {
        if (null === self::$adminToken) {
            $this->markTestSkipped('there is no user with admin rights');
        }

        $this->setToken(self::$adminToken);

        self::$DI['client']->request('GET', '/api/v1/monitor/phraseanet/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);
        $this->assertArrayHasKey('global_values', $content['response']);
        $this->assertArrayHasKey('cache', $content['response']);
        $this->assertArrayHasKey('phraseanet', $content['response']);

        $this->assertInternalType('array', $content['response']['global_values']);
        $this->assertInternalType('array', $content['response']['cache']);
        $this->assertInternalType('array', $content['response']['phraseanet']);
    }

    /**
     * @covers \API_V1_adapter::get_record
     * @covers \API_V1_adapter::list_record
     */
    public function testRecordRoute()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->evaluateGoodRecord($content['response']['record']);

        $route = '/api/v1/records/1234567890/1/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/records/kjslkz84spm/sfsd5qfsd5/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_story
     * @covers \API_V1_adapter::list_story
     */
    public function testStoryRoute()
    {
        $this->setToken(self::$token);
        self::$DI['app']['session']->set('usr_id', self::$DI['user']->getId());
        if (false ===  self::$DI['record_story_1']->hasChild(self::$DI['record_1'])) {
            self::$DI['record_story_1']->appendChild(self::$DI['record_1']);
        }

        self::$DI['app']['session']->remove('usr_id');

        $route = '/api/v1/stories/' . self::$DI['record_story_1']->get_sbas_id() . '/' . self::$DI['record_story_1']->get_record_id() . '/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->evaluateGoodStory($content['response']['story']);
        $this->assertGreaterThan(0, $content['response']['story']['records']);

        $route = '/api/v1/stories/1234567890/1/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/stories/kjslkz84spm/sfsd5qfsd5/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['record_story_1']->removeChild(self::$DI['record_1']);
    }

    /**
     * @covers \API_V1_adapter::get_databox_collections
     * @covers \API_V1_adapter::list_databox_collections
     * @covers \API_V1_adapter::list_collection
     */
    public function testDataboxCollectionRoute()
    {
        $this->setToken(self::$token);
        $databox_id = self::$DI['record_1']->get_sbas_id();
        $route = '/api/v1/databoxes/' . $databox_id . '/collections/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('collections', $content['response']);
        foreach ($content['response']['collections'] as $collection) {
            $this->assertTrue(is_array($collection), 'Une collection est un objet');
            $this->assertArrayHasKey('base_id', $collection);
            $this->assertArrayHasKey('collection_id', $collection);
            $this->assertArrayHasKey('name', $collection);
            $this->assertArrayHasKey('labels', $collection);
            $this->assertArrayHasKey('fr', $collection['labels']);
            $this->assertArrayHasKey('en', $collection['labels']);
            $this->assertArrayHasKey('de', $collection['labels']);
            $this->assertArrayHasKey('nl', $collection['labels']);
            $this->assertArrayHasKey('record_amount', $collection);
            $this->assertTrue(is_int($collection['base_id']));
            $this->assertGreaterThan(0, $collection['base_id']);
            $this->assertTrue(is_int($collection['collection_id']));
            $this->assertGreaterThan(0, $collection['collection_id']);
            $this->assertTrue(is_string($collection['name']));
            $this->assertTrue(is_int($collection['record_amount']));
            break;
        }
        $route = '/api/v1/databoxes/24892534/collections/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/databoxes/any_bad_id/collections/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_databox_status
     * @covers \API_V1_adapter::list_databox_status
     */
    public function testDataboxStatusRoute()
    {
        $this->setToken(self::$token);
        $databox_id = self::$DI['record_1']->get_sbas_id();
        $databox = self::$DI['app']['phraseanet.appbox']->get_databox($databox_id);
        $ref_status = $databox->get_statusbits();
        $route = '/api/v1/databoxes/' . $databox_id . '/status/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('status', $content['response']);
        foreach ($content['response']['status'] as $status) {
            $this->assertTrue(is_array($status), 'Un bloc status est un objet');
            $this->assertArrayHasKey('bit', $status);
            $this->assertTrue(is_int($status['bit']));
            $this->assertGreaterThan(3, $status['bit']);
            $this->assertLessThan(65, $status['bit']);
            $this->assertArrayHasKey('label_on', $status);
            $this->assertArrayHasKey('label_off', $status);
            $this->assertArrayHasKey('labels', $status);
            $this->assertArrayHasKey('fr', $status['labels']);
            $this->assertArrayHasKey('en', $status['labels']);
            $this->assertArrayHasKey('de', $status['labels']);
            $this->assertArrayHasKey('nl', $status['labels']);
            $this->assertArrayHasKey('img_on', $status);
            $this->assertArrayHasKey('img_off', $status);
            $this->assertArrayHasKey('searchable', $status);
            $this->assertArrayHasKey('printable', $status);
            $this->assertTrue(is_bool($status['searchable']));
            $this->assertTrue($status['searchable'] === (bool) $ref_status[$status['bit']]['searchable']);
            $this->assertTrue(is_bool($status['printable']));
            $this->assertTrue($status['printable'] === (bool) $ref_status[$status['bit']]['printable']);
            $this->assertTrue($status['label_on'] === $ref_status[$status['bit']]['labelon']);
            $this->assertTrue($status['img_off'] === $ref_status[$status['bit']]['img_off']);
            $this->assertTrue($status['img_on'] === $ref_status[$status['bit']]['img_on']);
            break;
        }
        $route = '/api/v1/databoxes/24892534/status/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/databoxes/any_bad_id/status/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_databox_metadatas
     * @covers \API_V1_adapter::list_databox_metadatas_fields
     * @covers \API_V1_adapter::list_databox_metadata_field_properties
     */
    public function testDataboxMetadatasRoute()
    {
        $this->setToken(self::$token);
        $databox_id = self::$DI['record_1']->get_sbas_id();
        $databox = self::$DI['app']['phraseanet.appbox']->get_databox($databox_id);
        $ref_structure = $databox->get_meta_structure();

        try {
            $ref_structure->get_element('idbarbouze');
            $this->fail('An expected exception has not been raised.');
        } catch (\Exception_Databox_FieldNotFound $e) {

        }

        $route = '/api/v1/databoxes/' . $databox_id . '/metadatas/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('document_metadatas', $content['response']);
        foreach ($content['response']['document_metadatas'] as $metadatas) {
            $this->assertTrue(is_array($metadatas), 'Un bloc metadata est un objet');
            $this->assertArrayHasKey('id', $metadatas);
            $this->assertArrayHasKey('namespace', $metadatas);
            $this->assertArrayHasKey('source', $metadatas);
            $this->assertArrayHasKey('tagname', $metadatas);
            $this->assertArrayHasKey('name', $metadatas);
            $this->assertArrayHasKey('separator', $metadatas);
            $this->assertArrayHasKey('thesaurus_branch', $metadatas);
            $this->assertArrayHasKey('type', $metadatas);
            $this->assertArrayHasKey('labels', $metadatas);
            $this->assertArrayHasKey('indexable', $metadatas);
            $this->assertArrayHasKey('multivalue', $metadatas);
            $this->assertArrayHasKey('readonly', $metadatas);
            $this->assertArrayHasKey('required', $metadatas);

            $this->assertTrue(is_int($metadatas['id']));
            $this->assertTrue(is_string($metadatas['namespace']));
            $this->assertTrue(is_string($metadatas['name']));
            $this->assertTrue(is_array($metadatas['labels']));
            $this->assertTrue(is_null($metadatas['source']) || is_string($metadatas['source']));
            $this->assertTrue(is_string($metadatas['tagname']));
            $this->assertTrue((strlen($metadatas['name']) > 0));
            $this->assertTrue(is_string($metadatas['separator']));

            $this->assertEquals(['fr', 'en', 'de', 'nl'], array_keys($metadatas['labels']));

            if ($metadatas['multivalue']) {
                $this->assertTrue((strlen($metadatas['separator']) > 0));
            }

            $this->assertTrue(is_string($metadatas['thesaurus_branch']));
            $this->assertTrue(in_array($metadatas['type'], [\databox_field::TYPE_DATE, \databox_field::TYPE_STRING, \databox_field::TYPE_NUMBER, \databox_field::TYPE_TEXT]));
            $this->assertTrue(is_bool($metadatas['indexable']));
            $this->assertTrue(is_bool($metadatas['multivalue']));
            $this->assertTrue(is_bool($metadatas['readonly']));
            $this->assertTrue(is_bool($metadatas['required']));

            $element = $ref_structure->get_element($metadatas['id']);
            $this->assertTrue($element->is_indexable() === $metadatas['indexable']);
            $this->assertTrue($element->is_required() === $metadatas['required']);
            $this->assertTrue($element->is_readonly() === $metadatas['readonly']);
            $this->assertTrue($element->is_multi() === $metadatas['multivalue']);
            $this->assertTrue($element->get_type() === $metadatas['type']);
            $this->assertTrue($element->get_tbranch() === $metadatas['thesaurus_branch']);
            $this->assertTrue($element->get_separator() === $metadatas['separator']);
            $this->assertTrue($element->get_name() === $metadatas['name']);
            $this->assertTrue($element->get_tag()->getName() === $metadatas['tagname']);
            $this->assertTrue($element->get_tag()->getTagname() === $metadatas['source']);
            $this->assertTrue($element->get_tag()->getGroupName() === $metadatas['namespace']);
            break;
        }
        $route = '/api/v1/databoxes/24892534/metadatas/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/databoxes/any_bad_id/metadatas/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_databox_terms
     * @covers \API_V1_adapter::list_databox_terms
     *
     */
    public function testDataboxTermsOfUseRoute()
    {
        $this->setToken(self::$token);
        $databox_id = self::$DI['record_1']->get_sbas_id();
        $route = '/api/v1/databoxes/' . $databox_id . '/termsOfUse/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('termsOfUse', $content['response']);
        foreach ($content['response']['termsOfUse'] as $terms) {
            $this->assertTrue(is_array($terms), 'Une bloc cgu est un objet');
            $this->assertArrayHasKey('locale', $terms);
            $this->assertTrue(in_array($terms['locale'], array_keys(Application::getAvailableLanguages())));
            $this->assertArrayHasKey('terms', $terms);
            break;
        }
        $route = '/api/v1/databoxes/24892534/termsOfUse/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/databoxes/any_bad_id/termsOfUse/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::search
     * @covers \API_V1_adapter::list_record
     * @covers \API_V1_adapter::list_story
     */
    public function testSearchRoute()
    {
        $this->setToken(self::$token);
        self::$DI['client']->request('POST', '/api/v1/search/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $response = $content['response'];

        $this->evaluateSearchResponse($response);

        $this->assertArrayHasKey('stories', $response['results']);
        $this->assertArrayHasKey('records', $response['results']);

        $found = false;

        foreach ($response['results']['records'] as $record) {
            $this->evaluateGoodRecord($record);
            $found = true;
            break;
        }

        if (!$found) {
            $this->fail('Unable to find record back');
        }
    }

    /**
     * @covers \API_V1_adapter::search
     * @covers \API_V1_adapter::list_record
     * @covers \API_V1_adapter::list_story
     */
    public function testSearchRouteWithStories()
    {
        $this->setToken(self::$token);

        self::$DI['record_story_1'];

        self::$DI['client']->request('POST', '/api/v1/search/', $this->getParameters(['search_type' => 1]), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $response = $content['response'];

        $this->evaluateSearchResponse($response);

        $this->assertArrayHasKey('stories', $response['results']);
        $this->assertArrayHasKey('records', $response['results']);

        $found = false;

        foreach ($response['results']['stories'] as $story) {
            $this->evaluateGoodStory($story);
            $found = true;
            break;
        }

        if (!$found) {
            $this->fail('Unable to find story back');
        }
    }

    /**
     * @covers \API_V1_adapter::search_records
     * @covers \API_V1_adapter::list_record
     */
    public function testRecordsSearchRoute()
    {
        $this->setToken(self::$token);
        self::$DI['client']->request('POST', '/api/v1/records/search/', $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $response = $content['response'];

        $this->evaluateSearchResponse($response);

        foreach ($response['results'] as $record) {
            $this->evaluateGoodRecord($record);
            break;
        }
    }

    /**
     * @dataProvider provideAvailableSearchMethods
     */
    public function testRecordsSearchRouteWithQuery($method)
    {
        $this->setToken(self::$token);
        $searchEngine = $this->getMockBuilder('Alchemy\Phrasea\SearchEngine\SearchEngineResult')
            ->disableOriginalConstructor()
            ->getMock();

        $searchEngine->expects($this->any())
            ->method('getSuggestions')
            ->will($this->returnValue(new ArrayCollection()));

        self::$DI['app']['phraseanet.SE'] = $this->getMock('Alchemy\Phrasea\SearchEngine\SearchEngineInterface');

        self::$DI['app']['phraseanet.SE']->expects($this->once())
                ->method('query')
                ->with('koala', 0, 10)
                ->will($this->returnValue(
                    $this->getMockBuilder('Alchemy\Phrasea\SearchEngine\SearchEngineResult')
                        ->disableOriginalConstructor()
                        ->getMock()
                ));
        self::$DI['client']->request($method, '/api/v1/records/search/', $this->getParameters(['query' => 'koala']), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
    }

    public function provideAvailableSearchMethods()
    {
        return [['POST'], ['GET']];
    }

    /**
     * @covers \API_V1_adapter::caption_records
     */
    public function testRecordsCaptionRoute()
    {
        $this->setToken(self::$token);

        $this->injectMetadatas(self::$DI['record_1']);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/caption/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->evaluateRecordsCaptionResponse($content);

        $route = '/api/v1/records/24892534/51654651553/caption/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/records/any_bad_id/sfsd5qfsd5/caption/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_record_metadatas
     * @covers \API_V1_adapter::list_record_caption
     */
    public function testRecordsMetadatasRoute()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/metadatas/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->evaluateRecordsMetadataResponse($content);

        $route = '/api/v1/records/24892534/51654651553/metadatas/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/records/any_bad_id/sfsd5qfsd5/metadatas/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_record_status
     */
    public function testRecordsStatusRoute()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/status/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->evaluateRecordsStatusResponse(self::$DI['record_1'], $content);

        $route = '/api/v1/records/24892534/51654651553/status/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/records/any_bad_id/sfsd5qfsd5/status/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_record_embed
     * @covers \API_V1_adapter::list_embedable_media
     * @covers \API_V1_adapter::list_permalink
     */
    public function testRecordsEmbedRoute()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/embed/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('embed', $content['response']);

        foreach ($content['response']['embed'] as $embed) {
            $this->checkEmbed($embed, self::$DI['record_1']);
        }
        $route = '/api/v1/records/24892534/51654651553/embed/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/records/any_bad_id/sfsd5qfsd5/embed/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_record_embed
     * @covers \API_V1_adapter::list_embedable_media
     * @covers \API_V1_adapter::list_permalink
     */
    public function testStoriesEmbedRoute()
    {
        $this->setToken(self::$token);
        $story = self::$DI['record_story_1'];

        $route = '/api/v1/stories/' . $story->get_sbas_id() . '/' . $story->get_record_id() . '/embed/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('embed', $content['response']);

        foreach ($content['response']['embed'] as $embed) {
            $this->checkEmbed($embed, $story);
        }
        $route = '/api/v1/stories/24892534/51654651553/embed/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/stories/any_bad_id/sfsd5qfsd5/embed/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::get_record_embed
     */
    public function testRecordsEmbedRouteMimeType()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/embed/';

        self::$DI['client']->request('GET', $route, $this->getParameters(['mimes' => ['image/png']]), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->assertArrayHasKey('embed', $content['response']);

        $this->assertEquals(0, count($content['response']['embed']));
    }

    /**
     * @covers \API_V1_adapter::get_record_related
     */
    public function testRecordsEmbedRouteDevices()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/embed/';

        self::$DI['client']->request('GET', $route, $this->getParameters(['devices' => ['nodevice']]), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->assertEquals(0, count($content['response']['embed']));
    }

    /**
     * @covers \API_V1_adapter::get_record_related
     */
    public function testRecordsRelatedRoute()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/related/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);
        $this->assertArrayHasKey("baskets", $content['response']);

        foreach ($content['response']['baskets'] as $basket) {
            $this->evaluateGoodBasket($basket);
        }

        $route = '/api/v1/records/24892534/51654651553/related/';
        $this->evaluateNotFoundRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
        $route = '/api/v1/records/any_bad_id/sfsd5qfsd5/related/';
        $this->evaluateBadRequestRoute($route, ['GET']);
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);
    }

    /**
     * @covers \API_V1_adapter::set_record_metadatas
     * @covers \API_V1_adapter::list_record_caption
     * @covers \API_V1_adapter::list_record_caption_field
     */
    public function testRecordsSetMetadatas()
    {
        $this->setToken(self::$token);

        $record = self::$DI['record_1'];

        $route = '/api/v1/records/' . $record->get_sbas_id() . '/' . $record->get_record_id() . '/setmetadatas/';
        $caption = $record->get_caption();

        $toupdate = [];

        foreach ($record->get_databox()->get_meta_structure()->get_elements() as $field) {
            try {
                $values = $record->get_caption()->get_field($field->get_name())->get_values();
                $value = array_pop($values);
                $meta_id = $value->getId();
            } catch (\Exception $e) {
                $meta_id = null;
            }

            $toupdate[$field->get_id()] = [
                'meta_id'        => $meta_id
                , 'meta_struct_id' => $field->get_id()
                , 'value'          => 'podom pom pom ' . $field->get_id()
            ];
        }

        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(['metadatas' => $toupdate]), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey("record_metadatas", $content['response']);
        $this->assertEquals(count($caption->get_fields()), count($content['response']['record_metadatas']), 'Retrived metadatas are the same');

        foreach ($caption->get_fields() as $field) {
            foreach ($field->get_values() as $value) {
                if ($field->is_readonly() === false && $field->is_multi() === false) {
                    $saved_value = $toupdate[$field->get_meta_struct_id()]['value'];
                    $this->assertEquals($value->getValue(), $saved_value);
                }
            }
        }

        $this->evaluateRecordsMetadataResponse($content);

        foreach ($content['response']['record_metadatas'] as $metadata) {
            if (!in_array($metadata['meta_id'], array_keys($toupdate)))
                continue;
            $saved_value = $toupdate[$metadata['meta_structure_id']]['value'];
            $this->assertEquals($saved_value, $metadata['value']);
        }
    }

    /**
     * @covers \API_V1_adapter::set_record_status
     * @covers \API_V1_adapter::list_record_status
     */
    public function testRecordsSetStatus()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/records/' . self::$DI['record_1']->get_sbas_id() . '/' . self::$DI['record_1']->get_record_id() . '/setstatus/';

        $record_status = strrev(self::$DI['record_1']->get_status());
        $status_bits = self::$DI['record_1']->get_databox()->get_statusbits();

        $tochange = [];
        foreach ($status_bits as $n => $datas) {
            $tochange[$n] = substr($record_status, ($n - 1), 1) == '0' ? '1' : '0';
        }
        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(['status' => $tochange]), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        /**
         * Get fresh record_1
         */
        $testRecord = new \record_adapter(self::$DI['app'], self::$DI['record_1']->get_sbas_id(), self::$DI['record_1']->get_record_id());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->evaluateRecordsStatusResponse($testRecord, $content);

        $record_status = strrev($testRecord->get_status());
        foreach ($status_bits as $n => $datas) {
            $this->assertEquals(substr($record_status, ($n), 1), $tochange[$n]);
        }

        foreach ($tochange as $n => $value) {
            $tochange[$n] = $value == '0' ? '1' : '0';
        }

        self::$DI['client']->request('POST', $route, $this->getParameters(['status' => $tochange]), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        /**
         * Get fresh record_1
         */
        $testRecord = new \record_adapter(self::$DI['app'], $testRecord->get_sbas_id(), $testRecord->get_record_id());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->evaluateRecordsStatusResponse($testRecord, $content);

        $record_status = strrev($testRecord->get_status());
        foreach ($status_bits as $n => $datas) {
            $this->assertEquals(substr($record_status, ($n), 1), $tochange[$n]);
        }

        self::$DI['record_1']->set_binary_status(str_repeat('0', 32));
    }

    /**
     * @covers \API_V1_adapter::set_record_collection
     */
    public function testMoveRecordToCollection()
    {
        $file = new File(self::$DI['app'], self::$DI['app']['mediavorus']->guess(__DIR__ . '/../../../../files/test001.jpg'), self::$DI['collection']);
        $record = \record_adapter::createFromFile($file, self::$DI['app']);

        $this->setToken(self::$token);

        $route = '/api/v1/records/' . $record->get_sbas_id() . '/' . $record->get_record_id() . '/setcollection/';

        $base_id = false;
        foreach ($record->get_databox()->get_collections() as $collection) {
            if ($collection->get_base_id() != $record->get_base_id()) {
                $base_id = $collection->get_base_id();
                break;
            }
        }
        if (!$base_id) {
            $this->markTestSkipped('No collection');
        }

        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(['base_id' => $base_id]), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $record->delete();
    }

    /**
     * @covers \API_V1_adapter::search_baskets
     * @covers \API_V1_adapter::list_baskets
     * @covers \API_V1_adapter::list_basket
     */
    public function testSearchBaskets()
    {
        self::$DI['client'] = new Client(self::$DI['app'], []);

        $this->setToken(self::$adminToken);
        $route = '/api/v1/baskets/list/';
        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);
        $this->assertArrayHasKey("baskets", $content['response']);

        foreach ($content['response']['baskets'] as $basket) {
            $this->evaluateGoodBasket($basket);
        }
    }

    /**
     * @covers \API_V1_adapter::create_basket
     * @covers \API_V1_adapter::list_basket
     */
    public function testAddBasket()
    {
        $this->setToken(self::$token);

        $route = '/api/v1/baskets/add/';

        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(['name' => 'un Joli Nom']), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertEquals(1, count($content['response']));
        $this->assertArrayHasKey("basket", $content['response']);
        $this->evaluateGoodBasket($content['response']['basket']);
        $this->assertEquals('un Joli Nom', $content['response']['basket']['name']);
    }

    /**
     * @covers \API_V1_adapter::get_basket
     * @covers \API_V1_adapter::list_basket_content
     * @covers \API_V1_adapter::list_basket_element
     */
    public function testBasketContent()
    {
        $this->setToken(self::$adminToken);

        $basketElement = self::$DI['app']['EM']->find('Phraseanet:BasketElement', 1);
        $basket = $basketElement->getBasket();

        $route = '/api/v1/baskets/' . $basket->getId() . '/content/';

        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertEquals(2, count((array) $content['response']));

        $this->assertArrayHasKey("basket_elements", $content['response']);
        $this->assertArrayHasKey("basket", $content['response']);
        $this->evaluateGoodBasket($content['response']['basket']);

        foreach ($content['response']['basket_elements'] as $basket_element) {
            $this->assertArrayHasKey('basket_element_id', $basket_element);
            $this->assertArrayHasKey('order', $basket_element);
            $this->assertArrayHasKey('record', $basket_element);
            $this->assertArrayHasKey('validation_item', $basket_element);
            $this->assertTrue(is_bool($basket_element['validation_item']));
            $this->assertTrue(is_int($basket_element['order']));
            $this->assertTrue(is_int($basket_element['basket_element_id']));
            $this->evaluateGoodRecord($basket_element['record']);
        }
    }

    /**
     * @covers \API_V1_adapter::set_basket_title
     * @covers \API_V1_adapter::list_basket_content
     * @covers \API_V1_adapter::list_basket_element
     */
    public function testSetBasketTitle()
    {
        $this->setToken(self::$adminToken);

        $basket = self::$DI['app']['EM']->find('Phraseanet:Basket', 1);

        $route = '/api/v1/baskets/' . $basket->getId() . '/setname/';

        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(['name' => 'un Joli Nom']), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertEquals(1, count((array) $content['response']));
        $this->assertArrayHasKey("basket", $content['response']);
        $this->evaluateGoodBasket($content['response']['basket']);

        $this->assertEquals($content['response']['basket']['name'], 'un Joli Nom');

        self::$DI['client']->request('POST', $route, $this->getParameters(['name' => 'un Joli Nom']), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertEquals(1, count((array) $content['response']));

        $this->assertArrayHasKey("basket", $content['response']);

        $this->evaluateGoodBasket($content['response']['basket']);

        $this->assertEquals($content['response']['basket']['name'], 'un Joli Nom');

        self::$DI['client']->request('POST', $route, $this->getParameters(['name' => '<strong>aéaa']), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertEquals(1, count((array) $content['response']));
        $this->assertArrayHasKey("basket", $content['response']);
        $this->evaluateGoodBasket($content['response']['basket']);
        $this->assertEquals($content['response']['basket']['name'], '<strong>aéaa');
    }

    /**
     * @covers \API_V1_adapter::set_basket_description
     * @covers \API_V1_adapter::list_basket_content
     * @covers \API_V1_adapter::list_basket_element
     */
    public function testSetBasketDescription()
    {
        $this->setToken(self::$adminToken);

        $basket = self::$DI['app']['EM']->find('Phraseanet:Basket', 1);

        $route = '/api/v1/baskets/' . $basket->getId() . '/setdescription/';

        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(['description' => 'une belle desc']), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertEquals(1, count((array) $content['response']));

        $this->assertArrayHasKey("basket", $content['response']);
        $this->evaluateGoodBasket($content['response']['basket']);
        $this->assertEquals($content['response']['basket']['description'], 'une belle desc');
    }

    /**
     * @covers \API_V1_adapter::delete_basket
     */
    public function testDeleteBasket()
    {
        $this->setToken(self::$adminToken);
        $route = '/api/v1/baskets/1/delete/';
        $this->evaluateMethodNotAllowedRoute($route, ['GET', 'PUT', 'DELETE']);

        self::$DI['client']->request('POST', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey("baskets", $content['response']);

        $found = false;
        foreach ($content['response']['baskets'] as $basket) {
            $this->evaluateGoodBasket($basket);
            $found = true;
            break;
        }
        if (!$found) {
            $this->fail('There should be four baskets left');
        }
    }

    /**
     * @covers \API_V1_adapter::add_record
     * @covers \API_V1_adapter::list_record
     */
    public function testAddRecord()
    {
        $this->setToken(self::$token);
        $route = '/api/v1/records/add/';

        $params = $this->getAddRecordParameters();
        $params['status'] = '0b10000';

        self::$DI['client']->request('POST', $route, $this->getParameters($params), $this->getAddRecordFile(), ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);
        $datas = $content['response'];

        $this->assertArrayHasKey('entity', $datas);
        $this->assertArrayHasKey('url', $datas);
    }

    /**
     * @covers \API_V1_adapter::add_record
     * @covers \API_V1_adapter::list_record
     */
    public function testAddRecordForceRecord()
    {
        $this->setToken(self::$token);
        $route = '/api/v1/records/add/';

        $params = $this->getAddRecordParameters();
        $params['forceBehavior'] = '0';

        self::$DI['client']->request('POST', $route, $this->getParameters($params), $this->getAddRecordFile(), ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $datas = $content['response'];

        $this->assertArrayHasKey('entity', $datas);
        $this->assertArrayHasKey('url', $datas);
        $this->assertRegExp('/\/records\/\d+\/\d+\//', $datas['url']);

        // if forced, there is no reason
        $this->assertEquals('0', $datas['entity']);
    }

    /**
     * @covers \API_V1_adapter::add_record
     * @covers \API_V1_adapter::list_quarantine_item
     */
    public function testAddRecordForceLazaret()
    {
        $this->setToken(self::$token);
        $route = '/api/v1/records/add/';

        $params = $this->getAddRecordParameters();
        $params['forceBehavior'] = '1';

        self::$DI['client']->request('POST', $route, $this->getParameters($params), $this->getAddRecordFile(), ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
        $datas = $content['response'];

        $this->assertArrayHasKey('entity', $datas);
        $this->assertArrayHasKey('url', $datas);
        $this->assertRegExp('/\/quarantine\/item\/\d+\//', $datas['url']);

        $this->assertEquals('1', $datas['entity']);
    }

    /**
     * @covers \API_V1_adapter::add_record
     */
    public function testAddRecordWrongBehavior()
    {
        $this->setToken(self::$token);
        $route = '/api/v1/records/add/';

        $params = $this->getAddRecordParameters();
        $params['forceBehavior'] = '2';

        self::$DI['client']->request('POST', $route, $this->getParameters($params), $this->getAddRecordFile(), ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponseBadRequest(self::$DI['client']->getResponse());
        $this->evaluateMetaBadRequest($content);
    }

    /**
     * @covers \API_V1_adapter::add_record
     */
    public function testAddRecordWrongBaseId()
    {
        $this->setToken(self::$adminToken);
        $route = '/api/v1/records/add/';

        $params = $this->getAddRecordParameters();
        $params['base_id'] = self::$DI['collection_no_access']->get_base_id();

        self::$DI['client']->request('POST', $route, $this->getParameters($params), $this->getAddRecordFile(), ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponseForbidden(self::$DI['client']->getResponse());
        $this->evaluateMetaForbidden($content);
    }

    /**
     * @covers \API_V1_adapter::add_record
     */
    public function testAddRecordNoBaseId()
    {
        $this->setToken(self::$token);
        $route = '/api/v1/records/add/';

        $params = $this->getAddRecordParameters();
        unset($params['base_id']);

        self::$DI['client']->request('POST', $route, $this->getParameters($params), $this->getAddRecordFile(), ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponseBadRequest(self::$DI['client']->getResponse());
        $this->evaluateMetaBadRequest($content);
    }

    /**
     * @covers \API_V1_adapter::add_record
     */
    public function testAddRecordMultipleFiles()
    {
        $this->setToken(self::$token);
        $route = '/api/v1/records/add/';

        $file = [
            new \Symfony\Component\HttpFoundation\File\UploadedFile(__FILE__, 'upload.txt'),
            new \Symfony\Component\HttpFoundation\File\UploadedFile(__FILE__, 'upload.txt'),
        ];

        self::$DI['client']->request('POST', $route, $this->getParameters($this->getAddRecordParameters()), ['file' => $file], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponseBadRequest(self::$DI['client']->getResponse());
        $this->evaluateMetaBadRequest($content);
    }

    public function testAddRecordNofile()
    {
        $this->setToken(self::$token);
        $route = '/api/v1/records/add/';

        self::$DI['client']->request('POST', $route, $this->getParameters($this->getAddRecordParameters()), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponseBadRequest(self::$DI['client']->getResponse());
        $this->evaluateMetaBadRequest($content);
    }

    /**
     * @covers \API_V1_adapter::search_publications
     * @covers \API_V1_adapter::list_publication
     */
    public function testFeedList()
    {
        $created_feed = self::$DI['app']['EM']->find('Phraseanet:Feed', 1);

        $this->setToken(self::$token);
        $route = '/api/v1/feeds/list/';

        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('feeds', $content['response']);

        $found = false;
        foreach ($content['response']['feeds'] as $feed) {

            $this->evaluateGoodFeed($feed);

            if ($feed['id'] == $created_feed->getId()) {
                $found = true;
                $this->assertEquals('Feed test, YOLO!', $feed['title']);
                break;
            }
        }

        if (!$found) {
            $this->fail('feed not found !');
        }
    }

    /**
     * @covers \API_V1_adapter::get_publications
     * @covers \API_V1_adapter::list_publications_entries
     * @covers \API_V1_adapter::list_publication_entry
     * @covers \API_V1_adapter::list_publication_entry_item
     * @covers \API_V1_adapter::list_record
     */
    public function testFeedsContent()
    {
        self::$DI['app']['notification.deliverer'] = $this->getMockBuilder('Alchemy\Phrasea\Notification\Deliverer')
            ->disableOriginalConstructor()
            ->getMock();

        $entry_title = 'Superman';
        $entry_subtitle = 'Wonder Woman';
        $author = "W. Shakespeare";
        $author_email = "gontran.bonheur@gmail.com";

        $feed = self::$DI['app']['EM']->find('Phraseanet:Feed', 1);
        $created_entry = $feed->getEntries()->first();

        $created_entry->setAuthorEmail($author_email);
        $created_entry->setAuthorName($author);
        $created_entry->setTitle($entry_title);
        $created_entry->setSubtitle($entry_subtitle);
        self::$DI['app']['EM']->persist($created_entry);
        self::$DI['app']['EM']->flush();

        $this->setToken(self::$token);
        $route = '/api/v1/feeds/content/';

        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('total_entries', $content['response']);
        $this->assertArrayHasKey('offset_start', $content['response']);
        $this->assertArrayHasKey('per_page', $content['response']);
        $this->assertArrayHasKey('entries', $content['response']);

        $found = false;

        foreach ($content['response']['entries'] as $entry) {
            $this->assertGoodEntry($entry);

            if ($entry['id'] == $created_entry->getId()) {
                $found = true;
                $this->assertEquals($author_email, $entry['author_email']);
                $this->assertEquals($author, $entry['author_name']);
                $this->assertEquals($entry_title, $entry['title']);
                $this->assertEquals($entry_subtitle, $entry['subtitle']);
                break;
            }
        }

        if (!$found) {
            $this->fail('entry not found !');
        }
    }

    /**
     * @covers \API_V1_adapter::get_feed_entry
     * @covers \API_V1_adapter::list_publication_entry
     */
    public function testFeedEntry()
    {
        self::$DI['app']['notification.deliverer'] = $this->getMockBuilder('Alchemy\Phrasea\Notification\Deliverer')
            ->disableOriginalConstructor()
            ->getMock();

        $feed = self::$DI['app']['EM']->find('Phraseanet:Feed', 1);
        $created_entry = $feed->getEntries()->first();

        $this->setToken(self::$token);
        $route = '/api/v1/feeds/entry/' . $created_entry->getId() . '/';

        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('entry', $content['response']);
        $this->assertGoodEntry($content['response']['entry']);

        $this->assertEquals($created_entry->getId(), $content['response']['entry']['id']);

    }

    /**
     * @covers \API_V1_adapter::get_feed_entry
     * @covers \API_V1_adapter::list_publication_entry
     */
    public function testFeedEntryNoAccess()
    {
        self::$DI['app']['notification.deliverer'] = $this->getMockBuilder('Alchemy\Phrasea\Notification\Deliverer')
            ->disableOriginalConstructor()
            ->getMock();

        $created_feed = self::$DI['app']['EM']->find('Phraseanet:Feed', 1);
        $created_entry = $created_feed->getEntries()->first();

        $created_feed->setCollection(self::$DI['collection_no_access']);

        $this->setToken(self::$adminToken);
        $route = '/api/v1/feeds/entry/' . $created_entry->getId() . '/';

        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponseForbidden(self::$DI['client']->getResponse());
        $this->evaluateMetaForbidden($content);
    }

    /**
     * @covers \API_V1_adapter::get_publication
     * @covers \API_V1_adapter::list_publications_entries
     * @covers \API_V1_adapter::list_publication_entry
     * @covers \API_V1_adapter::list_publication_entry_item
     * @covers \API_V1_adapter::list_record
     */
    public function testFeedContent()
    {
        self::$DI['app']['notification.deliverer'] = $this->getMockBuilder('Alchemy\Phrasea\Notification\Deliverer')
            ->disableOriginalConstructor()
            ->getMock();

        $entry_title = 'Superman';
        $entry_subtitle = 'Wonder Woman';

        $created_feed = self::$DI['app']['EM']->find('Phraseanet:Feed', 1);
        $created_entry = $created_feed->getEntries()->first();
        $created_entry->setTitle($entry_title);
        $created_entry->setSubtitle($entry_subtitle);
        self::$DI['app']['EM']->persist($created_entry);
        self::$DI['app']['EM']->flush();

        $this->setToken(self::$token);
        $route = '/api/v1/feeds/' . $created_feed->getId() . '/content/';

        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->evaluateResponse200(self::$DI['client']->getResponse());
        $this->evaluateMeta200($content);

        $this->assertArrayHasKey('feed', $content['response']);
        $this->assertArrayHasKey('entries', $content['response']);
        $this->evaluateGoodFeed($content['response']['feed']);

        $found = false;
        foreach ($content['response']['entries'] as $entry) {
            $this->assertGoodEntry($entry);

            if ($entry['id'] == $created_entry->getId()) {
                $this->assertEquals($entry_title, $entry['title']);
                $this->assertEquals($entry_subtitle, $entry['subtitle']);
                $found = true;
                break;
            }
        }

        $this->assertEquals($created_feed->getId(), $content['response']['feed']['id']);

        if (!$found) {
            $this->fail('Entry not found');
        }
    }

    /**
     * @covers list_quarantine
     * @covers list_quarantine_item
     */
    public function testQuarantineList()
    {
        $this->setToken(self::$token);
        $route = '/api/v1/quarantine/list/';

        $quarantineItemId = self::$DI['lazaret_1']->getId();

        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->assertArrayHasKey('offset_start', $content['response']);
        $this->assertArrayHasKey('per_page', $content['response']);
        $this->assertArrayHasKey('quarantine_items', $content['response']);

        $found = false;

        foreach ($content['response']['quarantine_items'] as $item) {
            $this->evaluateGoodQuarantineItem($item);
            if ($item['id'] == $quarantineItemId) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->fail('should find the quarantine item');
        }
    }

    /**
     * @covers list_quarantine_item
     */
    public function testQuarantineContent()
    {
        $this->setToken(self::$token);

        $quarantineItemId = self::$DI['lazaret_1']->getId();
        $route = '/api/v1/quarantine/item/' . $quarantineItemId . '/';

        $this->evaluateMethodNotAllowedRoute($route, ['POST', 'PUT', 'DELETE']);

        self::$DI['client']->request('GET', $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
        $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

        $this->assertArrayHasKey('quarantine_item', $content['response']);

        $this->evaluateGoodQuarantineItem($content['response']['quarantine_item']);
        $this->assertEquals($quarantineItemId, $content['response']['quarantine_item']['id']);
    }

    protected function getQuarantineItem()
    {
        $lazaretSession = new LazaretSession();
        self::$DI['app']['EM']->persist($lazaretSession);

        $quarantineItem = null;
        $callback = function ($element, $visa, $code) use (&$quarantineItem) {
                $quarantineItem = $element;
            };

        $tmpname = tempnam(sys_get_temp_dir(), 'test_quarantine');
        copy(__DIR__ . '/../../../../files/iphone_pic.jpg', $tmpname);

        $file = File::buildFromPathfile($tmpname, self::$DI['collection'], self::$DI['app']);
        self::$DI['app']['border-manager']->process($lazaretSession, $file, $callback, Manager::FORCE_LAZARET);

        return $quarantineItem;
    }

    protected function evaluateGoodQuarantineItem($item)
    {
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('quarantine_session', $item);
        $this->assertArrayHasKey('base_id', $item);
        $this->assertArrayHasKey('original_name', $item);
        $this->assertArrayHasKey('sha256', $item);
        $this->assertArrayHasKey('uuid', $item);
        $this->assertArrayHasKey('forced', $item);
        $this->assertArrayHasKey('checks', $item);
        $this->assertArrayHasKey('created_on', $item);
        $this->assertArrayHasKey('updated_on', $item);

        $this->assertInternalType('boolean', $item['forced']);
        $this->assertDateAtom($item['updated_on']);
        $this->assertDateAtom($item['created_on']);
    }

    protected function evaluateGoodFeed($feed)
    {
        $this->assertArrayHasKey('id', $feed);
        $this->assertArrayHasKey('title', $feed);
        $this->assertArrayHasKey('subtitle', $feed);
        $this->assertArrayHasKey('total_entries', $feed);
        $this->assertArrayHasKey('icon', $feed);
        $this->assertArrayHasKey('public', $feed);
        $this->assertArrayHasKey('readonly', $feed);
        $this->assertArrayHasKey('deletable', $feed);
        $this->assertArrayHasKey('created_on', $feed);
        $this->assertArrayHasKey('updated_on', $feed);

        $this->assertInternalType('integer', $feed['id']);
        $this->assertInternalType('string', $feed['title']);
        $this->assertInternalType('string', $feed['subtitle']);
        $this->assertInternalType('integer', $feed['total_entries']);
        $this->assertInternalType('boolean', $feed['icon']);
        $this->assertInternalType('boolean', $feed['public']);
        $this->assertInternalType('boolean', $feed['readonly']);
        $this->assertInternalType('boolean', $feed['deletable']);
        $this->assertInternalType('string', $feed['created_on']);
        $this->assertInternalType('string', $feed['updated_on']);

        $this->assertDateAtom($feed['created_on']);
        $this->assertDateAtom($feed['updated_on']);
    }

    protected function assertGoodEntry($entry)
    {
        $this->assertArrayHasKey('id', $entry);
        $this->assertArrayHasKey('author_email', $entry);
        $this->assertArrayHasKey('author_name', $entry);
        $this->assertArrayHasKey('created_on', $entry);
        $this->assertArrayHasKey('updated_on', $entry);
        $this->assertArrayHasKey('title', $entry);
        $this->assertArrayHasKey('subtitle', $entry);
        $this->assertArrayHasKey('items', $entry);
        $this->assertArrayHasKey('url', $entry);
        $this->assertArrayHasKey('feed_url', $entry);

        $this->assertInternalType('string', $entry['author_email']);
        $this->assertInternalType('string', $entry['author_name']);
        $this->assertDateAtom($entry['created_on']);
        $this->assertDateAtom($entry['updated_on']);
        $this->assertInternalType('string', $entry['title']);
        $this->assertInternalType('string', $entry['subtitle']);
        $this->assertInternalType('array', $entry['items']);

        foreach ($entry['items'] as $item) {
            $this->assertInternalType('integer', $item['item_id']);
            $this->evaluateGoodRecord($item['record']);
        }

        $this->assertRegExp('/\/feeds\/entry\/[0-9]+\//', $entry['url']);
        $this->assertRegExp('/\/feeds\/[0-9]+\/content\//', $entry['feed_url']);
    }

    protected function getAddRecordParameters()
    {
        return [
            'base_id' => self::$DI['collection']->get_base_id()
        ];
    }

    protected function getAddRecordFile()
    {
        $file = tempnam(sys_get_temp_dir(), 'upload');
        copy(__DIR__ . '/../../../../files/iphone_pic.jpg', $file);

        return [
            'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($file, 'upload.jpg')
        ];
    }

    protected function checkLazaretFile($file)
    {
        $this->assertArrayHasKey('id', $file);
        $this->assertArrayHasKey('session', $file);
        $this->assertArrayHasKey('base_id', $file);
        $this->assertArrayHasKey('original_name', $file);
        $this->assertArrayHasKey('sha256', $file);
        $this->assertArrayHasKey('uuid', $file);
        $this->assertArrayHasKey('forced', $file);
        $this->assertArrayHasKey('checks', $file);
        $this->assertArrayHasKey('created_on', $file);
        $this->assertArrayHasKey('updated_on', $file);

        $this->assertInternalType('integer', $file['id']);
        $this->assertInternalType('array', $file['session']);
        $this->assertInternalType('integer', $file['base_id']);
        $this->assertInternalType('string', $file['original_name']);
        $this->assertInternalType('string', $file['sha256']);
        $this->assertInternalType('string', $file['uuid']);
        $this->assertInternalType('boolean', $file['forced']);
        $this->assertInternalType('array', $file['checks']);
        $this->assertInternalType('string', $file['updated_on']);
        $this->assertInternalType('string', $file['created_on']);

        $this->assertArrayHasKey('id', $file['session']);
        $this->assertArrayHasKey('usr_id', $file['session']);

        $this->assertRegExp('/[a-f0-9]{64}/i', $file['sha256']);
        $this->assertRegExp('/[a-f0-9-]+/i', $file['uuid']);

        foreach ($file['checks'] as $check) {
            $this->assertInternalType('string', $check);
        }

        $this->assertDateAtom($file['updated_on']);
        $this->assertDateAtom($file['created_on']);
    }

    protected function evaluateNotFoundRoute($route, $methods)
    {
        foreach ($methods as $method) {
            self::$DI['client']->request($method, $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
            $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());

            $this->evaluateResponseNotFound(self::$DI['client']->getResponse());
            $this->evaluateMetaNotFound($content);
        }
    }

    protected function checkEmbed($embed, \record_adapter $record)
    {
        if ($embed['filesize'] === 0) {
            var_dump($embed);
        }
        $subdef = $record->get_subdef($embed['name']);
        $this->assertArrayHasKey("name", $embed);
        $this->assertArrayHasKey("permalink", $embed);
        $this->checkPermalink($embed['permalink'], $subdef);
        $this->assertArrayHasKey("height", $embed);
        $this->assertEquals($embed['height'], $subdef->get_height());
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_INT, $embed['height']);
        $this->assertArrayHasKey("width", $embed);
        $this->assertEquals($embed['width'], $subdef->get_width());
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_INT, $embed['width']);
        $this->assertArrayHasKey("filesize", $embed);
        $this->assertEquals($embed['filesize'], $subdef->get_size());
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_INT, $embed['filesize']);
        $this->assertArrayHasKey("player_type", $embed);
        $this->assertEquals($embed['player_type'], $subdef->get_type());
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_STRING, $embed['player_type']);
        $this->assertArrayHasKey("mime_type", $embed);
        $this->assertEquals($embed['mime_type'], $subdef->get_mime());
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_STRING, $embed['mime_type']);
        $this->assertArrayHasKey("devices", $embed);
        $this->assertEquals($embed['devices'], $subdef->getDevices());
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_ARRAY, $embed['devices']);
    }

    protected function checkPermalink($permalink, \media_subdef $subdef)
    {
        if (!$subdef->is_physically_present()) {
            return;
        }
        $start = microtime(true);
        $this->assertNotNull($subdef->get_permalink());
        $this->assertInternalType('array', $permalink);
        $this->assertArrayHasKey("created_on", $permalink);
        $now = new \Datetime($permalink['created_on']);
        $interval = $now->diff($subdef->get_permalink()->get_created_on());
        $this->assertTrue(abs($interval->format('U')) < 2);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_STRING, $permalink['created_on']);
        $this->assertDateAtom($permalink['created_on']);
        $this->assertArrayHasKey("id", $permalink);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_INT, $permalink['id']);
        $this->assertEquals($subdef->get_permalink()->get_id(), $permalink['id']);
        $this->assertArrayHasKey("is_activated", $permalink);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_BOOL, $permalink['is_activated']);
        $this->assertEquals($subdef->get_permalink()->get_is_activated(), $permalink['is_activated']);
        $this->assertArrayHasKey("label", $permalink);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_STRING, $permalink['label']);
        $this->assertArrayHasKey("updated_on", $permalink);

        $expected = $subdef->get_permalink()->get_last_modified();
        $found = \DateTime::createFromFormat(DATE_ATOM, $permalink['updated_on']);

        $this->assertLessThanOrEqual(1, $expected->diff($found)->format('U'));
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_STRING, $permalink['updated_on']);
        $this->assertDateAtom($permalink['updated_on']);
        $this->assertArrayHasKey("page_url", $permalink);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_STRING, $permalink['page_url']);
        $this->assertEquals($subdef->get_permalink()->get_page(), $permalink['page_url']);
        $this->checkUrlCode200($permalink['page_url']);
        $this->assertPermalinkHeaders($permalink['page_url'], $subdef);

        $this->assertArrayHasKey("url", $permalink);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_STRING, $permalink['url']);
        $this->assertEquals($subdef->get_permalink()->get_url(), $permalink['url']);
        $this->checkUrlCode200($permalink['url']);
        $this->assertPermalinkHeaders($permalink['url'], $subdef, "url");

        $this->assertArrayHasKey("download_url", $permalink);
        $this->assertInternalType(\PHPUnit_Framework_Constraint_IsType::TYPE_STRING, $permalink['download_url']);
        $this->assertEquals($subdef->get_permalink()->get_url() . '&download', $permalink['download_url']);
        $this->checkUrlCode200($permalink['download_url']);
        $this->assertPermalinkHeaders($permalink['download_url'], $subdef, "download_url");
    }

    private function executeRequest($url)
    {
        static $request = [];

        if (isset($request[$url])) {
            return $request[$url];
        }

        static $webserver;

        if (null === $webserver) {
            try {
                $code = self::$DI['local-guzzle']->head('/api/')->send()->getStatusCode();
            } catch (GuzzleException $e) {
                $code = null;
            }
            $webserver = ($code < 200 || $code >= 400) ? false : rtrim(self::$DI['app']['conf']->get('servername'), '/');
        }
        if (false === $webserver) {
            $this->markTestSkipped('Install does not seem to rely on a webserver');
        }
        if (0 === strpos($url, $webserver)) {
            $url = substr($url, strlen($webserver));
        }

        return $request[$url] = self::$DI['local-guzzle']->head($url)->send();
    }

    protected function assertPermalinkHeaders($url, \media_subdef $subdef, $type_url = "page_url")
    {
        $response = $this->executeRequest($url);

        $this->assertEquals(200, $response->getStatusCode());

        switch ($type_url) {
            case "page_url" :
                $this->assertTrue(strpos((string) $response->getHeader('content-type'), "text/html") === 0);
                if ($response->hasHeader('content-length')) {
                    $this->assertNotEquals($subdef->get_size(), (string) $response->getHeader('content-length'));
                }
                break;
            case "url" :
                $this->assertTrue(strpos((string) $response->getHeader('content-type'), $subdef->get_mime()) === 0, 'Verify that header ' . (string) $response->getHeader('content-type') . ' contains subdef mime type ' . $subdef->get_mime());
                if ($response->hasHeader('content-length')) {
                    $this->assertEquals($subdef->get_size(), (string) $response->getHeader('content-length'));
                }
                break;
            case "download_url" :
                $this->assertTrue(strpos((string) $response->getHeader('content-type'), $subdef->get_mime()) === 0, 'Verify that header ' . (string) $response->getHeader('content-type') . ' contains subdef mime type ' . $subdef->get_mime());
                if ($response->hasHeader('content-length')) {
                    $this->assertEquals($subdef->get_size(), (string) $response->getHeader('content-length'));
                }
                break;
        }
    }

    protected function checkUrlCode200($url)
    {
        $response = $this->executeRequest($url);
        $code = $response->getStatusCode();
        $this->assertEquals(200, $code, sprintf('verification de url %s', $url));
    }

    protected function evaluateMethodNotAllowedRoute($route, $methods)
    {
        foreach ($methods as $method) {
            self::$DI['client']->request($method, $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
            $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
            $this->assertTrue(self::$DI['client']->getResponse()->headers->has('Allow'));
            $this->evaluateResponseMethodNotAllowed(self::$DI['client']->getResponse());
            $this->evaluateMetaMethodNotAllowed($content);
        }
    }

    protected function evaluateBadRequestRoute($route, $methods)
    {
        foreach ($methods as $method) {
            self::$DI['client']->request($method, $route, $this->getParameters(), [], ['HTTP_Accept' => $this->getAcceptMimeType()]);
            $content = $this->unserialize(self::$DI['client']->getResponse()->getContent());
            $this->evaluateResponseBadRequest(self::$DI['client']->getResponse());
            $this->evaluateMetaBadRequest($content);
        }
    }

    protected function evaluateMeta($content)
    {
        $this->assertTrue(is_array($content), 'La reponse est un objet');
        $this->assertArrayHasKey('meta', $content);
        $this->assertArrayHasKey('response', $content);
        $this->assertTrue(is_array($content['meta']), 'Le bloc meta est un array');
        $this->assertTrue(is_array($content['response']), 'Le bloc reponse est un array');
        $this->assertEquals('1.3', $content['meta']['api_version']);
        $this->assertNotNull($content['meta']['response_time']);
        $this->assertEquals('UTF-8', $content['meta']['charset']);
    }

    protected function evaluateMeta200($content)
    {
        $this->evaluateMeta($content);
        $this->assertEquals(200, $content['meta']['http_code']);
        $this->assertNull($content['meta']['error_type']);
        $this->assertNull($content['meta']['error_message']);
        $this->assertNull($content['meta']['error_details']);
    }

    protected function evaluateMetaBadRequest($content)
    {
        $this->evaluateMeta($content);
        $this->assertNotNull($content['meta']['error_type']);
        $this->assertNotNull($content['meta']['error_message']);
        $this->assertEquals(400, $content['meta']['http_code']);
    }

    protected function evaluateMetaForbidden($content)
    {
        $this->evaluateMeta($content);
        $this->assertNotNull($content['meta']['error_type']);
        $this->assertNotNull($content['meta']['error_message']);
        $this->assertEquals(403, $content['meta']['http_code']);
    }

    protected function evaluateMetaNotFound($content)
    {
        $this->evaluateMeta($content);
        $this->assertNotNull($content['meta']['error_type']);
        $this->assertNotNull($content['meta']['error_message']);
        $this->assertEquals(404, $content['meta']['http_code']);
    }

    protected function evaluateMetaMethodNotAllowed($content)
    {
        $this->evaluateMeta($content);
        $this->assertNotNull($content['meta']['error_type']);
        $this->assertNotNull($content['meta']['error_message']);
        $this->assertEquals(405, $content['meta']['http_code']);
    }

    protected function evaluateResponse200(Response $response)
    {
        $this->assertEquals('UTF-8', $response->getCharset(), 'Test charset response');
        $this->assertEquals(200, $response->getStatusCode(), 'Test status code 200 ' . $response->getContent());
    }

    protected function evaluateResponseBadRequest(Response $response)
    {
        $this->assertEquals('UTF-8', $response->getCharset(), 'Test charset response');
        $this->assertEquals(400, $response->getStatusCode(), 'Test status code 400 ' . $response->getContent());
    }

    protected function evaluateResponseForbidden(Response $response)
    {
        $this->assertEquals('UTF-8', $response->getCharset(), 'Test charset response');
        $this->assertEquals(403, $response->getStatusCode(), 'Test status code 403 ' . $response->getContent());
    }

    protected function evaluateResponseNotFound(Response $response)
    {
        $this->assertEquals('UTF-8', $response->getCharset(), 'Test charset response');
        $this->assertEquals(404, $response->getStatusCode(), 'Test status code 404 ' . $response->getContent());
    }

    protected function evaluateResponseMethodNotAllowed(Response $response)
    {
        $this->assertEquals('UTF-8', $response->getCharset(), 'Test charset response');
        $this->assertEquals(405, $response->getStatusCode(), 'Test status code 405 ' . $response->getContent());
    }

    protected function evaluateGoodBasket($basket)
    {
        $this->assertTrue(is_array($basket));
        $this->assertArrayHasKey('created_on', $basket);
        $this->assertArrayHasKey('description', $basket);
        $this->assertArrayHasKey('name', $basket);
        $this->assertArrayHasKey('pusher_usr_id', $basket);
        $this->assertArrayHasKey('updated_on', $basket);
        $this->assertArrayHasKey('unread', $basket);

        if (!is_null($basket['pusher_usr_id'])) {
            $this->assertTrue(is_int($basket['pusher_usr_id']));
        }

        $this->assertTrue(is_string($basket['name']));
        $this->assertTrue(is_string($basket['description']));
        $this->assertTrue(is_bool($basket['unread']));
        $this->assertDateAtom($basket['created_on']);
        $this->assertDateAtom($basket['updated_on']);
    }

    protected function evaluateGoodRecord($record)
    {
        $this->assertArrayHasKey('databox_id', $record);
        $this->assertTrue(is_int($record['databox_id']));
        $this->assertArrayHasKey('record_id', $record);
        $this->assertTrue(is_int($record['record_id']));
        $this->assertArrayHasKey('mime_type', $record);
        $this->assertTrue(is_string($record['mime_type']));
        $this->assertArrayHasKey('title', $record);
        $this->assertTrue(is_string($record['title']));
        $this->assertArrayHasKey('original_name', $record);
        $this->assertTrue(is_string($record['original_name']));
        $this->assertArrayHasKey('updated_on', $record);
        $this->assertDateAtom($record['updated_on']);
        $this->assertArrayHasKey('created_on', $record);
        $this->assertDateAtom($record['created_on']);
        $this->assertArrayHasKey('collection_id', $record);
        $this->assertTrue(is_int($record['collection_id']));
        $this->assertArrayHasKey('thumbnail', $record);
        $this->assertArrayHasKey('sha256', $record);
        $this->assertTrue(is_string($record['sha256']));
        $this->assertArrayHasKey('technical_informations', $record);
        $this->assertArrayHasKey('phrasea_type', $record);
        $this->assertTrue(is_string($record['phrasea_type']));
        $this->assertTrue(in_array($record['phrasea_type'], ['audio', 'document', 'image', 'video', 'flash', 'unknown']));
        $this->assertArrayHasKey('uuid', $record);
        $this->assertTrue(\uuid::is_valid($record['uuid']));

        if (!is_null($record['thumbnail'])) {
            $this->assertTrue(is_array($record['thumbnail']));
            $this->assertArrayHasKey('player_type', $record['thumbnail']);
            $this->assertTrue(is_string($record['thumbnail']['player_type']));
            $this->assertArrayHasKey('permalink', $record['thumbnail']);
            $this->assertArrayHasKey('mime_type', $record['thumbnail']);
            $this->assertTrue(is_string($record['thumbnail']['mime_type']));
            $this->assertArrayHasKey('height', $record['thumbnail']);
            $this->assertTrue(is_int($record['thumbnail']['height']));
            $this->assertArrayHasKey('width', $record['thumbnail']);
            $this->assertTrue(is_int($record['thumbnail']['width']));
            $this->assertArrayHasKey('filesize', $record['thumbnail']);
            $this->assertTrue(is_int($record['thumbnail']['filesize']));
        }

        $this->assertTrue(is_array($record['technical_informations']));

        foreach ($record['technical_informations'] as $technical) {
            $this->assertArrayHasKey('value', $technical);
            $this->assertArrayHasKey('name', $technical);

            $value = $technical['value'];
            if (is_string($value)) {
                $this->assertFalse(ctype_digit($value));
                $this->assertEquals(0, preg_match('/[0-9]?\.[0-9]+/', $value));
            } elseif (is_float($value)) {
                $this->assertTrue(is_float($value));
            } elseif (is_int($value)) {
                $this->assertTrue(is_int($value));
            } else {
                $this->fail('unrecognized technical information');
            }
        }
    }

    protected function evaluateGoodStory($story)
    {
        $this->assertArrayHasKey('databox_id', $story);
        $this->assertTrue(is_int($story['databox_id']));
        $this->assertArrayHasKey('story_id', $story);
        $this->assertTrue(is_int($story['story_id']));
        $this->assertArrayHasKey('updated_on', $story);
        $this->assertDateAtom($story['updated_on']);
        $this->assertArrayHasKey('created_on', $story);
        $this->assertDateAtom($story['created_on']);
        $this->assertArrayHasKey('collection_id', $story);
        $this->assertTrue(is_int($story['collection_id']));
        $this->assertArrayHasKey('thumbnail', $story);
        $this->assertArrayHasKey('uuid', $story);
        $this->assertArrayHasKey('@entity@', $story);
        $this->assertEquals(\API_V1_adapter::OBJECT_TYPE_STORY, $story['@entity@']);
        $this->assertTrue(\uuid::is_valid($story['uuid']));

        if ( ! is_null($story['thumbnail'])) {
            $this->assertTrue(is_array($story['thumbnail']));
            $this->assertArrayHasKey('player_type', $story['thumbnail']);
            $this->assertTrue(is_string($story['thumbnail']['player_type']));
            $this->assertArrayHasKey('permalink', $story['thumbnail']);
            $this->assertArrayHasKey('mime_type', $story['thumbnail']);
            $this->assertTrue(is_string($story['thumbnail']['mime_type']));
            $this->assertArrayHasKey('height', $story['thumbnail']);
            $this->assertTrue(is_int($story['thumbnail']['height']));
            $this->assertArrayHasKey('width', $story['thumbnail']);
            $this->assertTrue(is_int($story['thumbnail']['width']));
            $this->assertArrayHasKey('filesize', $story['thumbnail']);
            $this->assertTrue(is_int($story['thumbnail']['filesize']));
        }

        $this->assertArrayHasKey('records', $story);
        $this->assertInternalType('array', $story['records']);

        foreach ($story['metadatas'] as $key => $metadata) {
            if (null !== $metadata) {
                $this->assertInternalType('string', $metadata);
            }
            if ($key === '@entity@') {
                continue;
            }

            $this->assertEquals(0, strpos($key, 'dc:'));
        }

        $this->assertArrayHasKey('@entity@', $story['metadatas']);
        $this->assertEquals(\API_V1_adapter::OBJECT_TYPE_STORY_METADATA_BAG, $story['metadatas']['@entity@']);

        foreach ($story['records'] as $record) {
            $this->evaluateGoodRecord($record);
        }
    }

    protected function evaluateRecordsCaptionResponse($content)
    {
        $this->assertArrayHasKey('caption_metadatas', $content['response']);

        $this->assertGreaterThan(0, count($content['response']['caption_metadatas']));

        foreach ($content['response']['caption_metadatas'] as $field) {
            $this->assertTrue(is_array($field), 'Un bloc field est un objet');
            $this->assertArrayHasKey('meta_structure_id', $field);
            $this->assertTrue(is_int($field['meta_structure_id']));
            $this->assertArrayHasKey('name', $field);
            $this->assertTrue(is_string($field['name']));
            $this->assertArrayHasKey('value', $field);
            $this->assertTrue(is_string($field['value']));
        }
    }

    protected function evaluateRecordsMetadataResponse($content)
    {
        if (!array_key_exists("record_metadatas", $content['response'])) {
            var_dump($content['response']);
        }

        $this->assertArrayHasKey("record_metadatas", $content['response']);
        foreach ($content['response']['record_metadatas'] as $meta) {
            $this->assertTrue(is_array($meta), 'Un bloc meta est un objet');
            $this->assertArrayHasKey('meta_id', $meta);
            $this->assertTrue(is_int($meta['meta_id']));
            $this->assertArrayHasKey('meta_structure_id', $meta);
            $this->assertTrue(is_int($meta['meta_structure_id']));
            $this->assertArrayHasKey('name', $meta);
            $this->assertTrue(is_string($meta['name']));
            $this->assertArrayHasKey('value', $meta);
            $this->assertArrayHasKey('labels', $meta);
            $this->assertTrue(is_array($meta['labels']));

            $this->assertEquals(['fr', 'en', 'de', 'nl'], array_keys($meta['labels']));

            if (is_array($meta['value'])) {
                foreach ($meta['value'] as $val) {
                    $this->assertTrue(is_string($val));
                }
            } else {
                $this->assertTrue(is_string($meta['value']));
            }
        }
    }

    protected function evaluateRecordsStatusResponse(\record_adapter $record, $content)
    {
        $status = $record->get_databox()->get_statusbits();

        $r_status = strrev($record->get_status());
        $this->assertArrayHasKey('status', $content['response']);
        $this->assertEquals(count((array) $content['response']['status']), count($status));
        foreach ($content['response']['status'] as $status) {
            $this->assertTrue(is_array($status));
            $this->assertArrayHasKey('bit', $status);
            $this->assertArrayHasKey('state', $status);
            $this->assertTrue(is_int($status['bit']));
            $this->assertTrue(is_bool($status['state']));

            $retrieved = !!substr($r_status, ($status['bit'] - 1), 1);

            $this->assertEquals($retrieved, $status['state']);
        }
    }

    protected function injectMetadatas(\record_adapter $record)
    {
        foreach ($record->get_databox()->get_meta_structure()->get_elements() as $field) {
            try {
                $values = $record->get_caption()->get_field($field->get_name())->get_values();
                $value = array_pop($values);
                $meta_id = $value->getId();
            } catch (\Exception $e) {
                $meta_id = null;
            }

            $toupdate[$field->get_id()] = [
                'meta_id'        => $meta_id
                , 'meta_struct_id' => $field->get_id()
                , 'value'          => 'podom pom pom ' . $field->get_id()
            ];
        }

        $record->set_metadatas($toupdate);
    }

    protected function setToken($token)
    {
        $_GET['oauth_token'] = $token;
    }

    protected function unsetToken()
    {
        unset($_GET['oauth_token']);
    }

    private function evaluateSearchResponse($response)
    {
        $this->assertArrayHasKey('available_results', $response);
        $this->assertArrayHasKey('total_results', $response);
        $this->assertArrayHasKey('error', $response);
        $this->assertArrayHasKey('warning', $response);
        $this->assertArrayHasKey('query_time', $response);
        $this->assertArrayHasKey('search_indexes', $response);
        $this->assertArrayHasKey('suggestions', $response);
        $this->assertArrayHasKey('results', $response);
        $this->assertArrayHasKey('query', $response);

        $this->assertTrue(is_int($response['available_results']), 'Le nombre de results dispo est un int');
        $this->assertTrue(is_int($response['total_results']), 'Le nombre de results est un int');
        $this->assertTrue(is_string($response['error']), 'Error est une string');
        $this->assertTrue(is_string($response['warning']), 'Warning est une string');

        $this->assertTrue(is_string($response['search_indexes']));
        $this->assertTrue(is_array($response['suggestions']));
        $this->assertTrue(is_array($response['results']));
        $this->assertTrue(is_string($response['query']));
    }
}
