<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Exception\SessionNotFound;
use Alchemy\Phrasea\Model\Entities\SessionModule;

class Session_Logger
{
    /**
     *
     * @var int
     */
    protected $id;

    /**
     *
     * @var databox
     */
    protected $databox;

    const EVENT_DELETE = 'delete';
    const EVENT_EDIT = 'edit';
    const EVENT_EXPORTDOWNLOAD = 'download';
    const EVENT_EXPORTFTP = 'ftp';
    const EVENT_EXPORTMAIL = 'mail';
    const EVENT_MOVE = 'collection';
    const EVENT_PRINT = 'print';
    const EVENT_PUSH = 'push';
    const EVENT_STATUS = 'status';
    const EVENT_SUBSTITUTE = 'substit';
    const EVENT_VALIDATE = 'validate';

    /**
     *
     * @param Application $app
     * @param databox     $databox
     * @param integer     $log_id
     *
     * @return Session_Logger
     */
    public function __construct(databox $databox, $log_id)
    {
        $this->databox = $databox;
        $this->id = (int) $log_id;

        return $this;
    }

    /**
     *
     * @return int
     */
    public function get_id()
    {
        return $this->id;
    }

    public function log(record_adapter $record, $action, $final, $comment)
    {
        $sql = 'INSERT INTO log_docs
          (id, log_id, date, record_id, action, final, comment)
          VALUES (null, :log_id, NOW(), :record_id, :action, :final, :comm)';

        $stmt = $this->databox->get_connection()->prepare($sql);

        $params = [
            ':log_id'    => $this->get_id()
            , ':record_id' => $record->get_record_id()
            , ':action'    => $action
            , ':final'     => $final
            , ':comm'      => $comment
        ];

        $stmt->execute($params);
        $stmt->closeCursor();

        return $this;
    }

    /**
     *
     * @param Application $app
     * @param databox     $databox
     * @param Browser     $browser
     *
     * @return Session_Logger
     */
    public static function create(Application $app, databox $databox, Browser $browser)
    {
        $colls = [];

        if ($app['authentication']->getUser()) {
            $bases = $app['acl']->get($app['authentication']->getUser())->get_granted_base([], [$databox->get_sbas_id()]);
            foreach ($bases as $collection) {
                $colls[] = $collection->get_coll_id();
            }
        }

        $conn =  $databox->get_connection();

        $sql = "INSERT INTO log
              (id, date,sit_session, user, site, usrid, nav,
                version, os, res, ip, user_agent,appli, fonction,
                societe, activite, pays)
            VALUES
              (null,now() , :ses_id, :usr_login, :site_id, :usr_id
              , :browser, :browser_version,  :platform, :screen, :ip
              , :user_agent, :appli, :fonction, :company, :activity, :country)";

        $params = [
            ':ses_id'          => $app['session']->get('session_id'),
            ':usr_login'       => $app['authentication']->getUser() ? $app['authentication']->getUser()->getLogin() : null,
            ':site_id'         => $app['conf']->get(['main', 'key']),
            ':usr_id'          => $app['authentication']->isAuthenticated() ? $app['authentication']->getUser()->getId() : null,
            ':browser'         => $browser->getBrowser(),
            ':browser_version' => $browser->getExtendedVersion(),
            ':platform'        => $browser->getPlatform(),
            ':screen'          => $browser->getScreenSize(),
            ':ip'              => $browser->getIP(),
            ':user_agent'      => $browser->getUserAgent(),
            ':appli'           => serialize([]),
            ':fonction' => $app['authentication']->getUser() ? $app['authentication']->getUser()->getJob() : null,
            ':company'  => $app['authentication']->getUser() ? $app['authentication']->getUser()->getCompany() : null,
            ':activity' => $app['authentication']->getUser() ? $app['authentication']->getUser()->getActivity() : null,
            ':country'  => $app['authentication']->getUser() ? $app['authentication']->getUser()->getCountry() : null
        ];

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $log_id = $conn->lastInsertId();
        $stmt->closeCursor();

        $sql = "INSERT INTO log_colls (id, log_id, coll_id) VALUES (null, :log_id, :coll_id)";
        $stmt = $conn->prepare($sql);

        foreach ($colls as $collId) {
            $stmt->execute([
                ':log_id'  => $log_id,
                ':coll_id' => $collId
            ]);
        }

        $stmt->closeCursor();
        unset($stmt, $conn);

        return new Session_Logger($databox, $log_id);
    }

    public static function load(Application $app, databox $databox)
    {
        if ( ! $app['authentication']->isAuthenticated()) {
            throw new Exception_Session_LoggerNotFound('Not authenticated');
        }

        $sql = 'SELECT id FROM log
            WHERE site = :site AND sit_session = :ses_id';

        $params = [
            ':site'   => $app['conf']->get(['main', 'key'])
            , ':ses_id' => $app['session']->get('session_id')
        ];

        $stmt = $databox->get_connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ( ! $row)
            throw new Exception_Session_LoggerNotFound('Logger not found');

        return new self($databox, $row['id']);
    }

    public static function updateClientInfos(Application $app, $appId)
    {
        if (!$app['authentication']->isAuthenticated()) {
            return;
        }

        $session = $app['EM']->find('Phraseanet:Session', $app['session']->get('session_id'));

        if (!$session) {
            throw new SessionNotFound('No session found');
        }

        if (!$session->hasModuleId($appId)) {
            $module = new SessionModule();

            $module->setModuleId($appId);
            $module->setSession($session);
            $session->addModule($module);

            $app['EM']->persist($module);
            $app['EM']->persist($session);

            $app['EM']->flush();
        }

        $appName = [
            '1' => 'Prod',
            '2' => 'Client',
            '3' => 'Admin',
            '4' => 'Report',
            '5' => 'Thesaurus',
            '6' => 'Compare',
            '7' => 'Validate',
            '8' => 'Upload',
            '9' => 'API'
        ];

        if (isset($appName[$appId])) {
            $sbas_ids = array_keys($app['acl']->get($app['authentication']->getUser())->get_granted_sbas());

            foreach ($sbas_ids as $sbas_id) {
                try {
                    $logger = $app['phraseanet.logger']($app['phraseanet.appbox']->get_databox($sbas_id));

                    $databox = $app['phraseanet.appbox']->get_databox($sbas_id);
                    $connbas = $databox->get_connection();
                    $sql = 'SELECT appli FROM log WHERE id = :log_id';
                    $stmt = $connbas->prepare($sql);
                    $stmt->execute([':log_id' => $logger->get_id()]);
                    $row3 = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt->closeCursor();

                    if (!$row3)
                        throw new Exception('no log');
                    $applis = unserialize($row3['appli']);

                    if (!in_array($appId, $applis)) {
                        $applis[] = $appId;
                    }

                    $sql = 'UPDATE log SET appli = :applis WHERE id = :log_id';

                    $params = [
                        ':applis' => serialize($applis)
                        , ':log_id' => $logger->get_id()
                    ];

                    $stmt = $connbas->prepare($sql);
                    $stmt->execute($params);
                    $stmt->closeCursor();
                } catch (\Exception $e) {

                }
            }
        }

        return;
    }
}
