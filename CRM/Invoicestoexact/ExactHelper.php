<?php

require_once __DIR__ . '/../../exactonline-api-php-client/vendor/autoload.php';

define('CLIENT_ID', 'e875a214-b33c-4381-9a9c-b34ed7c1010f');
define('CLIENT_SECRET', 'Pu69HwB767TC');
define('CLIENT_REDIRECT_URL', 'civicrm/invoicestoexact-webhook');

class MyCRM_Core_Session {
  static private $_singleton = NULL;

  public static function &singleton() {
    if (self::$_singleton === NULL) {
      self::$_singleton = new MyCRM_Core_Session();
    }
    return self::$_singleton;
  }

  public function get($key, $ignore) {
    $storage = json_decode(file_get_contents(__DIR__ . '/storage.json'), true);
    if (array_key_exists($key, $storage)) {
        return $storage[$key];
    }
    return null;
  }

  public function set($key, $value, $ignore) {
    $storage       = json_decode(file_get_contents(__DIR__ . '/storage.json'), true);
    $storage[$key] = $value;
    file_put_contents(__DIR__ . '/storage.json', json_encode($storage));
  }
}

class CRM_Invoicestoexact_ExactHelper {
  static function redirectUrl() {
    try {
      // If authorization code is returned from Exact, save this to use for token request
      $storedAuthorizationCode = MyCRM_Core_Session::singleton()->get('authorizationcode', 'ExactSession');
      if (isset($_GET['code']) && is_null($storedAuthorizationCode)) {
        MyCRM_Core_Session::singleton()->set('authorizationcode', $_GET['code'], 'ExactSession');
      }
      // If we do not have a authorization code, authorize first to setup tokens
      if (MyCRM_Core_Session::singleton()->get('authorizationcode', 'ExactSession') === null) {
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
    watchdog('alain', print_r($params, TRUE));
      $connection = self::connect();

      // find the customer
      $customerFinder = new \Picqer\Financials\Exact\Account($connection);
      $c = $customerFinder->filter("trim(Code) eq '" . $params['contact_code'] . "'");
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
      $salesInvoice->Description = $params['invoice_description'];

      // add an invoice line
      $salesInvoiceLine = new \Picqer\Financials\Exact\SalesInvoiceLine($connection);

      //$salesInvoiceLine->UnitCode = 'IND300661';
      $salesInvoiceLine->Item = $item->ID;
      $salesInvoiceLine->Quantity = 1;
      $salesInvoiceLine->UnitPrice = $params['unit_price'];
      $salesInvoiceLine->Notes = $params['line_notes'];

      // add line to invoice
      $salesInvoice->SalesInvoiceLines = array($salesInvoiceLine);

      // insert invoice in Exact
      $s = $salesInvoice->insert();
      echo "Ordernummer: " . $s['OrderNumber'] . '<br>';
      echo "InvoiceID: " . $s['InvoiceID'] . '<br>';

      echo "Invoice created: " . $salesInvoice->InvoiceNumber;
  }

  /***********************************************************
   * Exact connection methods:
   *   authorize
   *   connect
   ***********************************************************/

  static function authorize() {
    $url = CRM_Utils_System::url(CLIENT_REDIRECT_URL, 'reset=1', TRUE);
    $connection = new \Picqer\Financials\Exact\Connection();
    $connection->setRedirectUrl($url);
    $connection->setExactClientId(CLIENT_ID);
    $connection->setExactClientSecret(CLIENT_SECRET);
    $connection->redirectForAuthorization();
  }

  static function connect() {
    $url = CRM_Utils_System::url(CLIENT_REDIRECT_URL, 'reset=1', TRUE);
    $connection = new \Picqer\Financials\Exact\Connection();
    $connection->setRedirectUrl($url);
    $connection->setExactClientId(CLIENT_ID);
    $connection->setExactClientSecret(CLIENT_SECRET);

    $v = MyCRM_Core_Session::singleton()->get('authorizationcode', 'ExactSession');
    if ($v) {
      $connection->setAuthorizationCode($v);
    }

    $v = MyCRM_Core_Session::singleton()->get('accesstoken', 'ExactSession');
    if ($v) {
      $connection->setAccessToken($v);
    }

    $v = MyCRM_Core_Session::singleton()->get('refreshtoken', 'ExactSession');
    if ($v) {
      $connection->setRefreshToken($v);
    }

    $v = MyCRM_Core_Session::singleton()->get('expires_in', 'ExactSession');
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
    MyCRM_Core_Session::singleton()->set('accesstoken', $connection->getAccessToken(), 'ExactSession');
    MyCRM_Core_Session::singleton()->set('refreshtoken', $connection->getRefreshToken(), 'ExactSession');
    MyCRM_Core_Session::singleton()->set('expires_in', $connection->getTokenExpires(), 'ExactSession');

    return $connection;
  }
}
