<?php

require_once 'eventcustom.civix.php';

use CRM_Eventcustom_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function eventcustom_civicrm_config(&$config): void {
  _eventcustom_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function eventcustom_civicrm_install(): void {
  _eventcustom_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function eventcustom_civicrm_enable(): void {
  _eventcustom_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed
 */
function eventcustom_civicrm_managed(&$entities) {
  _eventcustom_civix_civicrm_managed($entities);
  $entities[] = [
    'module' => 'com.skvare.eventcustom',
    'name' => 'eventcustom_cg_registration',
    'entity' => 'CustomGroup',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'eventcustom_cg_registration',
      'title' => ts('Event Custom Registration'),
      'extends' => 'Event',
      'style' => 'Inline',
      'is_active' => TRUE,
      'is_public' => FALSE,
      'is_reserved' => 1,
      'options' => ['match' => ['name']],
    ],
  ];
  $entities[] = [
    'module' => 'com.skvare.eventcustom',
    'name' => 'eventcustom_cg_setting',
    'entity' => 'CustomField',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'eventcustom_cg_setting',
      'label' => ts('Enable Custom Signup Process?'),
      'data_type' => 'Boolean',
      'html_type' => 'Radio',
      'is_active' => TRUE,
      'weight' => 1,
      'custom_group_id' => 'eventcustom_cg_registration',
      'options' => ['match' => ['name', 'custom_group_id']],
    ],
  ];
  $entities[] = [
    'module' => 'com.skvare.eventcustom',
    'name' => 'eventcustom_cf_relationship_types',
    'entity' => 'CustomField',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'eventcustom_cf_relationship_types',
      'label' => ts('Relationship Type(s)'),
      'help_post' => ts('Relationship type used to pull related contacts.'),
      'data_type' => 'String',
      'html_type' => 'Select',
      'is_active' => TRUE,
      'serialize' => TRUE,
      'text_length' => 255,
      'weight' => 3,
      'option_type' => 0,
      'custom_group_id' => 'eventcustom_cg_registration',
      'options' => ['match' => ['name', 'custom_group_id']],
    ],
  ];


  $entities[] = [
    'module' => 'com.skvare.eventcustom',
    'name' => 'eventcustom_cg_primary_contact_register',
    'entity' => 'CustomField',
    'update' => 'never',
    'params' => [
      'version' => 3,
      'name' => 'eventcustom_cg_primary_contact_register',
      'label' => ts('Allow Primary Participant to register?'),
      'data_type' => 'Boolean',
      'html_type' => 'Radio',
      'is_active' => TRUE,
      'weight' => 2,
      'custom_group_id' => 'eventcustom_cg_registration',
      'options' => ['match' => ['name', 'custom_group_id']],
    ],
  ];
}

function eventcustom_civicrm_buildForm($formName, &$form) {
  if ($formName == "CRM_Event_Form_ManageEvent_EventInfo" && ($form->get("action") == CRM_Core_Action::ADD || $form->get("action") == CRM_Core_Action::UPDATE)) {
    $customFields = CRM_Eventcustom_Utils::getCustomFields();
    $customFieldForRel = $customFields['eventcustom_cf_relationship_types']['custom_n'];
    if (array_key_exists($customFieldForRel, $form->_elementIndex)) {
      $form->removeElement($customFieldForRel);
    }
    $form->add('select', $customFieldForRel, 'Relationship type',
      CRM_Eventcustom_Utils::relationshipTypes(),
      TRUE, ['class' => 'crm-select2', 'multiple' => 'multiple', 'placeholder' => ts('- any -')]);
  }
  elseif ($formName == 'CRM_Event_Form_Registration_Register') {
    $eid = $form->getVar('_eventId');
    $customFields = CRM_Eventcustom_Utils::getCustomFields();
    $eventCustomDetails = CRM_Eventcustom_Utils::getEventDetails($eid,
      $customFields);
    if (empty($eventCustomDetails[$customFields['eventcustom_cg_setting']['custom_n']])) {
      return;
    }
    $currentContactID = $form->getLoggedInUserContactID();
    $relatedContacts = CRM_Eventcustom_Utils::relatedContactsListing($form);
    $isPaid = TRUE;
    foreach ($relatedContacts as $contactID => $contact) {
      if ($contact['is_parent']) {
        $attribute = [];
        if ($contactID == $currentContactID) {
          $attribute = ['class' => 'currentUser'];
        }
        $element = $form->add('checkbox', "contacts_parent_{$contactID}",
          $contact['display_name'], NULL, FALSE, $attribute);
        if ($isPaid && !$contact['skip_registration'] && $contactID == $currentContactID) {
          $setDefaultForParent = ["contacts_parent_{$contactID}" => 1];
          $form->setDefaults($setDefaultForParent);
        }
      }
      else {
        $element = $form->add('checkbox', "contacts_child_{$contactID}", $contact['display_name']);
      }
      if ($contact['skip_registration'] || !$isPaid) {
        $element->freeze();
      }
    }
    $form->assign('relatedContacts', $relatedContacts);
    $form->assign('currentContactID', $currentContactID);
    if (CRM_Utils_System::isUserLoggedIn()) {
      CRM_Core_Region::instance('page-body')->add(['template' => 'CRM/Eventcustom/ContactListing.tpl']);
    }
    elseif ($formName == 'CRM_Event_Form_Registration_AdditionalParticipant') {
      $eid = $form->getVar('_eventId');
      $customFields = CRM_Eventcustom_Utils::getCustomFields();
      $eventCustomDetails = CRM_Eventcustom_Utils::getEventDetails($eid, $customFields);
      if (empty($eventCustomDetails[$customFields['eventcustom_cg_setting']['custom_n']])) {
        return;
      }

      if (!$form->_values['event']['is_monetary']) {
        [$finalContactList, $childContacts, $parentContacts] = CRM_Eventcustom_Utils::contactSequenceForRegistration($form);
        [$dontCare, $additionalPageNumber] = explode('_', $form->getVar('_name'));
        $contactID = $finalContactList[$additionalPageNumber];
        $childNumber = 0;
        if (in_array($contactID, $childContacts)) {
          $childNumber = CRM_Utils_Array::key($contactID, $childContacts);
        }
        $_params = $form->get('params');
        $_name = $form->getVar('_name');
        $participantNo = substr($_name, 12);
        $participantCnt = $participantNo;
        $participantTot = $_params[0]['additional_participants'];
        CRM_Utils_System::setTitle(ts('Register Child %1 of %2', [1 => $participantCnt, 2 => $participantTot]));
      }
      [$finalContactList, $childContacts, $parentContacts] = CRM_Eventcustom_Utils::contactSequenceForRegistration($form);
      [$dontCare, $additionalPageNumber] = explode('_', $form->getVar('_name'));
      $contactID = $finalContactList[$additionalPageNumber];
      $data = CRM_Eventcustom_Utils::getContactData(array_keys($form->_fields), $contactID);
      $form->setDefaults($data);
    }
    elseif (in_array($formName, ['CRM_Event_Form_Registration_Confirm', 'CRM_Event_Form_Registration_ThankYou'])) {
      $eid = $form->getVar('_eventId');
      $customFields = CRM_Eventcustom_Utils::getCustomFields();
      $eventCustomDetails = CRM_Eventcustom_Utils::getEventDetails($eid, $customFields);
      if (empty($eventCustomDetails[$customFields['eventcustom_cg_setting']['custom_n']])) {
        return;
      }
      $parentCanRegister = CRM_Eventcustom_Utils::getEventDetails($eid, $customFields);
      $canParentRegister = $parentCanRegister[$customFields['eventcustom_cg_primary_contact_register']['custom_n']];
      if ($formName == 'CRM_Event_Form_Registration_ThankYou') {
        $eid = $form->getVar('_eventId');
        if (!empty($canParentRegister)) {
          if ($form->getVar('_participantId')) {
            $result = civicrm_api3('ParticipantStatusType', 'getvalue', [
              'return' => "id",
              'name' => "not_attending",
            ]);
            CRM_Core_DAO::setFieldValue('CRM_Event_DAO_Participant', $form->getVar('_participantId'), 'status_id', $result);
          }
        }
      }

      $params = $form->getVar('_params');
      // To avoid confusion, changing removing parent name as first participant
      // and changing the labels too.
      if (!$canParentRegister) {
        $template = CRM_Core_Smarty::singleton();
        $part = $template->get_template_vars('part');
        $part[0]['info'] = ' ( Parent will be register as Non Attending Participant.)';
        $template->assign('part', $part);
      }
    }
  }
}

function eventcustom_civicrm_buildAmount($pageType, &$form, &$amounts) {
  if ((!$form->getVar('_action')
      || ($form->getVar('_action') & CRM_Core_Action::PREVIEW)
      || ($form->getVar('_action') & CRM_Core_Action::ADD)
      || ($form->getVar('_action') & CRM_Core_Action::UPDATE)
    )
    && !empty($amounts) && is_array($amounts) && ($pageType == 'event')) {

    $formName = get_class($form);
    if (!in_array(get_class($form), ['CRM_Event_Form_Registration_Register'])) {
      return;
    }
    $eid = $form->getVar('_eventId');
    $customFields = CRM_Eventcustom_Utils::getCustomFields();
    $eventCustomDetails = CRM_Eventcustom_Utils::getEventDetails($eid, $customFields);
    if (empty($eventCustomDetails[$customFields['eventcustom_cg_setting']['custom_n']])) {
      return;
    }
    $currentContactID = $form->getLoggedInUserContactID();
    $parents_can_register = $eventCustomDetails[$customFields['eventcustom_cg_primary_contact_register']['custom_n']];
    if (!$parents_can_register) {
      $amount = 0;
    }
    $psid = $form->get('priceSetId');
    $getPriceSetsInfo = CRM_Eventcustom_Utils::getPriceSetsInfo($psid);

    $originalAmounts = $amounts;
    foreach ($amounts as $fee_id => &$fee) {
      if (!is_array($fee['options'])) {
        continue;
      }
      foreach ($fee['options'] as $option_id => &$option) {
        if (array_key_exists($option_id, $getPriceSetsInfo)) {
          if ($formName == 'CRM_Event_Form_Registration_Register' &&
            !$parents_can_register) {
            $option['amount']  = 0;
          }
          elseif ($formName == 'CRM_Event_Form_Registration_Register' &&
            !empty($form->_submitValues) &&
            empty($form->_submitValues['contacts_parent_' . $currentContactID])) {
            $option['amount']  = 0;
          }

          // Re-calculate VAT/Sales TAX on discounted amount.
          if (array_key_exists('tax_amount', $originalAmounts[$fee_id]['options'][$option_id]) &&
            array_key_exists('tax_rate', $originalAmounts[$fee_id]['options'][$option_id])
          ) {
            $recalculateTaxAmount = CRM_Contribute_BAO_Contribution_Utils::calculateTaxAmount($amount, $originalAmounts[$fee_id]['options'][$option_id]['tax_rate']);
            if (!empty($recalculateTaxAmount)) {
              $option['tax_amount'] = round($recalculateTaxAmount['tax_amount'], 2);
            }
          }
        }
      }
    }
  }
}

function eventinvite_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if (in_array($formName, ['CRM_Event_Form_Registration_Register'])) {
    $eid = $form->getVar('_eventId');
    $customFields = CRM_Eventcustom_Utils::getCustomFields();
    $eventCustomDetails = CRM_Eventcustom_Utils::getEventDetails($eid, $customFields);
    if (empty($eventCustomDetails[$customFields['eventcustom_cg_setting']['custom_n']])) {
      return;
    }
    if (empty($eventCustomDetails[$customFields['eventcustom_cg_primary_contact_register']['custom_n']])) {
      return;
    }
    //cycle through and remove required validation on all price fields
    if (empty($eventCustomDetails[$customFields['eventcustom_cg_primary_contact_register']['custom_n']])) {
      foreach ($form->_priceSet['fields'] as $fid => $val) {
        $form->setElementError('price_' . $fid, NULL);
        $fields['price_' . $fid] = NULL;
      }

      $form->_lineItem = [];
      $form->setElementError('_qf_default', NULL);
    }
  }
}

