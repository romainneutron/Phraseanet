<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Model\Repositories;

use Alchemy\Phrasea\Application;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FeedItemRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class FeedItemRepository extends EntityRepository
{
    /**
     * Checks if a record is published in a public feed.
     *
     * @param integer     $sbas_id
     * @param integer     $record_id
     *
     * @return Boolean
     */
    public function isRecordInPublicFeed($sbas_id, $record_id)
    {
        $dql = 'SELECT i
            FROM Phraseanet:FeedItem i
            JOIN i.entry e
            JOIN e.feed f
            WHERE i.sbasId = :sbas_id
                AND i.recordId = :record_id
                AND f.public = true';

        $query = $this->_em->createQuery($dql);
        $query->setParameters(['sbas_id' => $sbas_id, 'record_id' => $record_id]);

        return count($query->getResult()) > 0;
    }

    /**
     * Gets latest items from public feeds.
     *
     * @param Application $app
     * @param integer     $nbItems
     *
     * @return FeedItem[] An array of FeedItem
     */
    public function loadLatest(Application $app, $nbItems = 20)
    {
        $execution = 0;
        $items = [];

        do {
            $dql = 'SELECT i
                FROM Phraseanet:FeedItem i
                JOIN i.entry e
                JOIN e.feed f
                WHERE f.public = true ORDER BY i.createdOn DESC';

            $query = $this->_em->createQuery($dql);
            $query
                ->setFirstResult((integer) $nbItems * $execution)
                ->setMaxResults((integer) $nbItems);

            $result = $query->getResult();

            foreach ($result as $item) {
                try {
                    $record = $item->getRecord($app);
                } catch (NotFoundHttpException $e) {
                    $app['EM']->remove($item);
                    continue;
                } catch (\Exception_Record_AdapterNotFound $e) {
                    $app['EM']->remove($item);
                    continue;
                }

                if (null !== $preview = $record->get_subdef('preview')) {
                    if (null !== $permalink = $preview->get_permalink()) {
                        $items[] = $item;

                        if (count($items) >= $nbItems) {
                            break;
                        }
                    }
                }
            }

            $app['EM']->flush();
            $execution++;
        } while (count($items) < $nbItems && count($result) !== 0);

        return $items;
    }
}
