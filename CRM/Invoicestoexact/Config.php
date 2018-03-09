<?php
/**
 * Class voor BEMAS configuratie invoices to exact
 *
 * @author Erik Hommel <hommel@ee-atwork>
 * @author Alain Benbassat <alain@businessandcode.eu>
 * @date 21 Dec 2017
 * @license AGPL-3.0
 */

class CRM_Invoicestoexact_Config {

  // property for singleton pattern (caching the config)
  static private $_singleton = NULL;

  // property for items to exact option group
  private $_itemsExactOptionGroup = [];
  private $_exactCredentialsOptionGroup = [];
  private $_contributionDataCustomGroup = [];
  private $_exactOrderNumberCustomField = [];
  private $_exactSentErrorCustomField = [];
  private $_exactErrorMessageCustomField = [];
  private $_popsyIdCustomField = [];
  private $_organizationDetailsCustomGroup = [];
  private $_individualDetailsCustomGroup = [];
  private $_currentMembershipStatusId = NULL;
  private $_typesOfMemberContactCustomField = [];
  private $_employerRelationshipTypeId = NULL;
  private $_primaryMemberTypeValue = NULL;
  private $_memberTypeValue = NULL;

  /**
   * CRM_Invoicestoexact_Config constructor.
   */
  public function __construct() {
    $this->_itemsExactOptionGroup = $this->createOptionGroupIfNotExists('bemas_items_to_exact', 'BEMAS Exact Lidmaatschap Items');
    $this->_exactCredentialsOptionGroup = $this->createOptionGroupIfNotExists('bemas_exact_credentials', 'BEMAS Exact Credentials');
    $this->createContributionDataCustomGroup();
    if (!empty($this->_itemsExactOptionGroup['id'])) {
      // add exact items based on membership types
      $this->updateMembershipTypeExactItems();

      // add client id/client secret option value
      $this->addClientIDandSecretItem();
    }
    $this->setOrganizationDetailsCustomGroup();
    $this->setIndividualDetailsCustomGroup();
    $this->setTypesOfMemberContactCustomField();
    $this->setPopsyIdCustomField();
    try {
      $this->_currentMembershipStatusId = civicrm_api3('MembershipStatus', 'getvalue', [
        'name' => 'Current',
        'return' => 'id',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      CRM_Core_Error::debug_log_message(ts('Could not find a membership status Current in') . __METHOD__ . ' (extension org.bemas.invoicestoexact)');
    }
    try {
      $this->_employerRelationshipTypeId = civicrm_api3('RelationshipType', 'getvalue', [
        'name_a_b' => 'Employee of',
        'name_b_a' => 'Employer of',
        'return' => 'id',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      CRM_Core_Error::createError(ts('Couldn not find employee/employer relationship in ') . __METHOD__ . ' (extension org.bemas.invoicestoexact)');
    }
  }

  /**
   * Getter for primary member contact value
   *
   * @return null
   */
  public function getPrimaryMemberTypeValue() {
    return $this->_primaryMemberTypeValue;
  }

  /**
   * Getter for member contact value
   *
   * @return null
   */
  public function getMemberTypeValue() {
    return $this->_memberTypeValue;
  }

  /**
   * Getter for current membership status id
   *
   * @return array|null
   */
  public function getCurrentMembershipStatusId() {
    return $this->_currentMembershipStatusId;
  }

  /**
   * Getter for exact client ID
   *
   * @return array
   */
  public function getExactClientId() {
    try {
      return civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => $this->_exactCredentialsOptionGroup['id'],
        'name' => 'bemas_exact_client_id',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      CRM_Core_Error::createError(ts('Could not find Exact Client ID, not possible to connect to Exact. Contact your system administrator'));
    }
  }

  /**
   * Getter for employer relationship type id
   *
   * @return array|null
   */
  public function getEmployerRelationshipTypeId() {
    return $this->_employerRelationshipTypeId;
  }

  /**
   * Getter for exact client secret
   *
   * @return array
   */
  public function getExactClientSecret() {
    try {
      return civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => $this->_exactCredentialsOptionGroup['id'],
        'name' => 'bemas_exact_client_secret',
        'return' => 'value',
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      CRM_Core_Error::createError(ts('Could not find Exact Client Secret, not possible to connect to Exact. Contact your system administrator'));
    }
  }

  /**
   * Method to find and set the organization details custom group
   */
  private function setOrganizationDetailsCustomGroup() {
    try {
      $this->_organizationDetailsCustomGroup = civicrm_api3('CustomGroup', 'getsingle', ['name' => 'Organization_details']);
    }
    catch (CiviCRM_API3_Exception $ex) {
    }
  }

  /**
   * Method to find and set the individual details custom group
   */
  private function setIndividualDetailsCustomGroup() {
    try {
      $this->_individualDetailsCustomGroup = civicrm_api3('CustomGroup', 'getsingle', ['name' => 'Individual_details']);
    }
    catch (CiviCRM_API3_Exception $ex) {
    }
  }

  /**
   * Method to find and set the custom field popsy_id_25
   */
  private function setPopsyIdCustomField() {
    try {
      $this->_popsyIdCustomField = civicrm_api3('CustomField', 'getsingle', [
        'name' => 'POPSY_ID',
        'custom_group_id' => $this->_organizationDetailsCustomGroup['id'],
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
    }
  }

  /**
   * Method to find and set the custom field for type of member contact
   */
  private function setTypesOfMemberContactCustomField() {
    try {
      $this->_typesOfMemberContactCustomField = civicrm_api3('CustomField', 'getsingle', [
        'name' => 'Types_of_member_contact',
        'custom_group_id' => $this->_individualDetailsCustomGroup['id'],
      ]);
      // if ok, also set the primary and member type values
      $this->setTypesOfMemberValues();
    }
    catch (CiviCRM_API3_Exception $ex) {
    }
  }

  /**
   * Method to set the types of member contact values (used to build the line notes for the exact invoice)
   */
  private function setTypesOfMemberValues() {
    try {
      $optionValues = civicrm_api3('OptionValue', 'get', [
        'option_group_id' => $this->_typesOfMemberContactCustomField['option_group_id'],
        'is_active' => 1,
        'sequential' => 1,
        'options' => ['limit' => 0],
      ]);
      foreach ($optionValues['values'] as $optionValue) {
        switch ($optionValue['name']) {
          case 'Member_contact':
            $this->_memberTypeValue = $optionValue['value'];
            break;

          case 'Primary_member_contact':
            $this->_primaryMemberTypeValue = $optionValue['value'];
            break;
        }
      }
    }
    catch (CiviCRM_API3_Exception $ex) {
    }
  }

  /**
   * Method to create custom group for contribution data if not exist yet
   */
  private function createContributionDataCustomGroup() {
    $customGroupName = 'bemas_contribution_data';
    try {
      $this->_contributionDataCustomGroup = civicrm_api3('CustomGroup', 'getsingle', [
        'extends' => 'Contribution',
        'name' => $customGroupName,
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      try {
        $createdCustomGroup = civicrm_api3('CustomGroup', 'create', [
          'name' => $customGroupName,
          'title' => 'BEMAS Aanvullende gegevens bijdrage',
          'extends' => 'Contribution',
          'table_name' => 'civicrm_value_bemas_contribution_data',
          'is_reserved' => 1,
          'collapse_adv_display' => 0,
          'collapse_display' => 0,
        ]);
        $this->_contributionDataCustomGroup = $createdCustomGroup['values'][$createdCustomGroup['id']];
      }
      catch (CiviCRM_API3_Exception $ex) {
        CRM_Core_Error::createError(ts('Could not find or create custom group for contribution data in ')
          . __METHOD__ . ' (extension org.bemas.invoicestoexact');
      }
    }
    // create custom fields if not exists yet
    $this->createExactOrderNumberCustomField();
    $this->createExactSentErrorCustomField();
    $this->createExactErrorMessageCustomField();
  }

  /**
   * Method to create or get custom field for exact order number
   */
  private function createExactOrderNumberCustomField() {
    $customFieldName = 'bemas_exact_order_number';
    try {
      $this->_exactOrderNumberCustomField = civicrm_api3('CustomField', 'getsingle', [
        'name' => $customFieldName,
        'column_name' => $customFieldName,
        'custom_group_id' => $this->_contributionDataCustomGroup['id'],
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      try {
        $createdCustomField = civicrm_api3('CustomField', 'create', [
          'custom_group_id' => $this->_contributionDataCustomGroup['id'],
          'name' => $customFieldName,
          'column_name' => $customFieldName,
          'label' => 'Ordernummer in Exact',
          'data_type' => 'String',
          'html_type' => 'Text',
          'is_active' => 1,
          'is_searchable' => 1,
          'is_view' => 1,
        ]);
        $this->_exactOrderNumberCustomField = $createdCustomField['values'][$createdCustomField['id']];
      }
      catch (CiviCRM_API3_Exception $ex) {
        CRM_Core_Error::createError(ts('Could not find or create custom field for exact order number in ')
          . __METHOD__ . ' (extension org.bemas.invoicestoexact');
      }
    }
  }

  /**
   * Method to create or get custom field Y/N if invoice was sent to Exact succesfully
   */
  private function createExactSentErrorCustomField() {
    $customFieldName = 'bemas_exact_sent_error';
    try {
      $this->_exactSentErrorCustomField = civicrm_api3('CustomField', 'getsingle', [
        'name' => $customFieldName,
        'column_name' => $customFieldName,
        'custom_group_id' => $this->_contributionDataCustomGroup['id'],
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      try {
        $createdCustomField = civicrm_api3('CustomField', 'create', [
          'custom_group_id' => $this->_contributionDataCustomGroup['id'],
          'name' => $customFieldName,
          'column_name' => $customFieldName,
          'label' => 'Fout bij sturen naar Exact?',
          'data_type' => 'Boolean',
          'html_type' => 'Radio',
          'is_active' => 1,
          'is_searchable' => 1,
          'is_view' => 1,
        ]);
        $this->_exactSentErrorCustomField = $createdCustomField['values'][$createdCustomField['id']];
      }
      catch (CiviCRM_API3_Exception $ex) {
        CRM_Core_Error::createError(ts('Could not find or create custom field for exact error sent in ')
          . __METHOD__ . ' (extension org.bemas.invoicestoexact');
      }
    }
  }

  /**
   * Method to create or get custom field error message from Exact
   */
  private function createExactErrorMessageCustomField() {
    $customFieldName = 'bemas_exact_error_message';
    try {
      $this->_exactErrorMessageCustomField = civicrm_api3('CustomField', 'getsingle', [
        'name' => $customFieldName,
        'column_name' => $customFieldName,
        'custom_group_id' => $this->_contributionDataCustomGroup['id'],
      ]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      try {
        $createdCustomField = civicrm_api3('CustomField', 'create', [
          'custom_group_id' => $this->_contributionDataCustomGroup['id'],
          'name' => $customFieldName,
          'column_name' => $customFieldName,
          'label' => 'Foutboodschap van Exact',
          'data_type' => 'Memo',
          'html_type' => 'TextArea',
          'is_active' => 1,
          'is_searchable' => 1,
          'is_view' => 1,
        ]);
        $this->_exactErrorMessageCustomField = $createdCustomField['values'][$createdCustomField['id']];
      }
      catch (CiviCRM_API3_Exception $ex) {
        CRM_Core_Error::createError(ts('Could not find or create custom field for exact error message in ')
          . __METHOD__ . ' (extension org.bemas.invoicestoexact');
      }
    }
  }

  /**
   * Method to find or create option group
   *
   * @param $optionGroupName
   * @param null $optionGroupTitle
   * @return array
   */
  private function createOptionGroupIfNotExists($optionGroupName, $optionGroupTitle = NULL) {
    try {
      return civicrm_api3('OptionGroup', 'getsingle', array(
        'name' => $optionGroupName,
      ));
    }
    catch (CiviCRM_API3_Exception $ex) {
      if (empty($optionGroupTitle)) {
        $parts = explode('_', $optionGroupName);
        foreach ($parts as $key => $value) {
          $parts[$key] = ucfirst($value);
        }
        $optionGroupTitle = implode(' ', $parts);
      }
      try {
        $optionGroup = civicrm_api3('OptionGroup', 'create', array(
          'name' => $optionGroupName,
          'title' => $optionGroupTitle,
          'is_active' => 1,
          'is_reserved' => 1,
        ));

        return $optionGroup['values'][$optionGroup['id']];
      }
      catch (CiviCRM_API3_Exception $ex) {
        CRM_Core_Error::createError(ts('Could not find or create an option group with name ') . $optionGroupName
          . ' in ' . __METHOD__ . ' (extension org.bemas.invoicestoexact');
      }
    }
    return NULL;
  }

  /**
   * Method to create option values in option group for membership exact items
   */
  public function updateMembershipTypeExactItems() {
    try {
      $membershipTypes = civicrm_api3('MembershipType', 'get', array(
        'is_active' => 1,
        'options' => array('limit' => 0),
      ));
      foreach ($membershipTypes['values'] as $membershipType) {
        // create an option value for each membership type if not exists
        $this->createOptionValueIfLabelNotExists(array(
          'option_group_id' => $this->_itemsExactOptionGroup['id'],
          'label' => $membershipType['name'],
          'is_active' => 1,
          'is_reserved' => 1,
          'value' => ts('Dummy Exact Artikel code voor Lidmaatschapstype ') . $membershipType['name'] . ':',
        ));
      }
    }
    catch (CiviCRM_API3_Exception $ex) {
    }
  }

  /**
   * Method to save exact integration credentials in option group
   */
  public function addClientIDandSecretItem() {
    $optionValues = [
      'bemas_exact_client_id' => 'Exact Client ID',
      'bemas_exact_client_secret' => 'Exact Client Secret',
      ];
    foreach ($optionValues as $name => $label) {
      try {
        $params = array(
          'option_group_id' => $this->_exactCredentialsOptionGroup['id'],
          'is_active' => 1,
          'is_reserved' => 1,
          'label' => $label,
          'name' => $name,
        );
        $this->createOptionValueIfLabelNotExists($params);
      }
      catch (CiviCRM_API3_Exception $ex) {
      }
    }
  }

  /**
   * Method to create option value if not exists yet
   *
   * @param $data
   */
  private function createOptionValueIfLabelNotExists($data) {
    if (!empty($data)) {
      try {
        $count = civicrm_api3('OptionValue', 'getcount', [
          'option_group_id' => $data['option_group_id'],
          'label' => $data['label'],
        ]);
        if ($count == 0) {
          civicrm_api3('OptionValue', 'create', $data);
        }
      }
      catch (CiviCRM_API3_Exception $ex) {
      }
    }
  }

  /**
   * Getter for items to exact option group
   *
   * @param string $key
   * @return array|mixed
   */
  public function getItemsExactOptionGroup($key = 'id') {
    if (!empty($key) && isset($this->_itemsExactOptionGroup[$key])) {
      return $this->_itemsExactOptionGroup[$key];
    }
    else {
      return $this->_itemsExactOptionGroup;
    }
  }

  /**
   * Getter for popsy id custom field
   *
   * @param string $key
   * @return array|string
   */
  public function getPopsyIdCustomField($key = 'id') {
    if (!empty($key) && isset($this->_popsyIdCustomField[$key])) {
      return $this->_popsyIdCustomField[$key];
    }
    else {
      return $this->_popsyIdCustomField;
    }
  }

  /**
   * Getter for exact order number custom field
   *
   * @param string $key
   * @return array|mixed
   */
  public function getExactOrderNumberCustomField($key = 'id') {
    if (!empty($key) && isset($this->_exactOrderNumberCustomField[$key])) {
      return $this->_exactOrderNumberCustomField[$key];
    }
    else {
      return $this->_exactOrderNumberCustomField;
    }
  }

  /**
   * Getter for exact sent error custom field
   *
   * @param string $key
   * @return array|mixed
   */
  public function getExactSentErrorCustomField($key = 'id') {
    if (!empty($key) && isset($this->_exactSentErrorCustomField[$key])) {
      return $this->_exactSentErrorCustomField[$key];
    }
    else {
      return $this->_exactSentErrorCustomField;
    }
  }

  /**
   * Getter for exact error message id custom field
   *
   * @param string $key
   * @return array|mixed
   */
  public function getExactErrorMessageCustomField($key = 'id') {
    if (!empty($key) && isset($this->_exactErrorMessageCustomField[$key])) {
      return $this->_exactErrorMessageCustomField[$key];
    }
    else {
      return $this->_exactErrorMessageCustomField;
    }
  }

  /**
   * Getter for type of member contact custom field
   *
   * @param string $key
   * @return array|mixed
   */
  public function getTypesOfMemberContactCustomField($key = 'id') {
    if (!empty($key) && isset($this->_typesOfMemberContactCustomField[$key])) {
      return $this->_typesOfMemberContactCustomField[$key];
    }
    else {
      return $this->_typesOfMemberContactCustomField;
    }
  }

  /**
   * Getter for individual details custom group
   *
   * @param string $key
   * @return array|mixed
   */
  public function getIndividualDetailsCustomGroup($key = 'id') {
    if (!empty($key) && isset($this->_individualDetailsCustomGroup[$key])) {
      return $this->_individualDetailsCustomGroup[$key];
    }
    else {
      return $this->_individualDetailsCustomGroup;
    }
  }

  /**
   * Getter for organization details custom group
   *
   * @param string $key
   * @return array|mixed
   */
  public function getOrganizationDetailsCustomGroup($key = 'id') {
    if (!empty($key) && isset($this->_organizationDetailsCustomGroup[$key])) {
      return $this->_organizationDetailsCustomGroup[$key];
    }
    else {
      return $this->_organizationDetailsCustomGroup;
    }
  }

  /**
   * Getter for contribution data custom group
   *
   * @param string $key
   * @return array|mixed
   */
  public function getContributionDataCustomGroup($key = 'id') {
    if (!empty($key) && isset($this->_contributionDataCustomGroup[$key])) {
      return $this->_contributionDataCustomGroup[$key];
    }
    else {
      return $this->_contributionDataCustomGroup;
    }
  }

  /**
   * Function to return singleton object
   *
   * @return object $_singleton
   * @access public
   * @static
   */
  public static function &singleton() {
    if (self::$_singleton === NULL) {
      self::$_singleton = new CRM_Invoicestoexact_Config();
    }
    return self::$_singleton;
  }

  /**
   * Method to enable option groups
   */
  public static function enableOptionGroups() {
    $optionGroupNames = ['bemas_items_to_exact', 'bemas_exact_credentials'];
    foreach ($optionGroupNames as $optionGroupName) {
      try {
        //first get id of option group based on name
        $optionGroupId = civicrm_api3('OptionGroup', 'getvalue', array(
          'name' => $optionGroupName,
          'return' => 'id',
        ));
        //enable option group
        civicrm_api3('OptionGroup', 'create', array(
          'id' => $optionGroupId,
          'is_active' => 1,
        ));
        //finally enable all option values
        $optionValues = civicrm_api3('OptionValue', 'get', array(
          'option_group_id' => $optionGroupId,
          'options' => array('limit' => 0),
        ));
        foreach ($optionValues['values'] as $optionValue) {
          civicrm_api3('OptionValue', 'create', array(
            'id' => $optionValue['id'],
            'is_active' => 1,
          ));
        }
      }
      catch (CiviCRM_API3_Exception $ex) {
      }
    }
  }

  /**
   * Method to disable option groups
   */
  public static function disableOptionGroups() {
    $optionGroupNames = ['bemas_items_to_exact', 'bemas_exact_credentials'];
    foreach ($optionGroupNames as $optionGroupName) {
      try {
        //first get id of option group based on name
        $optionGroupId = civicrm_api3('OptionGroup', 'getvalue', array(
          'name' => $optionGroupName,
          'return' => 'id',
        ));
        //then disable option values
        $optionValues = civicrm_api3('OptionValue', 'get', array(
          'option_group_id' => $optionGroupId,
          'options' => array('limit' => 0),
        ));
        foreach ($optionValues['values'] as $optionValue) {
          civicrm_api3('OptionValue', 'create', array(
            'id' => $optionValue['id'],
            'is_active' => 0,
          ));
        }
        //finally disable option group
        civicrm_api3('OptionGroup', 'create', array(
          'id' => $optionGroupId,
          'is_active' => 0,
        ));
      }
      catch (CiviCRM_API3_Exception $ex) {
      }
    }
  }

  public static function uninstallOptionGroups() {
    $optionGroupNames = ['bemas_items_to_exact', 'bemas_exact_credentials'];
    foreach ($optionGroupNames as $optionGroupName) {
      try {
        //first get id of option group based on name
        $optionGroupId = civicrm_api3('OptionGroup', 'getvalue', array(
          'name' => $optionGroupName,
          'return' => 'id',
        ));
        //then remove option values
        $optionValues = civicrm_api3('OptionValue', 'get', array(
          'option_group_id' => $optionGroupId,
          'options' => array('limit' => 0),
        ));
        foreach ($optionValues['values'] as $optionValue) {
          civicrm_api3('OptionValue', 'delete', array(
            'id' => $optionValue['id'],
          ));
        }
        //finally remove option group
        civicrm_api3('OptionGroup', 'delete', array(
          'id' => $optionGroupId,
        ));
      }
      catch (CiviCRM_API3_Exception $ex) {
      }
    }
  }

}
