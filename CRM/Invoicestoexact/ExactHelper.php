<?php

require_once __DIR__ . '/../../exactonline-api-php-client/vendor/autoload.php';


class CRM_Invoicestoexact_Exacthelper {
  static function redirectUrl() {
    try {
      // If authorization code is returned from Exact, save this to use for token request
      $storedAuthorizationCode = CRM_Core_Session::singleton()->get('authorizationcode', 'ExactSession');
      if (isset($_GET['code']) && is_null($storedAuthorizationCode)) {
        CRM_Core_Session::singleton()->set('authorizationcode', $_GET['code'], 'ExactSession');
      }
      // If we do not have a authorization code, authorize first to setup tokens
      if (CRM_Core_Session::singleton()->get('authorizationcode', 'ExactSession') === null) {
        self::authorize();
      }

      $connection = self::connect();

      // find the customer
      $customerFinder = new \Picqer\Financials\Exact\Account($connection);
      $c = $customerFinder->filter("Code eq '                28'");
      if (count($c) !== 1) {
        throw new Exception("klant niet gevonden");
      }
      $customer = $c[0];

      // find the product
      $itemFinder = new Picqer\Financials\Exact\Item($connection);
      $i = $itemFinder->filter("Code eq 'IND300661'");
      if (count($i) !== 1) {
        throw new Exception("artikel niet gevonden");
      }
      $item = $i[0];

      // create the invoice
      $salesInvoice = new \Picqer\Financials\Exact\SalesInvoice($connection);
      $salesInvoice->InvoiceTo = $customer->ID;
      $salesInvoice->OrderedBy = $customer->ID;
      $salesInvoice->Description = 'Lidmaatschap testfactuur';

      // add an invoice line
      $salesInvoiceLine = new \Picqer\Financials\Exact\SalesInvoiceLine($connection);

      //$salesInvoiceLine->UnitCode = 'IND300661';
      $salesInvoiceLine->Item = $item->ID;
      $salesInvoiceLine->Quantity = 17;
      $salesInvoiceLine->UnitPrice = 99;
      $salesInvoiceLine->Notes = "bla bla bla\nen nog eens bla bla bla";

      // add line to invoice
      $salesInvoice->SalesInvoiceLines = array($salesInvoiceLine);

      // insert invoice in Exact
      $s = $salesInvoice->insert();
      echo "<pre>"; var_dump($s); echo "</pre>";exit;
      echo "Ordernummer: " . $s['OrderNumber'] . '<br>';
      echo "InvoiceID: " . $s['InvoiceID'] . '<br>';

      echo "Invoice created: " . $salesInvoice->InvoiceNumber;
    } catch (\Exception $e) {
      echo 'FOUT: ' . $e->getMessage();
      watchdog('alain', $e->getMessage());
    }
  }

  /*
   * contact_code
   * item_code
   * unit_price
   * invoice_description
   * line_notes
   */
  static function sendInvoice($params) {
    watchdog('ExactHelper', print_r($params, TRUE));
  }

  /***********************************************************
   * Exact connection methods:
   *   authorize
   *   connect
   ***********************************************************/

  static function authorize() {
    $connection = new \Picqer\Financials\Exact\Connection();
    $connection->setRedirectUrl(CLIENT_REDIRECT_URL);
    $connection->setExactClientId(CLIENT_ID);
    $connection->setExactClientSecret(CLIENT_SECRET);
    $connection->redirectForAuthorization();
  }

  static function connect() {
    $connection = new \Picqer\Financials\Exact\Connection();
    $connection->setRedirectUrl(CLIENT_REDIRECT_URL);
    $connection->setExactClientId(CLIENT_ID);
    $connection->setExactClientSecret(CLIENT_SECRET);

    $v = CRM_Core_Session::singleton()->get('authorizationcode', 'ExactSession');
    if ($v) {
      $connection->setAuthorizationCode($v);
    }

    $v = CRM_Core_Session::singleton()->get('accesstoken', 'ExactSession');
    if ($v) {
      $connection->setAccessToken($v);
    }

    $v = CRM_Core_Session::singleton()->get('refreshtoken', 'ExactSession');
    if ($v) {
      $connection->setRefreshToken($v);
    }

    $v = CRM_Core_Session::singleton()->get('expires_in', 'ExactSession');
    if ($v) {
      $connection->setTokenExpires($v);
    }

    // Make the client connect and exchange tokens
    try {
      $connection->connect();
    } catch (\Exception $e) {
      throw new Exception('Could not connect to Exact: ' . $e->getMessage());
    }

    // Save the connection values
    CRM_Core_Session::singleton()->set('accesstoken', $connection->getAccessToken(), 'ExactSession');
    CRM_Core_Session::singleton()->set('refreshtoken', $connection->getRefreshToken(), 'ExactSession');
    CRM_Core_Session::singleton()->set('expires_in', $connection->getTokenExpires(), 'ExactSession');

    return $connection;
  }
}