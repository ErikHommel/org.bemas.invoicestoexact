<?php

class CRM_Invoicestoexact_ExactHelper {
  /*
   * contact_code
   * item_code
   * unit_price
   * invoice_description
   * line_notes
   */
  static function sendInvoice(CRM_Queue_TaskContext $ctx, $contributionID) {
    $exactOL = new CRM_Exactonline_Utils();
    $exactOL->exactConnection->connect();

    // get the contribution details
    $contribDetails = CRM_Invoicestoexact_Config::singleton()->getContributionDataCustomGroup('table_name');
    $exactContactID = CRM_Invoicestoexact_Config::singleton()->getContributionExactIDCustomField('column_name');
    $invoiceDescription = CRM_Invoicestoexact_Config::singleton()->getContributionDescriptionCustomField('column_name');
    $poNumber = CRM_Invoicestoexact_Config::singleton()->getContributionPOCustomfield('column_name');
    $comment = CRM_Invoicestoexact_Config::singleton()->getContributionCommentCustomfield('column_name');

    // fields for sync feedback
    $orderNumberCustomFieldId = CRM_Invoicestoexact_Config::singleton()->getExactOrderNumberCustomField('id');
    $sentErrorCustomFieldId = CRM_Invoicestoexact_Config::singleton()->getExactSentErrorCustomField('id');
    $errorMessageCustomFieldId = CRM_Invoicestoexact_Config::singleton()->getExactErrorMessageCustomField('id');

    try {
      $sql = "
        SELECT
          cd.$poNumber po
          , cd.$exactContactID exact_id
          , cd.$comment comment
          , cd.$invoiceDescription description
          , ft.name financial_type
        FROM
          civicrm_contribution c
        INNER JOIN
          $contribDetails cd on cd.entity_id = c.id
        INNER JOIN          
          civicrm_financial_type ft on c.financial_type_id = ft.id        
        WHERE
          c.id = $contributionID
      ";
      $daoContrib = CRM_Core_DAO::executeQuery($sql);
      if ($daoContrib->fetch()) {
        // find the customer
        $customerFinder = new \Picqer\Financials\Exact\Account($exactOL->exactConnection);
        $c = $customerFinder->filter("trim(Code) eq '" . $daoContrib->exact_id . "'");
        if (count($c) !== 1) {
          throw new Exception('klant met exact ID = ' . $daoContrib->exact_id . ' niet gevonden');
        }
        $customer = $c[0];

        // create the invoice
        $salesInvoice = new \Picqer\Financials\Exact\SalesInvoice($exactOL->exactConnection);
        $salesInvoice->InvoiceTo = $customer->ID;
        $salesInvoice->OrderedBy = $customer->ID;
        $salesInvoice->Description = $daoContrib->description;
        if ($daoContrib->po) {
          $salesInvoice->YourRef = $daoContrib->po;
        }

        // add the invoice lines
        $sql = "select * from civicrm_line_item where contribution_id = $contributionID";
        $daoContribLines = CRM_Core_DAO::executeQuery($sql);
        $salesInvoiceLines = [];
        $line = -1;
        while ($daoContribLines->fetch()) {
          // extra check on unit price in case drinks or food = 0
          if ($daoContribLines->unit_price > 0) {
            $line++;

            // find the product (article)
            $itemFinder = new \Picqer\Financials\Exact\Item($exactOL->exactConnection);
            $i = $itemFinder->filter("Code eq '" . $daoContribLines->label . "'");
            if (count($i) !== 1) {
              throw new Exception('artikel ' . $daoContribLines->label . ' niet gevonden');
            }
            $item = $i[0];

            // create the invoice line
            $salesInvoiceLine = new \Picqer\Financials\Exact\SalesInvoiceLine($exactOL->exactConnection);
            $salesInvoiceLine->Item = $item->ID;
            $salesInvoiceLine->Quantity = $daoContribLines->qty;
            $salesInvoiceLine->UnitPrice = $daoContribLines->unit_price;
            $salesInvoiceLines[] = $salesInvoiceLine;
          }
        }

        // add comment to the last line
        if ($line >= 0 && $daoContrib->comment) {
          $salesInvoiceLines[$line]->Notes = $daoContrib->comment;
        }

        // add the line(s) to the invoice
        $salesInvoice->SalesInvoiceLines = $salesInvoiceLines;

        // send to Exact!
        $s = $salesInvoice->insert();

        // save the order number
        self::saveContributionCustomData($orderNumberCustomFieldId, $s['OrderNumber'], $contributionID);
        self::saveContributionCustomData($sentErrorCustomFieldId, 0, $contributionID);
        self::saveContributionCustomData($errorMessageCustomFieldId, '', $contributionID);

        if ($daoContrib->financial_type == 'Member Dues') {
          // update membership status
          self::updateMembershipToCurrent($contributionID);
        }
        elseif ($daoContrib->financial_type == 'Event Fee') {
          // update the participant status
          self::updateParticipantToInvoiced($contributionID);
        }
      }
      else {
        throw new Exception("Kan bijdrage met id = $contributionID niet vinden", -999);
      }
    }
    catch (Exception $e) {
      if ($e->getCode() != -999) {
        // store the error msg in the contrib
        self::saveContributionCustomData($sentErrorCustomFieldId, 1, $contributionID);
        self::saveContributionCustomData($errorMessageCustomFieldId, $e->getMessage(), $contributionID);
      }

      return FALSE;
    }

    return TRUE;
  }

  static function saveContributionCustomData($customFieldId, $value, $contributionId) {
    civicrm_api3('CustomValue', 'create', [
      'entity_id' => $contributionId,
      'entity_table' => 'civicrm_contribution',
      'custom_' . $customFieldId => $value,
    ]);
  }

  static function updateMembershipToCurrent($contributionId) {
    // first get membership id, will be false if not a membership
    $membership = self::getMembershipForContribution($contributionId);
    if ($membership) {
      try {
        $membership['status_id'] = CRM_Invoicestoexact_Config::singleton()->getCurrentMembershipStatusId();
        civicrm_api3('Membership', 'create', $membership);
      }
      catch (CiviCRM_API3_Exception $ex) {
        CRM_Core_Error::debug_log_message(ts('Could not set membership with id ' . $membership['id']
          . ' to current with API Membershio ceate in ' . __METHOD__ . '(extension org.bemas.invoicestoexact)'));
      }
    }
  }

  static function updateParticipantToInvoiced($contributionId) {
    // first get membership id, will be false if not a membership
    $participant = self::getParticipantForContribution($contributionId);
    if ($participant) {
      try {
        // api gives error about role_id?!?, so we do it via SQL
        $sql = 'update civicrm_participant set status_id = %1 where id = %2';
        $sqlParams = [
          1 => [CRM_Invoicestoexact_Config::singleton()->getInvoicedParticipantStatusId(), 'Integer'],
          2 => [$participant['id'], 'Integer'],
        ];
        CRM_Core_DAO::executeQuery($sql, $sqlParams);

        // update the related participants
        $sql = 'update civicrm_participant set status_id = %1 where registered_by_id = %2 and status_id in (1, 2)';
        $sqlParams = [
          1 => [CRM_Invoicestoexact_Config::singleton()->getInvoicedParticipantStatusId(), 'Integer'],
          2 => [$participant['id'], 'Integer'],
        ];
        CRM_Core_DAO::executeQuery($sql, $sqlParams);
      }
      catch (CiviCRM_API3_Exception $ex) {
        CRM_Core_Error::debug_log_message(ts('Could not set participant with id ' . $participant['id']
          . ' to ' . $participant['status_id'] . ' with API Participant create in ' . __METHOD__ . '(extension org.bemas.invoicestoexact): ' . $ex->getMessage()));
      }
    }
  }

  /**
   * Method to get membership with contribution id
   *
   * @param $contributionId
   * @return array|bool
   */
  static function getMembershipForContribution($contributionId) {
    try {
      $membershipId = civicrm_api3('MembershipPayment', 'getvalue', [
        'contribution_id' => $contributionId,
        'return' => 'membership_id'
      ]);
      return civicrm_api3('Membership', 'getsingle', ['id' => $membershipId]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      return FALSE;
    }
  }

  static function getParticipantForContribution($contributionId) {
    try {
      $participantId = civicrm_api3('ParticipantPayment', 'getvalue', [
        'contribution_id' => $contributionId,
        'return' => 'participant_id'
      ]);
      return civicrm_api3('Participant', 'getsingle', ['id' => $participantId]);
    }
    catch (CiviCRM_API3_Exception $ex) {
      return FALSE;
    }
  }

}
