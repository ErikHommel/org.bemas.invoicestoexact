<?php
/**
 * Class to process contribution search task Send Invoices to Exact
 *
 * @author Erik Hommel <hommel@ee-atwork>
 * @date 21 Dec 2017
 * @license AGPL-3.0
 */

class CRM_Invoicestoexact_Form_Task_InvoiceExact extends CRM_Contribute_Form_Task {
  private $_data = [];
  private $_correctElements = [];
  private $_errorElements = [];

  private $_orgExactIdColumn = '';
  private $_orderNumberColumn = '';
  private $_orgDetTableName = '';
  private $_partDetTableName = '';
  private $_partExactIdColumn = '';
  private $_partPOColumn = '';
  private $_eventDetTableName = '';
  private $_eventFoodCostColumn = '';
  private $_eventBeverageCostColumn = '';
  private $_eventNumDaysColumn = '';
  private $_contDataTableName = '';
  private $_invoiceOptionGroupId = '';

  public function __construct() {
    // get custom fields
    $this->_orgExactIdColumn = CRM_Invoicestoexact_Config::singleton()->getPopsyIdCustomField('column_name');
    $this->_orderNumberColumn = CRM_Invoicestoexact_Config::singleton()->getExactOrderNumberCustomField('column_name');
    $this->_orgDetTableName = CRM_Invoicestoexact_Config::singleton()->getOrganizationDetailsCustomGroup('table_name');
    $this->_contDataTableName = CRM_Invoicestoexact_Config::singleton()->getContributionDataCustomGroup('table_name');
    $this->_invoiceOptionGroupId = CRM_Invoicestoexact_Config::singleton()->getItemsExactOptionGroup('id');
    $this->_partDetTableName = CRM_Invoicestoexact_Config::singleton()->getParticipantDetailsCustomGroup('table_name');
    $this->_partExactIdColumn = CRM_Invoicestoexact_Config::singleton()->getParticipantExactIdCustomField('column_name');
    $this->_partPOColumn = CRM_Invoicestoexact_Config::singleton()->getParticipantPOCustomField('column_name');
    $this->_eventDetTableName = CRM_Invoicestoexact_Config::singleton()->getEventDetailsCustomGroup('table_name');
    $this->_eventFoodCostColumn = CRM_Invoicestoexact_Config::singleton()->getEventFoodCostCustomField('column_name');
    $this->_eventBeverageCostColumn = CRM_Invoicestoexact_Config::singleton()->getEventBeverageCostCustomField('column_name');
    $this->_eventNumDaysColumn = CRM_Invoicestoexact_Config::singleton()->getEventNumDaysCustomField('column_name');
    parent::__construct();
  }

  /**
   * Method to build the form
   */
  public function buildQuickForm() {
    // preprocess all selected contributions and store in this->_correctElements and _errorElements
    $this->buildData();

    $this->assign('correctElements', $this->_correctElements);
    $this->assign('errorElements', $this->_errorElements);

    $this->addButtons([
      ['type' => 'next', 'name' => ts('Confirm'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => ts('Cancel')]
    ]);
  }

  /**
   * Method to build the form element for a correct contribution
   *
   * @param $contributionId
   */
  private function buildCorrectElement($contributionId) {
    $contactElement = 'contact_' . $contributionId;
    $dataElement = 'data_' . $contributionId;
    $this->_correctElements[$contributionId] = [
      'contact' => $contactElement,
      'data' => $dataElement,
    ];
    $this->add('text', $contactElement);
    $this->add('text', $dataElement);
  }

  /**
   * Method to set default values
   *
   * @return array|NULL
   */
  public function setDefaultValues() {
    $defaults = [];
    foreach ($this->_correctElements as $correctId => $correct) {
      $defaults[$correct['contact']] = $this->_data[$correctId]['display_name'];
      $defaults[$correct['data']] = 'Contact code: ' . $this->_data[$correctId]['contact_code'] . ', item code: ' .
        $this->_data[$correctId]['item_code'] . ' met bedrag ' . CRM_Utils_Money::format($this->_data[$correctId]['unit_price']);
    }
    foreach ($this->_errorElements as $errorId => $error) {
      if ($this->_data[$errorId]['entity_table'] == 'civicrm_membership') {
        $entityLine = 'Lidmaatschap';
      }
      else if ($this->_data[$errorId]['entity_table'] == 'civicrm_participant') {
        $entityLine = '- deelname BEMAS evenement';
      }
      else {
        $entityLine = '';
      }
      $defaults[$error['contact']] = $this->_data[$errorId]['display_name'] . ' ' . $entityLine;
      $defaults[$error['message']] = $this->_data[$errorId]['error_message'];
    }
    return $defaults;
  }

  /**
   * Method to build the form element for an error contribution
   *
   * @param $contributionId
   */
  private function buildErrorElement($contributionId) {
    $contactElement = 'contact_' . $contributionId;
    $messageElement = 'message_' . $contributionId;
    $this->_errorElements[$contributionId] = [
      'contact' => $contactElement,
      'message' => $messageElement,
    ];
    $this->add('text', $contactElement);
    $this->add('text', $messageElement);
  }

  /**
   * Method to build the relevant data for all potential invoices
   */
  private function buildData() {
    $canInvoiceBeSent = FALSE;

    if (count($this->_contributionIds) > 0) {
      // get all selected contributions
      $sql = "
        SELECT
          c.id contribution_id
          , ft.name financial_type
        FROM 
          civicrm_contribution c 
        INNER JOIN          
          civicrm_financial_type ft on c.financial_type_id = ft.id
        WHERE 
          c.id IN (" . implode(', ', $this->_contributionIds) . ')';
      $dao = CRM_Core_DAO::executeQuery($sql);
      while ($dao->fetch()) {
        // process the contribution based on its financial type
        if ($dao->financial_type == 'Member Dues') {
          $canInvoiceBeSent = $this->buildDataMembership($dao->contribution_id);
        }
        elseif ($dao->financial_type == 'Event Fee') {
          $canInvoiceBeSent = $this->buildDataParticipant($dao->contribution_id);
        }
        else {
          $this->_data[$dao->contribution_id]['error_message'] = 'Het financieel type van de bijdrage (' . $dao->financial_type . ') wordt nog niet ondersteund.';
          $canInvoiceBeSent = FALSE;
        }

        // store the contrib in the "Correct" or "Error" array
        if ($canInvoiceBeSent) {
          // OK, the contrib is complete
          $this->buildCorrectElement($dao->contribution_id);
        }
        else {
          // Nope, the contrib is not ready for invoicing
          $this->buildErrorElement($dao->contribution_id);
          if (($key = array_search($dao->contribution_id, $this->_contributionIds)) !== FALSE) {
            unset($this->_contributionIds[$key]);
          }
        }
      }
    }
  }

  private function buildDataParticipant($contributionID) {
    $retval = TRUE;

    // get the contribution and the participant-related data
    $sql = "
      SELECT
        c.id contribution_id
        , contact_a.id contact_id
        , concat(contact_a.first_name, ' ', contact_a.last_name) participant_name
        , empl.id employer_id
        , empl.organization_name employer_name
        , empl_det.{$this->_orgExactIdColumn} employer_exact_id
        , part_det.{$this->_partExactIdColumn} participant_exact_id
        , part_det.{$this->_partPOColumn} participant_po_number
        , c_det.{$this->_orderNumberColumn} order_number
        , e.title event_title
        , p.id participant_id
        , p.fee_amount event_all_in_price
        , p.role_id
        , p.status_id
        , ifnull(e_det.{$this->_eventFoodCostColumn}, 0) event_food_price
        , ifnull(e_det.{$this->_eventBeverageCostColumn}, 0) event_beverage_price
        , ifnull(e_det.{$this->_eventNumDaysColumn}, 1) event_num_days
      FROM
        civicrm_contribution c 
      INNER JOIN
        civicrm_contact contact_a on contact_a.id = c.contact_id
      INNER JOIN
        civicrm_participant_payment pp on pp.contribution_id = c.id
      INNER JOIN
        civicrm_participant p on p.id = pp.participant_id        
      LEFT OUTER JOIN
        civicrm_contact empl on empl.id = contact_a.employer_id
      LEFT OUTER JOIN
        {$this->_orgDetTableName} empl_det ON empl.id = empl_det.entity_id
      LEFT OUTER JOIN
        {$this->_partDetTableName} part_det ON p.id = part_det.entity_id
      LEFT OUTER JOIN
        civicrm_event e on e.id = p.event_id
      LEFT OUTER JOIN
        {$this->_eventDetTableName} e_det ON e.id = e_det.entity_id        
      LEFT OUTER JOIN
        {$this->_contDataTableName} c_det ON c.id = c_det.entity_id
      WHERE
        c.id = $contributionID 
    ";

    try {
      $dao = CRM_Core_DAO::executeQuery($sql);
      if ($dao->fetch()) {
        // validate the contribution
        if ($dao->role_id != 1) {
          throw new Exception('Rol <> deelnemer');
        }
        if ($dao->status_id != 1 && $dao->status_id != 2) {
          throw new Exception('Status <> ingeschreven of attended');
        }
        if ($dao->order_number) {
          throw new Exception('Reeds gefactureerd');
        }
        if ($dao->event_all_in_price == 0 || ($dao->event_all_in_price - $dao->event_food_price - $dao->event_beverage_price == 0)) {
          throw new Exception('Gratis deelname');
        }
        if ($dao->event_all_in_price - $dao->event_food_price - $dao->event_beverage_price < 0) {
          throw new Exception( 'Deelnameprijs min de cateringkost is negatief');
        }
        if (empty($dao->participant_exact_id) && empty($dao->employer_exact_id)) {
          throw new Exception( 'Geen Exact klant ID ingevuld bij werkgever of deelname');
        }

        // get the registrations linked to this one
        $extraParticipantCount = $this->getLinkedParticipantCount($dao->participant_id);
        $participantList = $this->getFormattedParticipantList($dao->participant_id, $dao->participant_name, $dao->employer_name);

        // get the event code and add the line items
        $eventExactCodes = $this->getExactEventAndCateringCodes($dao->event_title);
        $this->addOrReplaceLineItems($contributionID, $eventExactCodes, $dao->event_all_in_price, $dao->event_food_price, $dao->event_beverage_price, $dao->event_num_days, $extraParticipantCount);

        // add the PO, exact id of the payer, and invoice description
        $f = CRM_Invoicestoexact_Config::singleton()->getContributionExactIDCustomField('id');
        $this->saveContributionCustomData($f,$dao->participant_exact_id ? $dao->participant_exact_id : $dao->employer_exact_id, $contributionID);

        $f = CRM_Invoicestoexact_Config::singleton()->getContributionPOCustomfield('id');
        $this->saveContributionCustomData($f, $dao->participant_po_number, $contributionID);

        $f = CRM_Invoicestoexact_Config::singleton()->getContributionCommentCustomfield('id');
        $this->saveContributionCustomData($f, $participantList, $contributionID);

        $f = CRM_Invoicestoexact_Config::singleton()->getContributionDescriptionCustomfield('id');
        $this->saveContributionCustomData($f, 'BEMAS - ' . $eventExactCodes['event_code'], $contributionID);

        // store in array of "good" contributions
        $this->_data[$dao->contribution_id] = [
          'display_name' => $dao->participant_name . ' (' . $dao->employer_name . ')',
          'entity_table' => 'civicrm_participant',
          'invoice_description' => $dao->participant_po_number,
          'unit_price' => $dao->event_all_in_price . ', waarvan € ' . $dao->event_food_price . ' eten en € '.  $dao->event_beverage_price . ' drank',
          'contact_code' => $dao->participant_exact_id ? $dao->participant_exact_id : $dao->employer_exact_id,
          'item_code' => $eventExactCodes['event_code'],
        ];
      }
      else {
        throw new Exception('Bijdrage en aanverwante info niet gevonden');
      }
    }
    catch (Exception $e) {
      // set error message
      $this->_data[$dao->contribution_id] = [
        'display_name' => $dao->participant_name . ' (' . $dao->employer_name . ')',
        'entity_table' => 'civicrm_participant',
        'invoice_description' => $dao->participant_po_number,
        'unit_price' => $dao->event_all_in_price . ', waarvan € ' . $dao->event_food_price . ' eten en € '.  $dao->event_beverage_price . ' drank',
        'contact_code' => $dao->participant_exact_id ? $dao->participant_exact_id : $dao->employer_exact_id,
        'item_code' => $eventExactCodes['event_code'],
        'error_message' => $e->getMessage(),
      ];

      $retval = FALSE;
    }

    return $retval;
  }

  private function getLinkedParticipantCount($participant_id) {
    // get the other participants linked to this registration with status registered or attended with a fee amount > 0
    $sql = "
      select
        count(*)
      from
        civicrm_participant
      where
        fee_amount > 0
      and 
        status_id in (1, 2)
      and
        registered_by_id = $participant_id
    ";

    $count = CRM_Core_DAO::singleValueQuery($sql);

    return $count;
  }

  private function getFormattedParticipantList($participant_id, $participant_name, $employer_name) {
    // create array with key = employer, and value = array or participants
    $empl = [];
    $empl[$employer_name] = [$participant_name];

    // get the name and employer of the participants linked to this registration
    $sql = "
      select
        concat(c.first_name, ' ', c.last_name) participant_name
        , c.organization_name employer_name
      from
        civicrm_participant p
      inner join
        civicrm_contact c on c.id = p.contact_id
      where
        p.status_id in (1, 2)
      and
        p.registered_by_id = $participant_id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);

    // add the extra participants (if any) to the array
    while ($dao->fetch()) {
      if (array_key_exists($dao->employer_name, $empl)) {
        // add item to existing array
        $empl[$dao->employer_name][] = $dao->participant_name;
      }
      else {
        // add new employer
        $empl[$dao->employer_name] = [$dao->participant_name];
      }
    }

    // format the participant list
    // e.g. Participant(s): XYZ - Jef, Jos; ABC - Annick
    $particiantList = '';
    foreach ($empl as $k => $v) {
      if ($particiantList != '') {
        // add separator
        $particiantList .= '; ';
      }

      $particiantList .= $k . ' - ' . implode(', ', $v);
    }
    $particiantList = 'Participant(s): ' . $particiantList;

    return $particiantList;
  }

  private function addOrReplaceLineItems($contributionID, $eventExactCodes, $event_all_in_price, $event_food_price, $event_beverage_price, $event_num_days, $extraParticipantcount) {
    $sql = "select * from civicrm_line_item where contribution_id = $contributionID order by id";
    $dao = CRM_Core_DAO::executeQuery($sql);

    // make sure the number of days is a number
    if ($event_num_days == '') {
      $event_num_days = 1;
    }

    $i = 0;
    while ($dao->fetch()) {
      $i++;

      if ($i == 1) {
        // the first line item is the event subscription
        $unitPrice = $event_all_in_price - ($event_food_price * $event_num_days) - ($event_beverage_price * $event_num_days);
        $sqlUpdate = "update civicrm_line_item set label = %2, qty = %3, unit_price = %4, line_total = %5 where id = %1";
        $sqlUpdateParams = [
          1 => [$dao->id, 'Integer'],
          2 => [$eventExactCodes['event_code'], 'String'],
          3 => [1 + $extraParticipantcount, 'Integer'],
          4 => [$unitPrice, 'Money'],
          5 => [$unitPrice * (1 + $extraParticipantcount), 'Money'],
        ];
        CRM_Core_DAO::executeQuery($sqlUpdate, $sqlUpdateParams);
      }
      elseif ($i == 2) {
        // the second line item is drinks
        $sqlUpdate = "update civicrm_line_item set label = %2, qty = %3, unit_price = %4, line_total = %5 where id = %1";
        $sqlUpdateParams = [
          1 => [$dao->id, 'Integer'],
          2 => [$eventExactCodes['catering_drinks'], 'String'],
          3 => [1 + $extraParticipantcount, 'Integer'],
          4 => [$event_beverage_price * $event_num_days, 'Money'],
          5 => [$event_beverage_price * $event_num_days * (1 + $extraParticipantcount), 'Money'],
        ];
        CRM_Core_DAO::executeQuery($sqlUpdate, $sqlUpdateParams);
      }
      elseif ($i == 3) {
        // the third line item is food
        $sqlUpdate = "update civicrm_line_item set label = %2, qty = %3, unit_price = %4, line_total = %5 where id = %1";
        $sqlUpdateParams = [
          1 => [$dao->id, 'Integer'],
          2 => [$eventExactCodes['catering_food'], 'String'],
          3 => [1 + $extraParticipantcount, 'Integer'],
          4 => [$event_food_price * $event_num_days, 'Money'],
          5 => [$event_food_price * $event_num_days * (1 + $extraParticipantcount), 'Money'],
        ];
        CRM_Core_DAO::executeQuery($sqlUpdate, $sqlUpdateParams);
      }
    }

    // if don't have all 3...
    if ($i == 0) {
      throw new Exception('Bijdrage ' . $contributionID . ' heeft geen line items');
    }
    if ($i == 1) {
      // add drinks
      if ($event_beverage_price > 0) {
        $params = [
          'entity_id' => $contributionID,
          'entity_table' => 'civicrm_participant',
          'contribution_id' => $contributionID,
          'financial_type_id' => 4, // event fee
          'label' => $eventExactCodes['catering_drinks'],
          'unit_price' => $event_beverage_price,
          'qty' => 1 + $extraParticipantcount,
          'line_total' => $event_beverage_price * (1 + $extraParticipantcount),
        ];
        civicrm_api3('LineItem', 'create', $params);
      }
    }
    if ($i == 1 || $i == 2) {
      // add food
      if ($event_food_price > 0) {
        $params = [
          'entity_id' => $contributionID,
          'entity_table' => 'civicrm_participant',
          'contribution_id' => $contributionID,
          'financial_type_id' => 4, // event fee
          'label' => $eventExactCodes['catering_food'],
          'unit_price' => $event_food_price,
          'qty' => 1 + $extraParticipantcount,
          'line_total' => $event_food_price * (1 + $extraParticipantcount),
        ];
        civicrm_api3('LineItem', 'create', $params);
      }
    }
  }

  private function buildDataMembership($contributionID) {
    $retval = TRUE;

    // get the contribution and the membership-related data
    $sql = "
      select
        c.id contribution_id,
        c.contact_id,
        cont.display_name,
        li.id line_item_id,
        ov.value item_code,
        d1.{$this->_orgExactIdColumn} contact_code, 
        d2.{$this->_orderNumberColumn} exact_order_number              
      from
        civicrm_contribution c
      left outer join
        civicrm_contact cont on cont.id = c.contact_id
      left outer join
        civicrm_line_item li on li.contribution_id = c.id
      left outer join
        civicrm_membership_payment p on p.contribution_id = c.id
      left outer join
        civicrm_membership m on m.id = p.membership_id
      left outer join
        civicrm_membership_type mt on mt.id = m.membership_type_id
      left outer join
        civicrm_option_value ov ON mt.name_nl_NL = ov.label_nl_NL AND ov.option_group_id = {$this->_invoiceOptionGroupId}
      LEFT JOIN
        {$this->_orgDetTableName} d1 ON c.contact_id = d1.entity_id
      LEFT JOIN 
        {$this->_contDataTableName} d2 ON c.id = d2.entity_id        
      WHERE
        c.id = $contributionID    
    ";

    try {
      $dao = CRM_Core_DAO::executeQuery($sql);
      if ($dao->fetch()) {
        // validate the contribution
        if (!empty($dao->exact_order_number)) {
          throw new Exception('Bijdrage heeft al een Exact ordernummer en de factuur is al verstuurd naar Exact.');
        }
        if (empty($dao->contact_code)) {
          throw new Exception('Contact heeft geen Exact contact code');
        }
        if (empty($dao->item_code)) {
          throw new Exception('Lidmaatschapstype heeft geen Exact artikel code');
        }

        // invoice description
        $invoiceDescription = "Lidmaatschap/Cotisation/Membership BEMAS";

        // update the line item code
        $params = [
          'id' => $dao->line_item_id,
          'label' => $dao->item_code,
        ];
        civicrm_api3('LineItem', 'Create', $params);

        // update the members contacts of this organization
        $memberContacts = $this->generateLineNotes($dao->contact_id);
        $f = CRM_Invoicestoexact_Config::singleton()->getContributionCommentCustomfield('id');
        $this->saveContributionCustomData($f, $memberContacts, $contributionID);

        $this->_data[$dao->contribution_id] = [
          'contact_id' => $dao->contact_id,
          'display_name' => $dao->display_name,
          'entity_table' => $dao->entity_table,
          'invoice_description' => $invoiceDescription,
          'unit_price' => $dao->unit_price,
          'contact_code' => $dao->{$this->_orgExactIdColumn},
          'exact_order_number' => $dao->{$this->_orderNumberColumn},
          'item_code' => $dao->item_code,
        ];
      }
      else {
        throw new Exception('Bijdrage en aanverwante info niet gevonden');
      }
    }
    catch (Exception $e) {
      // set error message
      $this->_data[$dao->contribution_id] = [
        'contact_id' => $dao->contact_id,
        'display_name' => $dao->display_name,
        'entity_table' => $dao->entity_table,
        'invoice_description' => $invoiceDescription,
        'unit_price' => $dao->unit_price,
        'contact_code' => $dao->{$this->_orgExactIdColumn},
        'item_code' => $dao->item_code,
        'error_message' => $e->getMessage(),
      ];

      $retval = FALSE;
    }

    return $retval;
  }

  /**
   * Method to generate line notes based on members of organization
   *
   * @param $contactId
   * @return string
   */
  private function generateLineNotes($contactId) {
    $primaryLines = [];
    $memberLines = [];
    $lineNotes = '';
    $typeOfMemberContactColumn = CRM_Invoicestoexact_Config::singleton()->getTypesOfMemberContactCustomField('column_name');

    // add standard general conditions
    $lineNotes = "Het bedrijfslidmaatschap bij BEMAS wordt automatisch verlengd per kalenderjaar en is jaarlijks opzegbaar vóór 31/12. Alle werknemers kunnen aan ledentarief deelnemen aan de studiesessies en opleidingen. De hieronder vermelde lidcontacten ontvangen ook de magazines, het jaarboek en alle communicatie gericht naar onze leden. De lidcontacten kunnen ten allen tijde gewijzigd worden via e-mail naar office@bemas.org\n
Sans avis de désaffiliation avant le 31/12, l’affiliation d'entreprise à la BEMAS est prolongée automatiquement par année calendrier. Tous les employé(e)s de l'entreprise peuvent assister aux activités et formations au tarif membre. Les contacts affiliés, mentionnés ci-dessous, reçoivent également les magazines, l’annuaire et toute communication destinée à nos membres. Les contacts affiliés peuvent être modifiés à tout moment par courrier électronique à office@bemas.org.\n 
The corporate membership of BEMAS is renewed automatically for one calendar year and can be terminated annually before Dec 31st. All employees can participate at the member activities and trainings at a special member rate. The member contacts listed below also receive the magazine(s), the yearbook and all communications addressed to our members. The member contacts can be changed at all times by sending an email to office@bemas.org.\n 
Werknemer(s) die momenteel als lidcontact is (zijn) genoteerd:\n
Employé(s) actuellement noté(s) en tant que contact(s) affilié(s):\n
Employee(s) currently designated as member contact(s) in our records:\n\n";

    // find all employees of the contact where type of member contact is m1 or mc
    $sqlArray = $this->getMemberContactsQuery($contactId);
    if (isset($sqlArray['query']) && !empty($sqlArray['query']) && isset($sqlArray['queryParams'])) {
      $dao = CRM_Core_DAO::executeQuery($sqlArray['query'], $sqlArray['queryParams']);
      while ($dao->fetch()) {
        switch ($dao->$typeOfMemberContactColumn) {
          case CRM_Invoicestoexact_Config::singleton()->getPrimaryMemberTypeValue():
            $primaryLines[] = $dao->display_name;
            break;
          case CRM_Invoicestoexact_Config::singleton()->getMemberTypeValue():
            $memberLines[] = $dao->display_name;
            break;
        }
      }
      if (!empty($primaryLines)) {
        $lineNotes .= implode(", ", $primaryLines) . "\n";
      }
      if (!empty($memberLines)) {
        $lineNotes .= implode(", ", $memberLines);
      }
    }
    return $lineNotes;
  }

  /**
   * Method to build query for member contacts
   *
   * @param $contactId
   * @return array
   *
   */
  private function getMemberContactsQuery($contactId) {
    $typeOfMemberContactColumn = CRM_Invoicestoexact_Config::singleton()->getTypesOfMemberContactCustomField('column_name');
    $indDetailsTable = CRM_Invoicestoexact_Config::singleton()->getIndividualDetailsCustomGroup('table_name');
    $result = [];
    $result['query'] = 'SELECT emp.display_name, ind.' . $typeOfMemberContactColumn . '
      FROM civicrm_relationship AS rel
      JOIN civicrm_contact AS emp ON rel.contact_id_a = emp.id
      LEFT JOIN ' . $indDetailsTable . ' ind ON emp.id = ind.entity_id
      WHERE rel.relationship_type_id = %1 AND rel.contact_id_b = %2 AND ' . $typeOfMemberContactColumn . ' IN (%3, %4)
      ORDER BY emp.display_name';
    $result['queryParams'] = [
      1 => [CRM_Invoicestoexact_Config::singleton()->getEmployerRelationshipTypeId(), 'Integer'],
      2 => [$contactId, 'Integer'],
      3 => [CRM_Invoicestoexact_Config::singleton()->getPrimaryMemberTypeValue(), 'String'],
      4 => [CRM_Invoicestoexact_Config::singleton()->getMemberTypeValue(), 'String'],
    ];
    return $result;
  }

  public function postProcess() {
    // the queue name is based on the logged in user
    $queueName = 'synccontribution_' . CRM_Core_Session::getLoggedInContactID();

    // create the queue
    $queue = CRM_Queue_Service::singleton()->create([
      'type' => 'Sql',
      'name' => $queueName,
      'reset' => TRUE, // flush queue upon creation
    ]);

    // add all id's to the queue
    foreach ($this->_contributionIds as $contributionId) {
      $task = new CRM_Queue_Task(['CRM_Invoicestoexact_ExactHelper', 'sendInvoice'], [$contributionId]);
      $queue->createItem($task);
    }

    if ($queue->numberOfItems() > 0) {
      // run the queue
      $runner = new CRM_Queue_Runner([
        'title' => 'BEMAS Exact Sync',
        'queue' => $queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_CONTINUE,
        'onEndUrl' => CRM_Utils_System::url('civicrm/contribute/search', 'reset=1'),
      ]);
      $runner->runAllViaWeb();
    }
    else {
      CRM_Core_Session::setStatus('Geen bijdragen te synchroniseren', 'Fout', 'error');
    }

    parent::postProcess();
  }

  /**
   * Method to save contribution custom data values
   *
   * @param $customFieldId
   * @param $value
   * @param $contributionId
   */
  private function saveContributionCustomData($customFieldId, $value, $contributionId) {
    try {
      civicrm_api3('CustomValue', 'create', [
        'entity_id' => $contributionId,
        'entity_table' => 'civicrm_contribution',
        'custom_' . $customFieldId => $value,
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      CRM_Core_Error::debug_log_message('Could not set contribution custom value for custom field id '
        . $customFieldId . ' and contribution ' . $contributionId . ' in ' . __METHOD__ . '(extension org.bemas.invoicestoexact)');
    }
  }

  private function getExactEventAndCateringCodes($eventTitle) {
    $returnArr = [];

    // the exact code is stored in the first part of the event title e.g. T180523V -  The Fundamentals of pump repair
    $eventCode = explode(' - ', $eventTitle);
    if (count($eventCode) < 2) {
      throw new Exception( 'Kan de event code niet uit de titel halen: ' . $eventTitle);
    }

    // store the event code in the return array
    $returnArr['event_code'] = $eventCode[0];

    // make sure the code starts with TT, TC, T, A
    $firstTwoLetters = substr($eventCode[0],0, 2);
    $firstLetter = substr($eventCode[0],0, 1);
    if ($firstTwoLetters == 'TC' || $firstTwoLetters == 'TT') {
      $returnArr['catering_food'] = 'CatFood-' . $firstTwoLetters;
      $returnArr['catering_drinks'] = 'CatDrinks-' . $firstTwoLetters;
    }
    elseif ($firstLetter == 'T' || $firstLetter == 'A') {
      $returnArr['catering_food'] = 'CatFood-' . $firstLetter;
      $returnArr['catering_drinks'] = 'CatDrinks-' . $firstLetter;
    }
    else {
      // not valid
      throw new Exception( 'De event code begint niet met TC, TT, T of A: ' . $eventCode[0]);
    }

    return $returnArr;
  }

}
