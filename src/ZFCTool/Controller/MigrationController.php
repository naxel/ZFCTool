<?php
/**
 * User: naxel
 * Date: 19.02.14 10:50
 */

namespace ZFCTool\Controller;

use Zend\EventManager\EventManagerInterface;
use Zend\View\Model\ViewModel;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Console\Request as ConsoleRequest;
use Zend\Console\Adapter\AdapterInterface as Console;
use Zend\Console\ColorInterface as Color;
use Zend\Console\Exception\RuntimeException;

use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;

use Zend\Db\Sql\Ddl;
use Zend\Db\Sql\Sql;
use Zend\Db\Adapter\Driver\StatementInterface;
use Zend\Db\Adapter\Adapter;

use ZFCTool\Exception\ConflictedMigrationException;
use ZFCTool\Exception\MigrationExecutedException;
use ZFCTool\Exception\MigrationNotLoadedException;
use ZFCTool\Exception\NoMigrationsForExecutionException;
use ZFCTool\Exception\OldMigrationException;
use ZFCTool\Exception\YoungMigrationException;
use ZFCTool\Exception\ZFCToolException;
use ZFCTool\Exception\CurrentMigrationException;
use ZFCTool\Exception\IncorrectMigrationNameException;
use ZFCTool\Exception\MigrationNotExistsException;

use ZFCTool\Service\MigrationManager;

class MigrationController extends AbstractActionController
{

    /** @var ConsoleRequest $request */
    protected $request;

    /** @var Console $console */
    protected $console;

    /** @var MigrationManager $manager */
    protected $manager;

    /**
     * @param MigrationManager $manager
     */
    public function setManager(MigrationManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @return MigrationManager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * @param Console $console
     */
    public function setConsole(Console $console)
    {
        $this->console = $console;
    }

    /**
     * @return Console
     */
    public function getConsole()
    {
        return $this->console;
    }

    /**
     * @param ConsoleRequest $request
     */
    public function setRequest(ConsoleRequest $request)
    {
        $this->request = $request;
    }

    /**
     * @return ConsoleRequest
     */
    public function getRequest()
    {
        return $this->request;
    }


    public function setEventManager(EventManagerInterface $events)
    {
        parent::setEventManager($events);

        $controller = $this;
        $events->attach('dispatch', function ($e) use ($controller) {

            /** @var ConsoleRequest */
            $request = $e->getRequest();

            if (!$request instanceof ConsoleRequest) {
                throw new RuntimeException('You can only use this action from a console!');
            }

            $console = $controller->getServiceLocator()->get('console');
            if (!$console instanceof Console) {
                throw new RuntimeException('Cannot obtain console adapter. Are we running in a console?');
            }

            $controller->setRequest($request);

            $controller->setConsole($console);
            try {
                /** @var MigrationManager $migrationManager */
                $migrationManager = $controller->getServiceLocator()->get('MigrationManager');
                $controller->setManager($migrationManager);
            } catch (Exception $e) {
                do {
                    echo $e->getMessage();
                } while ($e = $e->getPrevious);
                exit;
            }



        }, 100); // execute before executing action logic
    }


    public function indexAction()
    {
        return new ViewModel(); // display standard index page
    }


    public function listAction()
    {
        $module = $this->request->getParam('module');
        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }

        //Display mini-help
        $this->console->writeLine(str_pad('', $this->console->getWidth(), '-'));
        $this->console->writeLine('L - Already loaded', Color::GREEN);
        $this->console->writeLine('R - Ready for load', Color::YELLOW);
        $this->console->writeLine('LN - Loaded, not exists', Color::RED);
        $this->console->writeLine('C - Conflict, not loaded', null, Color::RED);
        $this->console->writeLine(str_pad('', $this->console->getWidth(), '-'));

        try {
            $manager = $this->getManager();
            $migrations = $this->manager->listMigrations($module);

            foreach ($migrations as $migration) {

                $color = null;
                $bgColor = null;
                $prefix = '';

                switch ($migration['type']) {
                    case $manager::MIGRATION_TYPE_CONFLICT:
                        $bgColor = Color::RED;
                        $prefix = '[C]';
                        break;
                    case $manager::MIGRATION_TYPE_READY:
                        $color = Color::YELLOW;
                        $prefix = '[R]';
                        break;
                    case $manager::MIGRATION_TYPE_NOT_EXIST:
                        $color = Color::RED;
                        $prefix = '[LN]';
                        break;
                    case $manager::MIGRATION_TYPE_LOADED:
                        $color = Color::GREEN;
                        $prefix = '[L]';
                        break;
                }

                //Display all migrations
                $this->console->writeLine($prefix . ' ' . $migration['name'], $color, $bgColor);
            }

            $this->console->writeLine(str_pad('', $this->console->getWidth(), '-'));

        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }


    public function createAction()
    {
        $module = $this->request->getParam('module');
        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }

        try {
            $migrationPath = $this->manager->create($module);

            if ($migrationPath) {
                $this->console->writeLine('Migration created: ' . $migrationPath, Color::GREEN);
            }
        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }


    public function generateAction()
    {
        $module = $this->request->getParam('module');
        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }

        try {

            $migrationPath = $this->manager->generateMigration($module);

            if ($migrationPath) {
                $this->console->writeLine('Migration generated: ' . $migrationPath, Color::GREEN);
            }

        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }

    public function fakeAction()
    {
        $module = $this->request->getParam('module');
        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }
        $to = $this->request->getParam('to');


        try {
            $migrationManager = $this->getManager();

            if ((null === $to) && $migrationManager::isMigration($module)) {
                list($to, $module) = array($module, null);
            }

            $this->manager->fake($module, $to);

            $this->console->writeLine("Fake upgrade to revision `$to`", Color::GREEN);

        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }

    /**
     * down
     *
     */
    public function downAction()
    {
        $module = $this->request->getParam('module');
        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }
        $to = $this->request->getParam('to');

        try {

            $this->manager->down($module, $to);

            foreach ($this->manager->getMessages() as $message) {
                $this->console->writeLine($message, Color::GREEN);
            }

        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }


    /**
     * up
     */
    public function upAction()
    {
        $module = $this->request->getParam('module');

        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }
        $to = $this->request->getParam('to');

        try {

            $migrationManager = $this->getManager();

            if ((null === $to) && $migrationManager::isMigration($module)) {
                list($to, $module) = array($module, null);
            }

            $this->manager->up($module, $to);

            foreach ($this->manager->getMessages() as $message) {
                $this->console->writeLine($message, Color::GREEN);
            }

        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }


    /**
     * print differences on screen
     */
    public function diffAction()
    {
        $module = $this->request->getParam('module');

        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }

        $whiteList = $this->request->getParam('whiteList');
        $blackList = $this->request->getParam('blackList');

        try {

            $result = $this->manager->generateMigration($module, $blackList, $whiteList, true, '', '');

            if (!empty($result)) {
                $this->console->writeLine('Queries (' . sizeof($result['up']) . ') :' . PHP_EOL);

                if (sizeof($result['up']) > 0)
                    foreach ($result['up'] as $diff) {
                        $this->console->writeLine(stripcslashes($diff) . PHP_EOL);
                    }

            } else {
                $this->console->writeLine('Your database has no changes from last revision!');
            }

        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }

    /**
     *
     */
    public function rollbackAction()
    {
        $module = $this->request->getParam('module');
        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }
        $step = $this->request->getParam('step');

        if (is_numeric($module) && (0 < (int)$module)) {
            list($step, $module) = array($module, null);
        }

        if (null === $step) {
            $step = 1;
        }

        try {

            $this->getManager()->rollback($module, $step);

            foreach ($this->manager->getMessages() as $message) {
                $this->console->writeLine($message, Color::GREEN);
            }

        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }


    /**
     * current migration
     *
     */
    public function currentAction()
    {
        $module = $this->request->getParam('module');

        if ($module) {
            $this->console->writeLine('Only for module "' . $module . '":');
        }

        try {

            $revision = $this->getManager()->getLastMigration($module);
            if ('0' == $revision['id']) {
                $this->console->writeLine('None');
            } else {
                $this->console->writeLine('Current migration is: ' . $revision['migration']);
            }

        } catch (ZFCToolException $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        } catch (\Exception $e) {
            $this->console->writeLine($e->getMessage(), Color::RED);
        }
    }
}
