<?php

/**
 * AppserverIo\Appserver\Core\Consoles\TelnetFactory
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

namespace AppserverIo\Appserver\Core\Consoles;

use AppserverIo\Psr\Cli\ConsoleFactoryInterface;
use AppserverIo\Psr\Cli\Configuration\ConsoleConfigurationInterface;
use AppserverIo\Psr\ApplicationServer\ApplicationServerInterface;

/**
 * Factory to create new telnet console instances.
 *
 * @author    Tim Wagner <tw@appserver.io>
 * @copyright 2015 TechDivision GmbH <info@appserver.io>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/appserver-io/appserver
 * @link      http://www.appserver.io
 */
class TelnetFactory implements ConsoleFactoryInterface
{

    /**
     * Factory method to create new telnet console instances.
     *
     * @param \AppserverIo\Psr\ApplicationServer\ApplicationServerInterface    $applicationServer The application server instance
     * @param \AppserverIo\Psr\Cli\Configuration\ConsoleConfigurationInterface $consoleNode       The console configuration
     *
     * @return \AppserverIo\Psr\Cli\ConsoleInterface The telnet console instance
     */
    public static function factory(ApplicationServerInterface $applicationServer, ConsoleConfigurationInterface $consoleNode)
    {
        return new Telnet($applicationServer, $consoleNode);
    }
}
