<?php

namespace TheliaMySQLModesChecker\Listener;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\DataFetcher\PDODataFetcher;
use Propel\Runtime\Propel;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;

/**
 * @author Gilles Bourgeat <gilles.bourgeat@gmail.com>
 */
class BootListener implements EventSubscriberInterface
{
    public function boot(Event $event)
    {
        /** @var ConnectionInterface $con */
        $con = Propel::getConnection('thelia');

        /** @var  PDODataFetcher $result */
        $result = $con->query("SELECT VERSION() as version, @@SESSION.sql_mode as session_sql_mode");

        if ($result && $data = $result->fetch(\PDO::FETCH_ASSOC)) {
            $sessionSqlMode = explode(',', $data['session_sql_mode']);
            if (empty($sessionSqlMode[0])) {
                unset($sessionSqlMode[0]);
            }
            $canUpdate = false;

            // MariaDB is not impacted by this problem
            if (false === strpos($data['version'], 'MariaDB')) {
                // MySQL 5.6+ compatibility
                if (version_compare($data['version'], '5.6.0', '>=')) {
                    // add NO_ENGINE_SUBSTITUTION
                    if (!\in_array('NO_ENGINE_SUBSTITUTION', $sessionSqlMode)) {
                        $sessionSqlMode[] = 'NO_ENGINE_SUBSTITUTION';
                        $canUpdate = true;
                        Tlog::getInstance()->addWarning("Add sql_mode NO_ENGINE_SUBSTITUTION. Please configure your MySQL server.");
                    }

                    // remove STRICT_TRANS_TABLES
                    if (($key = array_search('STRICT_TRANS_TABLES', $sessionSqlMode)) !== false) {
                        unset($sessionSqlMode[$key]);
                        $canUpdate = true;
                        Tlog::getInstance()->addWarning("Remove sql_mode STRICT_TRANS_TABLES. Please configure your MySQL server.");
                    }

                    // remove ONLY_FULL_GROUP_BY
                    if (($key = array_search('ONLY_FULL_GROUP_BY', $sessionSqlMode)) !== false) {
                        unset($sessionSqlMode[$key]);
                        $canUpdate = true;
                        Tlog::getInstance()->addWarning("Remove sql_mode ONLY_FULL_GROUP_BY. Please configure your MySQL server.");
                    }
                }
            } else {
                // MariaDB 10.2.4+ compatibility
                if (version_compare($data['version'], '10.2.4', '>=')) {
                    // remove STRICT_TRANS_TABLES
                    if (($key = array_search('STRICT_TRANS_TABLES', $sessionSqlMode)) !== false) {
                        unset($sessionSqlMode[$key]);
                        $canUpdate = true;
                        Tlog::getInstance()->addWarning("Remove sql_mode STRICT_TRANS_TABLES. Please configure your MySQL server.");
                    }
                }

                if (version_compare($data['version'], '10.1.7', '>=')) {
                    if (!\in_array('NO_ENGINE_SUBSTITUTION', $sessionSqlMode)) {
                        $sessionSqlMode[] = 'NO_ENGINE_SUBSTITUTION';
                        $canUpdate = true;
                        Tlog::getInstance()->addWarning("Add sql_mode NO_ENGINE_SUBSTITUTION. Please configure your MySQL server.");
                    }
                }
            }

            if (! empty($canUpdate)) {
                if (null === $con->query("SET SESSION sql_mode='" . implode(',', $sessionSqlMode) . "';")) {
                    throw new \RuntimeException('Failed to set MySQL global and session sql_mode');
                }
            }
        } else {
            Tlog::getInstance()->addWarning("Failed to get MySQL version and sql_mode");
        }
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return array(
            TheliaEvents::BOOT => ["boot", 128]
        );
    }
}
