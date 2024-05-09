<?php

use CRM_Eventcustom_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Eventcustom_Utils {

  const customContactGroupName = 'eventcustom_cg_registration';
  static $customGroup;
  static $customFields;

  public static function getCustomGroup() {

    if (empty(static::$customGroup)) {
      $params = [
        'extends' => 'Event',
        'is_active' => 1,
        'name' => self::customContactGroupName,
        'return' => ['id', 'table_name'],
      ];

      static::$customGroup = civicrm_api3('CustomGroup', 'getsingle', $params);

      unset(static::$customGroup['extends']);
      unset(static::$customGroup['is_active']);
      unset(static::$customGroup['name']);
    }

    return static::$customGroup;
  }

  /**
   * Get information about the custom Activity fields
   *
   * @return array Multi-dimensional, keyed by lowercased custom field
   *         name (i.e., civicrm_custom_group.name). Subarray keyed with id (i.e.,
   *         civicrm_custom_group.id), column_name, custom_n, and data_type.
   */
  public static function getCustomFields() {
    if (empty(static::$customFields)) {
      $custom_group = static::getCustomGroup();

      $params = [
        'custom_group_id' => $custom_group['id'],
        'is_active' => 1,
        'return' => ['id', 'column_name', 'name', 'data_type'],
      ];

      $fields = civicrm_api3('CustomField', 'get', $params);

      if (CRM_Utils_Array::value('count', $fields) < 1) {
        CRM_Core_Error::fatal('Event Custom Extension - defined custom fields appear to be missing (custom field group' . self::customContactGroupName . ').');
      }

      foreach ($fields['values'] as $field) {
        static::$customFields[strtolower($field['name'])] = [
          'id' => $field['id'],
          'column_name' => $field['column_name'],
          'custom_n' => 'custom_' . $field['id'],
          'data_type' => $field['data_type'],
        ];
      }
    }

    return static::$customFields;
  }

  /**
   * @param $eventId
   * @param $eventField
   * @return array|int
   */
  static function getEventDetails($eventId, $details) {
    $eventDetail = [];
    try {
      $eventDetail = civicrm_api3('event', 'getsingle', [
        'id' => $eventId,
        'return' => [
          $details['eventcustom_cg_setting']['custom_n'],
          $details['eventcustom_cg_primary_contact_register']['custom_n'],
          $details['eventcustom_cf_relationship_types']['custom_n'],
          'title',
        ],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
    }

    return $eventDetail;
  }

  /**
   * @return array
   */
  public static function relationshipTypes() {
    $result = civicrm_api3('RelationshipType', 'get', [
      'sequential' => 1,
      'is_active' => 1,
      'options' => ['limit' => 0],
    ]);


    $relationshipTypes = [];
    foreach ($result['values'] as $type) {
      if ($type['label_a_b'] == $type['label_b_a']) {
        $relationshipTypes[$type['id']] = $type['label_a_b'];
      }
      else {
        $relationshipTypes[$type['id'] . '_a_b'] = $type['label_a_b'];
        $relationshipTypes[$type['id'] . '_b_a'] = $type['label_b_a'];
      }
    }

    return $relationshipTypes;
  }

  /**
   * @param $form
   * @return array
   */
  public static function relatedContactsListing($form) {
    $group_members = [];

    // Get logged in user Contact ID
    $userID = $form->getLoggedInUserContactID();
    $eventId = $form->getVar('_eventId');

    $primary_contact_params = [
      'version' => '3',
      'id' => $userID,
    ];
    // Get all Contact Details for logged in user
    $civi_primary_contact = civicrm_api('Contact', 'getsingle', $primary_contact_params);
    $civi_primary_contact['display_name'] .= ' (you)';
    $group_members[$userID] = $civi_primary_contact;

    $customFields = CRM_Eventcustom_Utils::getCustomFields();
    $customSettings = CRM_Eventcustom_Utils::getEventDetails($eventId, $customFields);
    if (empty($customSettings[$customFields['eventcustom_cf_relationship_types']['custom_n']])) {
      return;
    }
    $relationships = $customSettings[$customFields['eventcustom_cf_relationship_types']['custom_n']];
    $rab = [];
    $rba = [];

    // parents can only register for events that allow it
    $parents_can_register = self::canParentRegisterforEvent($eventId);
    foreach ($relationships as $r) {
      @ list($rType, $dir) = explode("_", $r, 2);
      if ($dir == NULL) {
        $rab[] = $rType;
        $rba[] = $rType;
      }
      elseif ($dir = "a_b") {
        $rab[] = $rType;
      }
      else {
        $rba[] = $rType;
      }
    }

    $contactIds = [$userID];
    if (!empty($rab)) {
      $relationshipsCurrentUserOnBSide = civicrm_api3('Relationship', 'get', [
        'return' => ["contact_id_a"],
        'contact_id_b' => "user_contact_id",
        'is_active' => TRUE,
        'relationship_type_id' => ['IN' => $rab]
      ]);
      foreach ($relationshipsCurrentUserOnBSide['values'] as $rel) {
        $contactIds[] = $rel['contact_id_a'];
      }
    }
    if (!empty($rba)) {
      $relationshipsCurrentUserOnASide = civicrm_api3('Relationship', 'get', [
        'return' => ["contact_id_b"],
        'contact_id_a' => "user_contact_id",
        'is_active' => TRUE,
        'relationship_type_id' => ['IN' => $rba]
      ]);
      foreach ($relationshipsCurrentUserOnASide['values'] as $rel) {
        $contactIds[] = $rel['contact_id_b'];
      }
    }

    //make it a unique list of contacts
    $contactIds = array_unique($contactIds);
    $returnField = ["display_name", "group"];

    $spouse_of_id = civicrm_api3('RelationshipType', 'getvalue', [
      'name_a_b' => 'Spouse of',
      'return' => 'id',
    ]);
    $couple = [$userID, 0];

    // Get all related Contacts for this user
    foreach ($contactIds as $cid) {
      // only look for parent / child relationship
      try {
        $contactDataResult = civicrm_api("Contact", "get", [
            'return' => $returnField,
            'version' => 3,
            'id' => $cid,
            'is_deleted' => 0]
        );
        if (!empty($contactDataResult['values'])) {
          $group_members[$cid] = $contactDataResult['values'][$cid];
        }
        else {
          continue;
        }
      }
      catch (CiviCRM_API3_Exception $exception) {
        continue;
      }

      $group_members[$cid]['is_parent'] = FALSE;
      if ($userID == $cid) {
        $group_members[$cid]['display_name'] .= ' (you)';
        $group_members[$cid]['is_parent'] = TRUE;
      }
      else {
        $couple[1] = $cid;
        $count = civicrm_api3('Relationship', 'getcount', [
          'relationship_type_id' => $spouse_of_id,
          'is_active' => 1,
          'contact_id_a' => [
            'IN' => $couple
          ],
          'contact_id_b' => [
            'IN' => $couple
          ]
        ]);
        if ($count) {
          $group_members[$cid]['is_parent'] = TRUE;
        }
      }

      // For parent membership is not required, they can register for event
      // if its allowed through event custom setting.
      if ($parents_can_register && $group_members[$cid]['is_parent']) {
        $group_members[$cid]['skip_registration'] = FALSE;
        $group_members[$cid]['explanation'] = '';
      }
      else {
        //$group_members[$cid]['skip_registration'] = TRUE;
        $group_members[$cid]['explanation'] = '';
      }

    }
    foreach ($group_members as $cid => $contactDetails) {
      if ($contactDetails['is_parent']) {
        if (!$parents_can_register && array_key_exists($cid, $group_members)) {
          $group_members[$cid]['skip_registration'] = TRUE;
          $group_members[$cid]['explanation'] = 'Parents cannot register for this event';
        }
      }
    }

    return $group_members;
  }

  public static function canParentRegisterforEvent($eventId) {
    $result = civicrm_api3('Event', 'get', [
      'id' => $eventId,
    ]);
    $eventDetails = $result['values'][$eventId];

    try {
      $fid = civicrm_api3('CustomField', 'getvalue', [
        'custom_group_id' => 'eventcustom_cg_registration',
        'name' => 'eventcustom_cg_primary_contact_register',
        'return' => 'id',
      ]);
      $parents_can_register = !empty($eventDetails["custom_$fid"]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $parents_can_register = FALSE;
    }

    return $parents_can_register;
  }

  /**
   * @param $form
   * @return array
   */
  public static function contactSequenceForRegistration($form) {
    $params = $form->getVar('_params');
    $currentContactID = $form->getLoggedInUserContactID();
    $childContacts = $childSortContacts = $parentContacts = [];
    foreach ($params[0] as $k => $v) {
      if (strpos($k, 'contacts_child_') === 0) {
        [, , $cid] = explode('_', $k);
        $childContacts[$cid] = $cid;
      }
      elseif (strpos($k, 'contacts_parent_') === 0) {
        [, , $cid] = explode('_', $k);
        $parentContacts[$cid] = $cid;
      }
    }

    unset($parentContacts[$currentContactID]);
    sort($childContacts);
    $i = 1;
    foreach ($childContacts as $cid) {
      $childSortContacts[$i] = $cid;
      $i++;
    }
    $finalContactList = [];

    $i = 1;
    foreach ($parentContacts + $childContacts as $cid) {
      $finalContactList[$i] = $cid;
      $i++;
    }

    return [$finalContactList, $childSortContacts, $parentContacts];
  }

  /**
   *  Helper function that fetches a specified list of fields
   *  for a given contact or list of contacts.
   *
   * @param $fields
   * @param null $contactIds
   * @return bool|array
   */
  public static function getContactData($fields, $contactIds = NULL) {
    $params = [
      'is_deceased' => FALSE,
      'is_deleted' => FALSE,
    ];

    $params['id'] = is_array($contactIds) ? ['IN' => $contactIds] : ['IN' => [$contactIds]];

    //todo: Some massage of fields to fetch all requested data
    $fieldsToFetch = $fields;
    $fieldMapping = [];
    $relatedObjects = [];
    foreach ($fieldsToFetch as &$fieldName) {
      @ list($field, $location, $type) = explode("-", $fieldName);

      if ($location == "Primary") {
        if (strpos($field, "custom") !== FALSE) {
          $objectType = substr($field, 0, strpos($field, "_"));

          if (!array_key_exists($objectType, $relatedObjects)) {
            $relatedObjects[$objectType] = [];
          }

          if (!array_key_exists($location, $relatedObjects[$objectType])) {
            $relatedObjects[$objectType][$location] = [];
          }

          $relatedObjects[$objectType][$location][$field] = $fieldName;

        }
        else {
          $fieldMapping[$field] = $fieldName;
          $fieldName = $field;
        }

      }
      elseif (is_numeric($location)) {

        if (strpos($field, "custom") !== FALSE) {
          $objectType = substr($field, 0, strpos($field, "_"));
        }
        else {
          if ($field == "phone" || $field == "email") {
            $objectType = $field;
          }
          else {
            $objectType = "address";
          }
        }


        if (!array_key_exists($objectType, $relatedObjects)) {
          $relatedObjects[$objectType] = [];
        }

        if (!array_key_exists($location, $relatedObjects[$objectType])) {
          $relatedObjects[$objectType][$location] = [];
        }

        $relatedObjects[$objectType][$location][$field] = $fieldName;

      }
    }

    //Add some API Chaining for related objects that wont be auto-fetched via Contact.get
    foreach ($relatedObjects as $entityType => $locationTypeData) {
      $locationTypes = array_keys($locationTypeData);
      $chainKey = "api." . ucfirst($entityType) . ".get";
      if (in_array("Primary", $locationTypes)) {
        $params[$chainKey] = [];
      }
      else {
        $params[$chainKey] = ["location_type_id" => ["IN" => $locationTypes]];
      }
    }
    // SUP-1707 exclude participant fields
    $api = civicrm_api3('CustomGroup', 'get', [
      'extends' => 'Participant',
      'is_active' => 1,
      'return' => 'id',
    ]);
    $group_ids = array_keys($api['values']);

    $field_ids = [];
    foreach ($fieldsToFetch as $ftf) {
      if (strpos($ftf, 'custom_') === 0) {
        $field_ids[] = substr($ftf, 7);
      }
    }
    try {
      $api = civicrm_api3('CustomField', 'get', [
        'id' => [
          'IN' => $field_ids,
        ],
        'custom_group_id' => [
          'IN' => $group_ids,
        ],
        'return' => 'id',
      ]);
    }
    catch (Exception $e) {
      // probably no custom fields, which is fine.
      trigger_error($e->getMessage());
    }
    $field_ids = [];
    foreach (array_keys($api['values']) as $id) {
      $field_ids[] = "custom_$id";
    }
    // SUP-1707
    //exclude Participant Fields from data to pre-populate the form
    $params['return'] = array_diff($fieldsToFetch, $field_ids);

    //Fetch the data
    $result = civicrm_api3('Contact', 'get', $params);

    if ($result['is_error'] == 0 && $result['count'] > 0) {
      $contacts = [];
      foreach ($result['values'] as $cid => $data) {
        foreach ($fieldMapping as $name => $oldName) {
          if (array_key_exists($name, $data) && !array_key_exists($oldName, $data)) {
            $data[$oldName] = $data[$name];
          }
        }

        //Mix in the related objects data.
        foreach ($relatedObjects as $entity => $locationTypeData) {
          $chainKey = "api." . ucfirst($entity) . ".get";
          foreach ($data[$chainKey]['values'] as $entityData) {
            if (array_key_exists($entityData['location_type_id'], $locationTypeData)) {
              foreach ($locationTypeData[$entityData['location_type_id']] as $name => $oldName) {
                if (!array_key_exists($oldName, $data)) {
                  if (array_key_exists($name, $entityData)) {
                    $data[$oldName] = $entityData[$name];
                  }
                  elseif (array_key_exists($name . "_id", $entityData)) {
                    $data[$oldName] = $entityData[$name . "_id"];
                  }
                }
              }
            }

            if ($entityData['is_primary'] == 1 && array_key_exists("Primary", $locationTypeData)) {
              foreach ($locationTypeData["Primary"] as $name => $oldName) {
                if (!array_key_exists($oldName, $data)) {
                  if (array_key_exists($name, $entityData)) {
                    $data[$oldName] = $entityData[$name];
                  }
                  elseif (array_key_exists($name . "_id", $entityData)) {
                    $data[$oldName] = $entityData[$name . "_id"];
                  }
                }
              }
            }
          }
        }

        //Only return the fields that were asked for.
        $contacts[$cid] = array_intersect_key($data, array_flip($fields));
      }

      //Decide what to return and in what format
      if (!is_array($contactIds)) {
        return $contacts[$contactIds];
      }

      return $contacts;
    }

    return FALSE;
  }

  /**
   * @param null $priceSetId
   * @return array
   */
  public static function getPriceSetsOptions($priceSetId = NULL) {
    $values = self::getPriceSetsInfo($priceSetId);

    $priceSets = [];
    if (!empty($values)) {
      $currentLabel = NULL;
      $optGroup = 0;
      foreach ($values as $set) {
        // Quickform doesn't support optgroups so this uses a hack. @see js/Common.js in core
        if ($currentLabel !== $set['ps_label']) {
          //$priceSets['crm_optgroup_' . $optGroup++] = $set['ps_label'];
        }
        $priceSets[$set['item_id']] = "{$set['pf_label']} :: {$set['item_label']}";
        $currentLabel = $set['ps_label'];
      }
    }

    return $priceSets;
  }

  /**
   * @param null $priceSetId
   * @return array
   */
  public static function getPriceSetsInfo($priceSetId = NULL) {
    $params = [];
    $psTableName = 'civicrm_price_set_entity';
    if ($priceSetId) {
      $additionalWhere = 'ps.id = %1';
      $params = [1 => [$priceSetId, 'Positive']];
    }
    else {
      $additionalWhere = 'ps.is_quick_config = 0';
    }

    $sql = "
      SELECT    pfv.id as item_id,
                pfv.label as item_label,
                pf.label as pf_label,
                ps.title as ps_label
      FROM      civicrm_price_field_value as pfv
      LEFT JOIN civicrm_price_field as pf on (pf.id = pfv.price_field_id AND pf.is_active  = 1 AND pfv.is_active = 1)
      LEFT JOIN civicrm_price_set as ps on (ps.id = pf.price_set_id AND ps.is_active = 1)
      INNER JOIN {$psTableName} as pse on (ps.id = pse.price_set_id)
      WHERE  {$additionalWhere}
      ORDER BY  pf_label, pfv.price_field_id, pfv.weight
      ";

    $dao = CRM_Core_DAO::executeQuery($sql, $params);
    $priceSets = [];
    while ($dao->fetch()) {
      $priceSets[$dao->item_id] = [
        'item_id' => $dao->item_id,
        'item_label' => $dao->item_label,
        'pf_label' => $dao->pf_label,
        'ps_label' => $dao->ps_label,
      ];
    }

    return $priceSets;
  }
}
