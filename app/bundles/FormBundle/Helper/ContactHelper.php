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
     * @param Submission $submission
     * @param array      $fieldMappedData
     * @param IpAddress  $ipAddress
     *
     * @return Lead
     */
    public function createFromSubmission(Submission $submission, array $fieldMappedData, IpAddress $ipAddress)
    {
        $this->form = $submission->getForm();

        $this->setContactFromMappedData($fieldMappedData);
        $this->prepareAndSaveContact($ipAddress);
        $this->setCompanyFromMappedData($fieldMappedData);

        //set tracking ID for stats purposes to determine unique hits
        $submission->setTrackingId($this->contactTracker->getTrackingId())
            ->setLead($this->contact);

        return $this->contact;
    }

    /**
     * @param array $fieldMappedData
     */
    private function setContactFromMappedData(array $fieldMappedData)
    {
        // Default to a new contact
        $this->contact = new Lead();
        $this->contact->setNewlyCreated(true);
        $trackedUniqueFieldData = [];

        if (!$this->form->isInKioskMode()) {
            // Default to currently tracked lead
            $this->contact = $this->contactTracker->getContact();

            $this->logger->debug('FORM: Not in kiosk mode so using current contact ID #'.$this->contact->getId());

            $trackedContactData     = $this->contact->getProfileFields();
            $trackedUniqueFieldData = $this->getUniqueFieldValues($trackedContactData);
        }

        $mappedData            = $this->mapData($fieldMappedData, $this->fieldModel->getFieldListWithProperties('lead'));
        $mappedUniqueFieldData = $this->getUniqueFieldValues($mappedData);

        $this->checkForExistingContact($mappedUniqueFieldData, $trackedUniqueFieldData);

        // Bind form data to the contact's profile
        $this->leadModel->setFieldValues($this->contact, $mappedData, false, true, true);
    }

    /**
     * @param array $fieldMappedData
     */
    private function setCompanyFromMappedData(array $fieldMappedData)
    {
        // force add company contact field to company fields check
        $companyFields       = $this->fieldModel->getFieldListWithProperties('company');
        $companyFields       = array_merge($companyFields, ['company' => 'company']);
        $companyFieldMatches = $this->mapData($fieldMappedData, $companyFields);
        if (empty($companyFieldMatches)) {
            return;
        }

        list($company, $addContactToCompany, $companyEntity) = IdentifyCompanyHelper::identifyLeadsCompany(
            $companyFieldMatches,
            $this->contact,
            $this->companyModel
        );

        if (empty($company) || !$companyEntity instanceof Company || !$addContactToCompany) {
            return;
        }

        $this->companyModel->setFieldValues($companyEntity, $companyFieldMatches);
        $this->companyModel->saveEntity($companyEntity);

        $this->contact->addCompanyChangeLogEntry('form', 'Identify Company', 'Lead added to the company, '.$company['companyname'], $company['id']);
        $this->companyModel->addLeadToCompany($companyEntity, $this->contact);
        $this->leadModel->setPrimaryCompany($companyEntity->getId(), $this->contact->getId());
    }

    /**
     * @param array $mappedUniqueFieldData
     * @param array $trackedUniqueFieldData
     */
    private function checkForExistingContact(array $mappedUniqueFieldData, array $trackedUniqueFieldData)
    {
        // Check for existing contacts from submitted data
        $duplicateContacts = $this->contactDeduper->findDuplicateContacts($mappedUniqueFieldData, $this->contact->getId());
        if (!count($duplicateContacts)) {
            return;
        }

        $this->logger->debug('FORM: '.count($duplicateContacts).' found based on unique identifiers');

        /** @var \Mautic\LeadBundle\Entity\Lead $foundContact */
        $foundContact = $duplicateContacts[0];
        $this->logger->debug('FORM: Testing contact ID# '.$foundContact->getId().' for conflicts');

        // Get unique identifier fields for the found contact then compare with the contact currently tracked
        $foundUniqueFieldData = $this->getUniqueFieldValues($foundContact->getProfileFields());

        try {
            // Check for conflicts between the found contact and the tracked contact
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

            try {
                // Merge the found lead with currently tracked lead
                $this->contact = $this->contactMerger->merge($foundContact, $this->contact);
                $this->logger->debug('FORM: Merging contacts '.$this->contact->getId().' and '.$foundContact->getId());
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

    /**
     * @param array $submittedData
     * @param array $fields
     *
     * @return array
     */
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

    /**
     * @param IpAddress $ipAddress
     */
    private function prepareAndSaveContact(IpAddress $ipAddress)
    {
        $this->addIpAddressToContact($ipAddress);

        // Set last active time
        $this->contact->setLastActive(new \DateTime());

        // Set the manipulator
        $this->contact->setManipulator(
            new LeadManipulator(
                'form',
                'submission',
                $this->form->getId(),
                $this->form->getName()
            )
        );

        $this->leadModel->saveEntity($this->contact, false);

        if (!$this->form->isInKioskMode()) {
            // Set the current contact which will generate tracking cookies
            $this->contactTracker->setTrackedContact($this->contact);
        } else {
            // Set system current contact which will still allow execution of events without generating tracking cookies
            $this->contactTracker->setSystemContact($this->contact);
        }
    }

    /**
     * @param IpAddress $ipAddress
     */
    private function addIpAddressToContact(IpAddress $ipAddress)
    {
        if ($this->form->isInKioskMode()) {
            return;
        }

        $ipAddresses = $this->contact->getIpAddresses();
        if (!$ipAddresses->contains($ipAddress)) {
            $this->contact->addIpAddress($ipAddress);

            $this->logger->debug('FORM: Associating '.$ipAddress->getIpAddress().' to contact');
        }
    }
}
