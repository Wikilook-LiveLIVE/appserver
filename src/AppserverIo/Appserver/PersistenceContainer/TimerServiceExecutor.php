<?php

/**
 * \AppserverIo\Appserver\PersistenceContainer\TimerServiceExecutor
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 */

namespace AppserverIo\Appserver\PersistenceContainer;

use AppserverIo\Storage\GenericStackable;
use AppserverIo\Appserver\Core\Environment;
use AppserverIo\Appserver\Core\AbstractDaemonThread;
use AppserverIo\Appserver\Core\Utilities\LoggerUtils;
use AppserverIo\Appserver\Core\Utilities\EnvironmentKeys;
use AppserverIo\Psr\Servlet\SessionUtils;
use AppserverIo\Psr\Application\ApplicationInterface;
use AppserverIo\Psr\EnterpriseBeans\TimerInterface;
use AppserverIo\Psr\EnterpriseBeans\ServiceExecutorInterface;
use AppserverIo\Psr\EnterpriseBeans\TimerServiceContextInterface;

/**
 * The executor thread for the timers.
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 *
 * @property \AppserverIo\Psr\Application\ApplicationInterface $application     The application instance
 * @property \AppserverIo\Storage\GenericStackable             $scheduledTimers Contains the scheduled timers
 * @property \AppserverIo\Storage\GenericStackable             $tasksToExecute  Contains the ID's of the tasks to be executed
 * @property \AppserverIo\Storage\GenericStackable             $timerTasks      Contains the timer tasks that have to be executed
 */
class TimerServiceExecutor extends AbstractDaemonThread implements ServiceExecutorInterface
{

    /**
     * Injects the application instance.
     *
     * @param \AppserverIo\Psr\Application\ApplicationInterface $application The application instance
     *
     * @return void
     */
    public function injectApplication(ApplicationInterface $application)
    {
        $this->application = $application;
    }

    /**
     * Injects the storage for the scheduled timers.
     *
     * @param \AppserverIo\Storage\GenericStackable $scheduledTimers The storage for the scheduled timers
     *
     * @return void
     */
    public function injectScheduledTimers(GenericStackable $scheduledTimers)
    {
        $this->scheduledTimers = $scheduledTimers;
    }

    /**
     * Injects the storage for the ID's of the tasks to be executed.
     *
     * @param \AppserverIo\Storage\GenericStackable $tasksToExecute The storage for the ID's of the tasks to be executed
     *
     * @return void
     */
    public function injectTasksToExecute(GenericStackable $tasksToExecute)
    {
        $this->tasksToExecute = $tasksToExecute;
    }

    /**
     * Injects the timer tasks that have to be executed.
     *
     * @param  \AppserverIo\Storage\GenericStackable $timerTasks The storage for the timer tasks
     *
     * @return void
     */
    public function injectTimerTasks(GenericStackable $timerTasks)
    {
        $this->timerTasks = $timerTasks;
    }

    /**
     * Returns the application instance.
     *
     * @return \AppserverIo\Psr\Application\ApplicationInterface|\AppserverIo\Psr\Naming\NamingDirectoryInterface The application instance
     */
    public function getApplication()
    {
        return $this->application;
    }

    /**
     * Returns the scheduled timers.
     *
     * @return \AppserverIo\Storage\GenericStackable A collection of scheduled timers
     **/
    public function getScheduledTimers()
    {
        return $this->scheduledTimers;
    }

    /**
     * Returns the storage of the ID's of the tasks to be executed.
     *
     * @return \AppserverIo\Storage\GenericStackable The storage for the ID's of the tasks to be executed
     **/
    public function getTasksToExecute()
    {
        return $this->tasksToExecute;
    }

    /**
     * Adds the passed timer task to the schedule.
     *
     * @param \AppserverIo\Psr\EnterpriseBeans\TimerInterface $timer The timer we want to schedule
     *
     * @return void
     */
    public function schedule(TimerInterface $timer)
    {

        // force handling the timer tasks now
        $this->synchronized(function (TimerInterface $t) {

            // store the timer-ID and the PK of the timer service => necessary to load the timer later
            $this->scheduledTimers[$timerId = $t->getId()] = $t->getTimerService()->getPrimaryKey();

            // create a wrapper instance for the timer task that we want to schedule
            $timerTaskWrapper = new \stdClass();
            $timerTaskWrapper->executeAt = microtime(true) + ($t->getTimeRemaining() / 1000000);
            $timerTaskWrapper->taskId = uniqid();
            $timerTaskWrapper->timerId = $timerId;

            // schedule the timer tasks as wrapper
            $this->tasksToExecute[$timerTaskWrapper->taskId] = $timerTaskWrapper;

        }, $timer);
    }

    /**
     * This method will be invoked before the while() loop starts and can be used
     * to implement some bootstrap functionality.
     *
     * @return void
     */
    public function bootstrap()
    {

        // setup autoloader
        require SERVER_AUTOLOADER;

        // make the application available and register the class loaders
        $application = $this->getApplication();
        $application->registerClassLoaders();

        // register the applications annotation registries
        $application->registerAnnotationRegistries();

        // add the application instance to the environment
        Environment::singleton()->setAttribute(EnvironmentKeys::APPLICATION, $application);

        // create s simulated request/session ID whereas session equals request ID
        Environment::singleton()->setAttribute(EnvironmentKeys::SESSION_ID, $sessionId = SessionUtils::generateRandomString());
        Environment::singleton()->setAttribute(EnvironmentKeys::REQUEST_ID, $sessionId);

        // try to load the profile logger
        if (isset($this->loggers[$profileLoggerKey = \AppserverIo\Logger\LoggerUtils::PROFILE])) {
            $this->profileLogger = $this->loggers[$profileLoggerKey];
            $this->profileLogger->appendThreadContext('timer-service-executor');
        }
    }

    /**
     * Collect the finished timer task jobs.
     *
     * @return void
     */
    public function collectGarbage()
    {
        $this->synchronized(function () {
            foreach ($this->timerTasks as $taskId => $timerTask) {
                if ($timerTask->isRunning()) {
                    continue;
                } else {
                    unset($this->timerTasks[$taskId]);
                }
            }
        });
    }


    /**
     * This is invoked on every iteration of the daemons while() loop.
     *
     * @param integer $timeout The timeout before the daemon wakes up
     *
     * @return void
     */
    public function iterate($timeout)
    {

        // call parent method and sleep for the default timeout
        parent::iterate($timeout);

        // iterate over the timer tasks that has to be executed
        foreach ($this->tasksToExecute as $taskId => $timerTaskWrapper) {
            // this should never happen
            if (!$timerTaskWrapper instanceof \stdClass) {
                // log an error message because we task wrapper has wrong type
                \error(sprintf('Timer-Task-Wrapper %s has wrong type %s', $taskId, get_class($timerTaskWrapper)));
                // we didn't foud a timer task ignore this
                continue;
            }

            // query if the task has to be executed now
            if ($timerTaskWrapper->executeAt < microtime(true)) {
                // load the timer task wrapper we want to execute
                if ($pk = $this->scheduledTimers[$timerId = $timerTaskWrapper->timerId]) {
                    // load the timer service registry
                    $timerServiceRegistry = $this->getApplication()->search(TimerServiceContextInterface::IDENTIFIER);

                    // lookup the timer from the timer service
                    $timer = $timerServiceRegistry->lookup($pk)->getTimers()->get($timerId);

                    // create and execute the timer task
                    $this->timerTasks[$taskId] = $timer->getTimerTask($this->getApplication());

                    // remove the key from the list of tasks to be executed
                    unset($this->tasksToExecute[$taskId]);

                } else {
                    // log an error message because we can't find the timer instance
                    \error(sprintf('Can\'t find timer %s to create timer task %s', $timerTaskWrapper->timerId, $taskId));
                }
            }
        }

        // collect the garbage (finished timer task jobs)
        $this->collectGarbage();

        // profile the size of the timer tasks to be executed
        if ($this->profileLogger) {
            $this->profileLogger->debug(
                sprintf('Processed timer service executor, executing %d timer tasks', sizeof($this->tasksToExecute))
            );
        }
    }

    /**
     * This is a very basic method to log some stuff by using the error_log() method of PHP.
     *
     * @param mixed  $level   The log level to use
     * @param string $message The message we want to log
     * @param array  $context The context we of the message
     *
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        LoggerUtils::log($level, $message, $context);
    }

    /**
     * Let the daemon sleep for the passed value of miroseconds.
     *
     * @param integer $timeout The number of microseconds to sleep
     *
     * @return void
     */
    public function sleep($timeout)
    {
        $this->synchronized(function ($self) use ($timeout) {
            $self->wait($timeout);
        }, $this);
    }
}
