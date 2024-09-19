<?php

namespace MauticPlugin\JotaworksDoiBundle\Helper;

use Mautic\LeadBundle\Event\ContactIdentificationEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\JotaworksDoiBundle\Event\DoiSuccessful;
use MauticPlugin\JotaworksDoiBundle\Helper\LeadHelper;
use MauticPlugin\JotaworksDoiBundle\DoiEvents;

use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\FieldModel as LeadFieldModel;
use Mautic\LeadBundle\Deduplicate\ContactMerger;


class DoiActionHelper {

    protected $eventDispatcher;

    protected $ipLookupHelper;

    protected $pageModel;

    protected $emailModel;

    protected $auditLogModel;

    protected $leadModel;

    protected $leadFieldModel;

    protected $em;

    protected $logger;

    protected $request;

    public function __construct($eventDispatcher, $ipLookupHelper, $pageModel, $emailModel, $auditLogModel, $leadModel, $leadFieldModel, $em, $logger, $request )
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->ipLookupHelper  = $ipLookupHelper;
        $this->pageModel       = $pageModel;
        $this->emailModel      = $emailModel;
        $this->auditLogModel   = $auditLogModel;
        $this->leadModel       = $leadModel;
        $this->leadFieldModel  = $leadFieldModel;
        $this->em              = $em;
        $this->logger          = $logger;
        $this->request         = $request->getCurrentRequest();
    }

    public function setRequest($request)
    {
        $this->request = $request;
    }    

    public function applyDoiActions($config) 
    {
        $this->logDoiSuccess($config);
        $this->updateLead($config);
        $this->removeDNC($config['leadEmail']);
        $this->identifyLead($config['lead_id']);
        $this->trackPageHit($config);
        $this->fireWebhook($config);
    }

    public function fireWebhook($config) 
    {
        $lead = $this->leadModel->getEntity($config['lead_id']);
        if(!$lead)
        {
            return;
        }

        $doiEvent = new DoiSuccessful($lead, $config);
        $this->eventDispatcher->dispatch($doiEvent, DoiEvents::DOI_SUCCESSFUL);
    }

    public function trackPageHit($config)
    {

        if($this->request)
        {
            $lead = $this->leadModel->getEntity($config['lead_id']);
            if(!$lead)
            {
                return;
            }

            $this->request->request->set('page_url', $config['url']);
            $this->request->query->set('page_url', $config['url']);

            $this->pageModel->hitPage(null, $this->request, $code = '200', $lead );
        }

    }

    public function identifyLead($leadId) 
    {
        $clickthrough = ['leadId' => $leadId ];
    
        $event = new ContactIdentificationEvent($clickthrough);
        $this->eventDispatcher->dispatch(LeadEvents::ON_CLICKTHROUGH_IDENTIFICATION, $event);
    }

    public function removeDNC($email)
    {
        $this->emailModel->removeDoNotContact($email);
    }

    public function logDoiSuccess($config)
    {
        $ip = $this->ipLookupHelper->getIpAddressFromRequest();
        $log = [
            'bundle'    => 'lead',
            'object'    => 'doi',
            'objectId'  => $config['lead_id'],
            'action'    => 'confirm_doi',
            'details'   => $config,
            'ipAddress' => $ip,
        ];
        $this->auditLogModel->writeToLog($log);
    }

    public function updateLead($config)
    {
        $addTags               = (!empty($config['add_tags'])) ? $config['add_tags'] : [];
        $removeTags            = (!empty($config['remove_tags'])) ? $config['remove_tags'] : [];
        $addTo                 = (!empty($config['addToLists'])) ? $config['addToLists']: [];
        $removeFrom            = (!empty($config['removeFromLists'])) ? $config['removeFromLists']: [];
        $leadFieldUpdate       = (!empty($config['leadFieldUpdate'])) ? $config['leadFieldUpdate']: [];
        $leadFieldUpdateBefore = (!empty($config['leadFieldUpdateBefore'])) ? $config['leadFieldUpdateBefore']: [];

        $lead = $this->leadModel->getEntity($config['lead_id']);
        if(!$lead)
        {
            return;
        }
    
        // Change Tags (if any)
        if(!empty($addTags)|| !empty($removeTags)){
            $this->leadModel->modifyTags($lead, $addTags, $removeTags);
        }

        // Add to Lists (if any)
        if (!empty($addTo)) {
            $this->leadModel->addToLists($lead, $addTo);
        }

        // Remove from Lists (if any)
        if (!empty($removeFrom)) {
            $this->leadModel->removeFromLists($lead, $removeFrom);
        }       

        //Update lead value (if any)
        if( !empty($leadFieldUpdate) )
        {
            $ip = $this->ipLookupHelper->getIpAddressFromRequest();            
            LeadHelper::leadFieldUpdate($leadFieldUpdate, $this->leadModel, $lead, $ip );               
        }

        // Merge duplicate contacts if any
        $this->mergeDuplicates($lead);
    }

    public function mergeDuplicates($lead)
    {
        //get current lead fields and values
        $leadFields       = $lead->getFields(true);
        
        $uniqueLeadFields = $this->leadFieldModel->getUniqueIdentifierFields();
        // Get profile fields of the current lead
        $currentFields    = $lead->getProfileFields();
    
        // Closure to get data and unique fields
        $getData = function ($currentFields, $uniqueOnly = false) use ($leadFields, $uniqueLeadFields) {
            $uniqueFieldsWithData = $data = [];
            foreach ($leadFields as $alias => $properties) {
                if (isset($currentFields[$alias])) {
                    $value        = $currentFields[$alias];
                    $data[$alias] = $value;

                    // make sure the value is actually there and the field is one of our uniques
                    if (!empty($value) && array_key_exists($alias, $uniqueLeadFields)) {
                        $uniqueFieldsWithData[$alias] = $value;
                    }
                }
            }

            return ($uniqueOnly) ? $uniqueFieldsWithData : [$data, $uniqueFieldsWithData];
        };

        // Closure to help search for a conflict
        $checkForIdentifierConflict = function ($fieldSet1, $fieldSet2) {
            // Find fields in both sets
            $potentialConflicts = array_keys(
                array_intersect_key($fieldSet1, $fieldSet2)
            );

            $this->logger->debug(
                'DOIupdateLead: Potential conflicts '.implode(', ', array_keys($potentialConflicts)).' = '.implode(', ', $potentialConflicts)
            );

            $conflicts = [];
            foreach ($potentialConflicts as $field) {
                if (!empty($fieldSet1[$field]) && !empty($fieldSet2[$field])) {
                    if (strtolower($fieldSet1[$field]) !== strtolower($fieldSet2[$field])) {
                        $conflicts[] = $field;
                    }
                }
            }

            return [count($conflicts), $conflicts];
        };

        // Get data for the form submission
        [$data, $uniqueFieldsWithData] = $getData($currentFields);
        $this->logger->debug('DOIupdateLead: Unique fields submitted include '.implode(', ', $uniqueFieldsWithData));
    
        $existingLeads = (!empty($uniqueFieldsWithData)) ? $this->em->getRepository('MauticLeadBundle:Lead')->getLeadsByUniqueFields(
            $uniqueFieldsWithData,
            $lead->getId()
        ) : [];

        $uniqueFieldsCurrent = $getData($currentFields, true);
        
        if (count($existingLeads)) {
            $this->logger->debug(count($existingLeads).' found based on unique identifiers');

            /** @var \Mautic\LeadBundle\Entity\Lead $foundLead */
            $foundLead = $existingLeads[0];

            $this->logger->debug('Testing contact ID# '.$foundLead->getId().' for conflicts');

            // Get profile fields of the found lead
            $foundLeadFields = $foundLead->getProfileFields();

            $uniqueFieldsCurrent = $getData($currentFields, true);
            $uniqueFieldsFound   = $getData($foundLeadFields, true);
            [$hasConflict, $conflicts] = $checkForIdentifierConflict($uniqueFieldsFound, $uniqueFieldsCurrent);

            if ($hasConflict || !$lead->getId()) {
                $lead = $foundLead;
                if ($hasConflict) {
                    $this->logger->debug('Conflicts found in '.implode(', ', $conflicts).' so not merging');
                }
            } else {
                $this->logger->debug('Merging contacts '.$lead->getId().' and '.$foundLead->getId());
                $lead = $this->leadModel->mergeLeads($lead, $foundLead);
            }
        }
    }
}
