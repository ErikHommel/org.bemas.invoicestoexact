<?php
/**
 * Class to process contribution search task Send Invoices to Exact
 *
 * @author Erik Hommel <hommel@ee-atwork>
 * @date 21 Dec 2017
 * @license AGPL-3.0
 */

class CRM_Invoicestoexact_Form_Task_InvoiceExact extends CRM_Contribute_Form_Task {
  private $_data = array();
  private $_correctElements = array();
  private $_errorElements = array();

  /**
   * Method to build the form
   */
  public function buildQuickForm() {
    $this->buildData();
    foreach ($this->_contributionIds as $contributionId) {
      if ($this->canInvoiceBeSent($contributionId)) {
        $this->buildCorrectElement($contributionId);
      } else {
        $this->buildErrorElement($contributionId);
        if (($key = array_search($contributionId, $this->_contributionIds)) !== FALSE) {
          unset($this->_contributionIds[$key]);
        }
      }
    }
    $this->assign('correctElements', $this->_correctElements);
    $this->assign('errorElements', $this->_errorElements);
    $this->addButtons(array(
      array('type' => 'next', 'name' => ts('Confirm'), 'isDefault' => true,),
      array('type' => 'cancel', 'name' => ts('Cancel'),),));
  }

  /**
   * Method to build the form element for a correct contribution
   *
   * @param $contributionId
   */
  private function buildCorrectElement($contributionId) {
    $contactElement = 'contact_'.$contributionId;
    $dataElement = 'data_'.$contributionId;
    $this->_correctElements[$contributionId] = array(
      'contact' => $contactElement,
      'data' => $dataElement,
    );
    $this->add('text', $contactElement);
    $this->add('text', $dataElement);
  }

  /**
   * Method to set default values
   *
   * @return array|NULL
   */
  public function setDefaultValues() {
    $defaults = array();
    foreach ($this->_correctElements as $correctId => $correct) {
      $defaults[$correct['contact']] = $this->_data[$correctId]['display_name'];
      $defaults[$correct['data']] = 'Contact code: '.$this->_data[$correctId]['contact_code'].', item code: '.
        $this->_data[$correctId]['item_code'].' met bedrag '.CRM_Utils_Money::format($this->_data[$correctId]['unit_price']);
    }
    foreach ($this->_errorElements as $errorId => $error) {
      if ($this->_data[$errorId]['entity_table'] == 'civicrm_membership') {
        $entityLine = 'Lidmaatschap';
      } else {
        $entityLine = '';
      }
      $defaults[$error['contact']] = $this->_data[$errorId]['display_name'].' '.$entityLine;
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
    $contactElement = 'contact_'.$contributionId;
    $messageElement = 'message_'.$contributionId;
    $this->_errorElements[$contributionId] = array(
      'contact' => $contactElement,
      'message' => $messageElement,
    );
    $this->add('text', $contactElement);
    $this->add('text', $messageElement);
  }

  /**
   * Method to check if the contribution can be sent to Exact (only if custom field exact_invoice_id is empty)
   *  and only if we have exact codes
   *
   * @param $contributionId
   * @return bool
   */
  private function canInvoiceBeSent($contributionId) {
    if (!empty($this->_data[$contributionId]['exact_invoice_id'])) {
      $this->_data[$contributionId]['error_message'] = ts('Bijdrage heeft al een Exact factuurnummer');
      return FALSE;
    }
    if (empty($this->_data[$contributionId]['contact_code'])) {
      $this->_data[$contributionId]['error_message'] = 'Contact heeft geen Exact contact code';
      return FALSE;
    }
    if (empty($this->_data[$contributionId]['item_code'])) {
      $this->_data[$contributionId]['error_message'] = 'Lidmaatschapstype heeft geen Exact contact code';
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Method to build the relevant data for all potential invoices
   */
  private function buildData() {
    $queryParams = array();
    $queryIndexes = array();
    $index = 0;
    foreach ($this->_contributionIds as $contributionId) {
      $index++;
      $queryParams[$index] = array($contributionId, 'Integer');
      $queryIndexes[] = '%'.$index;
    }
    if (!empty($queryParams)) {
      $contactCodeColumn = CRM_Invoicestoexact_Config::singleton()->getPopsyIdCustomField('column_name');
      $invoiceIdColumn = CRM_Invoicestoexact_Config::singleton()->getExactInvoiceIdCustomField('column_name');
      $orgDetTableName = CRM_Invoicestoexact_Config::singleton()->getOrganizationDetailsCustomGroup('table_name');
      $contDataTableName = CRM_Invoicestoexact_Config::singleton()->getContributionDataCustomGroup('table_name');
      $invoiceOptionGroupId = CRM_Invoicestoexact_Config::singleton()->getItemsExactOptionGroup('id');
      $query = 'SELECT a.id AS contribution_id, a.contact_id, b.display_name, c.entity_table, c.label AS invoice_description, 
      c.unit_price, d.'.$contactCodeColumn.', e.'.$invoiceIdColumn.', f.label AS item_code 
      FROM civicrm_contribution a JOIN civicrm_contact b ON a.contact_id = b.id
      LEFT JOIN civicrm_line_item c ON a.id = c.contribution_id
      LEFT JOIN '.$orgDetTableName.' d ON a.contact_id = d.entity_id
      LEFT JOIN '.$contDataTableName.' e ON a.id = e.entity_id
      LEFT JOIN civicrm_option_value f ON c.label = f.value AND f.option_group_id = '.$invoiceOptionGroupId.
      ' WHERE a.id IN('.implode(', ', $queryIndexes).')';

      $dao = CRM_Core_DAO::executeQuery($query, $queryParams);
      while ($dao->fetch()) {
        $this->_data[$dao->contribution_id] = array(
          'contact_id' => $dao->contact_id,
          'display_name' => $dao->display_name,
          'entity_table' => $dao->entity_table,
          'invoice_description' => $dao->invoice_description,
          'unit_price' => $dao->unit_price,
          'contact_code' => $dao->$contactCodeColumn,
          'exact_invoice_id' => $dao->$invoiceIdColumn,
          'item_code' => $dao->item_code,
        );
      }
    }
  }

  public function postProcess() {
    foreach ($this->_contributionIds as $contributionId) {
      $data = array(
        'contact_code' => $this->_data[$contributionId]['contact_code'],
        'item_code' => $this->_data[$contributionId]['item_code'],
        'invoice_description' => $this->_data[$contributionId]['invoice_description'],
        'line_notes' => $this->_data[$contributionId]['line_notes'],
        'unit_price' => $this->_data[$contributionId]['unit_price'],
      );
      CRM_Invoicetoexact_ExactHelper::sendInvoice($data);
    }
    parent::postProcess();
  }

}