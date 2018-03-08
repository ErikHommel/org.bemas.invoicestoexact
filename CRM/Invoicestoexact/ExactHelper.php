<?php

require_once __DIR__ . '/../../exactonline-api-php-client/vendor/autoload.php';

define('CLIENT_REDIRECT_URL', 'civicrm/invoicestoexact-webhook');

class CRM_Invoicestoexact_ExactHelper {
  static function redirectUrl() {
    try {
      // If authorization code is returned from Exact, save this to use for token request
      $storedAuthorizationCode = self::get('authorizationcode');
      if (isset($_GET['code']) && is_null($storedAuthorizationCode)) {
        self::set('authorizationcode', $_GET['code']);
      }
      // If we do not have a authorization code, authorize first to setup tokens
      if (self::get('authorizationcode') === null) {
        self::authorize();
      }

    } catch (\Exception $e) {
      echo 'FOUT: ' . $e->getMessage();
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
    $returnArr = array();

    try {
      $connection = self::connect();// find the customer
      $customerFinder = new \Picqer\Financials\Exact\Account($connection);
      $c = $customerFinder->filter("trim(Code) eq '" . $params['contact_code'] . "'");
      if (count($c) !== 1) {
        throw new Exception("klant niet gevonden");
      }
      $customer = $c[0];// find the product
      $itemFinder = new Picqer\Financials\Exact\Item($connection);
      $i = $itemFinder->filter("Code eq '" . $params['item_code'] . "'");
      if (count($i) !== 1) {
        throw new Exception("artikel niet gevonden");
      }
      $item = $i[0];// create the invoice
      $salesInvoice = new \Picqer\Financials\Exact\SalesInvoice($connection);
      $salesInvoice->InvoiceTo = $customer->ID;
      $salesInvoice->OrderedBy = $customer->ID;
      $salesInvoice->Description = $params['invoice_description'];// add an invoice line
      $salesInvoiceLine = new \Picqer\Financials\Exact\SalesInvoiceLine($connection);
      $salesInvoiceLine->Item = $item->ID;
      $salesInvoiceLine->Quantity = 1;
      $salesInvoiceLine->UnitPrice = $params['unit_price'];
      $salesInvoiceLine->Notes = $params['line_notes'];// add line to invoice
      $salesInvoice->SalesInvoiceLines = [$salesInvoiceLine];// insert invoice in Exact
      $s = $salesInvoice->insert();

      // success, return invoice number
      $returnArr['is_error'] = 0;
      $returnArr['error_message'] = '';
      $returnArr['invoice_id'] = $salesInvoice->InvoiceNumber;
    }
    catch (Exception $e) {
      $returnArr['is_error'] = 1;
      $returnArr['error_message'] = $e->getMessage();
      $returnArr['invoice_id'] = -1;
    }

    return $returnArr;
  }

  /***********************************************************
   * Exact connection methods:
   *   authorize
   *   connect
   *
   * Storage methods:
   *   get
   *   set
   ***********************************************************/

  static function authorize() {
    // get client id / secret option value
    $params = array(
      'option_group_id' => 'bemas_items_to_exact',
      'label' => 'Client ID/Client Secret',
    );
    $v = civicrm_api3('OptionValue', 'getsingle', $params);
    $vArr = explode('/', $v['value']);
    $clientID = $vArr[0];
    $clientSecret = $vArr[1];

    $url = CRM_Utils_System::url(CLIENT_REDIRECT_URL, 'reset=1', TRUE);
    $connection = new \Picqer\Financials\Exact\Connection();
    $connection->setRedirectUrl($url);
    $connection->setExactClientId($clientID);
    $connection->setExactClientSecret($clientSecret);
    $connection->redirectForAuthorization();
  }

  static function connect() {
    // get client id / secret option value
    $params = array(
      'option_group_id' => 'bemas_items_to_exact',
      'label' => 'Client ID/Client Secret',
    );
    $v = civicrm_api3('OptionValue', 'getsingle', $params);
    $vArr = explode('/', $v['value']);
    $clientID = $vArr[0];
    $clientSecret = $vArr[1];

    $url = CRM_Utils_System::url(CLIENT_REDIRECT_URL, 'reset=1', TRUE);
    $connection = new \Picqer\Financials\Exact\Connection();
    $connection->setRedirectUrl($url);
    $connection->setExactClientId($clientID);
    $connection->setExactClientSecret($clientSecret);

    $v = self::get('authorizationcode');
    if ($v) {
      $connection->setAuthorizationCode($v);
    }

    $v = self::get('accesstoken');
    if ($v) {
      $connection->setAccessToken($v);
    }

    $v = self::get('refreshtoken');
    if ($v) {
      $connection->setRefreshToken($v);
    }

    $v = self::get('expires_in');
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
    self::set('accesstoken', $connection->getAccessToken());
    self::set('refreshtoken', $connection->getRefreshToken());
    self::set('expires_in', $connection->getTokenExpires());

    return $connection;
  }

  static function get($key) {
    $storage = json_decode(file_get_contents(__DIR__ . '/storage.json'), true);
    if (array_key_exists($key, $storage)) {
      return $storage[$key];
    }
    return null;
  }

  static function set($key, $value) {
    $storage = json_decode(file_get_contents(__DIR__ . '/storage.json'), true);
    $storage[$key] = $value;
    file_put_contents(__DIR__ . '/storage.json', json_encode($storage));
  }
}
