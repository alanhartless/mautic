<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\FormBundle\Helper;

use Mautic\CoreBundle\Entity\IpAddress;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Helper\Exception\NoConflictsFound;
use Mautic\LeadBundle\DataObject\LeadManipulator;
use Mautic\LeadBundle\Deduplicate\ContactDeduper;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Deduplicate\Exception\SameContactException;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Monolog\Logger;

class ContactHelper
{
    /**
     * @var CompanyModel
     */
    private $companyModel;

    /**
     * @var ContactTracker
     */
    private $contactTracker;

    /**
     * @var ContactMerger
     */
    private $contactMerger;

    /**
     * @var ContactDeduper
     */
    private $contactDeduper;

    /**
     * @var FieldModel
     */
    private $fieldModel;

    /**
     * @var LeadModel
     */
    private $leadModel;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Form
     */
    private $form;

    /**
     * @var Lead
     */
    private $contact;

    /**
     * ContactHelper constructor.
     *
     * @param CompanyModel   $companyModel
     * @param LeadModel      $leadModel
     * @param FieldModel     $fieldModel
     * @param ContactTracker $contactTracker
     * @param ContactMerger  $contactMerger
     * @param ContactDeduper $contactDeduper
     * @param Logger         $logger
     */
    public function __construct(
        CompanyModel $companyModel,
        LeadModel $leadModel,
        FieldModel $fieldModel,
        ContactTracker $contactTracker,
        ContactMerger $contactMerger,
        ContactDeduper $contactDeduper,
        Logger $logger
    ) {
        $this->companyModel   = $companyModel;
        $this->leadModel      = $leadModel;
        $this->fieldModel     = $fieldModel;
        $this->contactTracker = $contactTracker;
        $this->contactMerger  = $contactMerger;
        $this->contactDeduper = $contactDeduper;
        $this->logger         = $logger;
    }

    /**
     * Create/update lead from form submit.
     *
     * @param Form  $this->form
     * @param array $this->contactFieldMatches
     *
     * @return Lead
     */
    public function createFromSubmission(Submission $submission, array $fieldMappedData, IpAddress $ipAddress)
    {
        $this->form = $submission->getForm();

        $contact = $this->getContact($fieldMappedData);

        $inKioskMode   = $this->form->isInKioskMode();

        //no lead was found by a mapped email field so create a new one
        if ($this->contact->isNewlyCreated()) {
            if (!$inKioskMode) {
                $this->contact->addIpAddress($ipAddress);
                $this->logger->debug('FORM: Associating '.$ipAddress->getIpAddress().' to contact');
            }
        } elseif (!$inKioskMode) {
            $this->contactIpAddresses = $this->contact->getIpAddresses();
            if (!$this->contactIpAddresses->contains($ipAddress)) {
                $this->contact->addIpAddress($ipAddress);

                $this->logger->debug('FORM: Associating '.$ipAddress->getIpAddress().' to contact');
            }
        }

        //set the mapped fields
        $this->leadModel->setFieldValues($this->contact, $mappedData, false, true, true);

        // last active time
        $this->contact->setLastActive(new \DateTime());

        //create a new lead
        $this->contact->setManipulator(
            new LeadManipulator(
                'form',
                'submission',
                $this->form->getId(),
                $this->form->getName()
            )
        );
        $this->leadModel->saveEntity($this->contact, false);

        if (!$inKioskMode) {
            // Set the current lead which will generate tracking cookies
            $this->contactTracker->setTrackedContact($this->contact);
        } else {
            // Set system current lead which will still allow execution of events without generating tracking cookies
            $this->contactTracker->setSystemContact($this->contact);
        }

        $companyFieldMatches = $this->mapData($fieldMappedData, $this->fieldModel->getFieldListWithProperties('company'));
        if (!empty($companyFieldMatches)) {
            list($company, $this->contactAdded, $companyEntity) = IdentifyCompanyHelper::identifyLeadsCompany(
                $companyFieldMatches,
                $this->contact,
                $this->companyModel
            );
            if ($this->contactAdded) {
                $this->contact->addCompanyChangeLogEntry('form', 'Identify Company', 'Lead added to the company, '.$company['companyname'], $company['id']);
            }

            if (!empty($company) and $companyEntity instanceof Company) {
                // Save after the lead in for new leads created through the API and maybe other places
                $this->companyModel->addLeadToCompany($companyEntity, $this->contact);
                $this->leadModel->setPrimaryCompany($companyEntity->getId(), $this->contact->getId());
            }
        }

        // Get updated lead if applicable with tracking ID
        /* @var Lead $this->contact */
        $this->contact          = $this->contactTracker->getContact();
        //set tracking ID for stats purposes to determine unique hits
        $submission->setTrackingId($this->contactTracker->getTrackingId())
            ->setLead($this->contact);

        return $this->contact;
    }

    private function getContact()
    {
        if (!$this->form->isInKioskMode()) {
            // Default to currently tracked lead
            $this->contact = $this->contactTracker->getContact();

            $this->logger->debug('FORM: Not in kiosk mode so using current contact ID #'.$this->contact->getId());

            return;
        }

        // Default to a new lead in kiosk mode
        $this->contact = new Lead();
        $this->contact->setNewlyCreated(true);

        $this->logger->debug('FORM: In kiosk mode so assuming a new contact');
    }

    /**
     * @param array $mappedData
     * @param array $trackedUniqueFieldData
     */
    private function checkForExistingContact(array $mappedData, array $trackedUniqueFieldData)
    {
        $duplicateContacts = $this->contactDeduper->findDuplicateContacts($mappedData, $this->contact->getId());
        if (!count($duplicateContacts)) {
            return;
        }

        $this->logger->debug(count($duplicateContacts).' found based on unique identifiers');

        /** @var \Mautic\LeadBundle\Entity\Lead $foundContact */
        $foundContact = $duplicateContacts[0];
        $this->logger->debug('FORM: Testing contact ID# '.$foundContact->getId().' for conflicts');

        // Get unique identifier fields for the found contact then compare with the contact currently tracked
        $foundUniqueFieldData = $this->getUniqueFieldValues($foundContact->getProfileFields());

        try {
            $conflicts = $this->checkForConflicts($foundUniqueFieldData, $trackedUniqueFieldData);
            $this->logger->debug('FORM: Conflicts found in '.implode(', ', $conflicts).' so not merging');

            // Use the found lead without merging because there is some sort of conflict with unique identifiers or in kiosk mode and
            // thus should not be merged
            $this->contact = $foundContact;
        } catch (NoConflictsFound $exception) {
            if ($this->form->isInKioskMode() || !$this->contact->getId()) {
                $this->logger->debug('FORM: In kiosk mode so use the found contact without merging');

                $this->contact = $foundContact;

                return;
            }

            $this->logger->debug('FORM: Merging contacts '.$this->contact->getId().' and '.$foundContact->getId());

            try {
                // Merge the found lead with currently tracked lead
                $this->contact = $this->contactMerger->merge($foundContact, $this->contact);
            } catch (SameContactException $exception) {
            }
        }
    }

    /**
     * @param array $fieldSet1
     * @param array $fieldSet2
     *
     * @return array
     *
     * @throws NoConflictsFound
     */
    private function checkForConflicts(array $fieldSet1, array $fieldSet2)
    {
        // Find fields in both sets
        $potentialConflicts = array_keys(
            array_intersect_key($fieldSet1, $fieldSet2)
        );

        $this->logger->debug(
            'FORM: Potential conflicts '.implode(', ', array_keys($potentialConflicts)).' = '.implode(', ', $potentialConflicts)
        );

        $conflicts = [];
        foreach ($potentialConflicts as $field) {
            if (!empty($fieldSet1[$field]) && !empty($fieldSet2[$field])) {
                if (strtolower($fieldSet1[$field]) !== strtolower($fieldSet2[$field])) {
                    $conflicts[] = $field;
                }
            }
        }

        if (empty($conflicts)) {
            throw new NoConflictsFound();
        }

        return $conflicts;
    }

    private function mapData(array $submittedData, array $fields)
    {
        $mappedData = [];
        foreach ($fields as $alias => $properties) {
            if (!isset($submittedData[$alias])) {
                continue;
            }

            $mappedData[$alias] = $submittedData[$alias];
        }

        return $mappedData;
    }

    /**
     * @param array $mappedData
     *
     * @return array
     */
    private function getUniqueFieldValues(array $mappedData)
    {
        $uniqueLeadFields = $this->fieldModel->getUniqueIdentifierFields();

        $mappedUniqueFieldData = [];
        foreach ($mappedData as $field => $value) {
            if (empty($value) || !array_key_exists($field, $uniqueLeadFields)) {
                continue;
            }

            $mappedUniqueFieldData[$field] = $value;
        }

        return $mappedUniqueFieldData;
    }
}
