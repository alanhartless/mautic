<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\EventListener;

use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FormSubscriber implements EventSubscriberInterface
{
    /**
     * @var CampaignModel
     */
    private $campaignModel;

    /**
     * FormSubscriber constructor.
     *
     * @param CampaignModel $campaignModel
     */
    public function __construct(CampaignModel $campaignModel)
    {
        $this->campaignModel = $campaignModel;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            FormEvents::FORM_ON_SUBMIT => ['onFormSubmit'],
        ];
    }

    /**
     * @param SubmissionEvent $event
     */
    public function onFormSubmit(SubmissionEvent $event)
    {
        $form    = $event->getForm();
        $contact = $event->getContact();

        if (!$contact || $form->isStandalone()) {
            return;
        }

        // Find and add the lead to the associated campaigns
        $campaigns = $this->campaignModel->getCampaignsByForm($form);
        if (empty($campaigns)) {
            return;
        }

        foreach ($campaigns as $campaign) {
            $this->campaignModel->addLead($campaign, $contact);
        }
    }
}
