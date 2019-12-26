<?php

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\EmailBundle\Swiftmailer\Spool;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Swift_Mime_SimpleMessage;

/**
 * Class DelegatingSpool
 * This class must extend \Swift_FileSpool due to SendEmailCommand only setting recover-timeout if $spool is an instance of \Swift_FileSpool.
 */
class DelegatingSpool extends \Swift_FileSpool
{
    /**
     * @var bool
     */
    private $fileSpoolEnabled = false;

    /**
     * @var \Swift_Transport
     */
    private $realTransport;

    /**
     * @var bool
     */
    private $messageSpooled = false;

    /**
     * DelegatingSpool constructor.
     *
     * @param CoreParametersHelper $coreParametersHelper
     * @param \Swift_Transport     $realTransport
     *
     * @throws \Swift_IoException
     */
    public function __construct(CoreParametersHelper $coreParametersHelper, \Swift_Transport $realTransport)
    {
        $this->fileSpoolEnabled = 'file' === $coreParametersHelper->getParameter('mailer_spool_type');
        $this->realTransport    = $realTransport;

        $filePath = $coreParametersHelper->getParameter('mailer_spool_path');
        parent::__construct($filePath);
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @param string[]|null            $failedRecipients
     *
     * @return int
     *
     * @throws \Swift_IoException
     */
    public function delegateMessage(Swift_Mime_SimpleMessage $message, &$failedRecipients = null): int
    {
        $this->messageSpooled = false;

        // Write to filesystem if file spooling is enabled
        if ($this->fileSpoolEnabled) {
            $this->messageSpooled = parent::queueMessage($message);

            return 1;
        }

        // Send immediately otherwise
        return $this->realTransport->send($message, $failedRecipients);
    }

    public function wasMessageSpooled(): bool
    {
        return $this->messageSpooled;
    }
}
