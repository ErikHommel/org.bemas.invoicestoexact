<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('CLIENT_ID', 'e875a214-b33c-4381-9a9c-b34ed7c1010f');
define('CLIENT_SECRET', 'Pu69HwB767TC');
define('CLIENT_REDIRECT_URL', 'http://54.36.191.163/bemasexacttest/exactonline-api-php-client/example/example.php');

// Autoload composer installed libraries
require __DIR__ . '/../vendor/autoload.php';

/**
 * Function to retrieve persisted data for the example
 * @param string $key
 * @return null|string
 */
function getValue($key)
{
    $storage = json_decode(file_get_contents('storage.json'), true);
    if (array_key_exists($key, $storage)) {
        return $storage[$key];
    }
    return null;
}

/**
 * Function to persist some data for the example
 * @param string $key
 * @param string $value
 */
function setValue($key, $value)
{
    $storage       = json_decode(file_get_contents('storage.json'), true);
    $storage[$key] = $value;
    file_put_contents('storage.json', json_encode($storage));
}

/**
 * Function to authorize with Exact, this redirects to Exact login promt and retrieves authorization code
 * to set up requests for oAuth tokens
 */
function authorize()
{
    $connection = new \Picqer\Financials\Exact\Connection();
    $connection->setRedirectUrl(CLIENT_REDIRECT_URL);
    $connection->setExactClientId(CLIENT_ID);
    $connection->setExactClientSecret(CLIENT_SECRET);
    $connection->redirectForAuthorization();
}

/**
 * Function to connect to Exact, this creates the client and automatically retrieves oAuth tokens if needed
 *
 * @return \Picqer\Financials\Exact\Connection
 * @throws Exception
 */
function connect()
{
    $connection = new \Picqer\Financials\Exact\Connection();
    $connection->setRedirectUrl(CLIENT_REDIRECT_URL);
    $connection->setExactClientId(CLIENT_ID);
    $connection->setExactClientSecret(CLIENT_SECRET);

    if (getValue('authorizationcode')) // Retrieves authorizationcode from database
    {
        $connection->setAuthorizationCode(getValue('authorizationcode'));
    }

    if (getValue('accesstoken')) // Retrieves accesstoken from database
    {
        $connection->setAccessToken(getValue('accesstoken'));
    }

    if (getValue('refreshtoken')) // Retrieves refreshtoken from database
    {
        $connection->setRefreshToken(getValue('refreshtoken'));
    }

    if (getValue('expires_in')) // Retrieves expires timestamp from database
    {
        $connection->setTokenExpires(getValue('expires_in'));
    }

    // Make the client connect and exchange tokens
    try {
        $connection->connect();
    } catch (\Exception $e) {
        throw new Exception('Could not connect to Exact: ' . $e->getMessage());
    }

    // Save the new tokens for next connections
    setValue('accesstoken', $connection->getAccessToken());
    setValue('refreshtoken', $connection->getRefreshToken());

    // Save expires time for next connections
    setValue('expires_in', $connection->getTokenExpires());

    return $connection;
}

// If authorization code is returned from Exact, save this to use for token request
if (isset($_GET['code']) && is_null(getValue('authorizationcode'))) {
    setValue('authorizationcode', $_GET['code']);
}

// If we do not have a authorization code, authorize first to setup tokens
if (getValue('authorizationcode') === null) {
    authorize();
}

// Create the Exact client
$connection = connect();

// Get the journals from our administration
try {
  /*
    $journals = new \Picqer\Financials\Exact\Journal($connection);
    $result   = $journals->get();
    foreach ($result as $journal) {
        echo 'Journal: ' . $journal->Description . '<br>';
    }
*/

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
    $salesInvoiceLine->Quantity = 2;
    $salesInvoiceLine->Notes = "bla bla bla\nen nog eens bla bla bla";

    // add line to invoice
    $salesInvoice->SalesInvoiceLines = array($salesInvoiceLine);

    // insert invoice in Exact
    $s = $salesInvoice->insert();
    echo "<pre>"; var_dump($s); echo "</pre>";exit;
    echo "Ordernummer: " . $s['OrderNumber'] . '<br>';
    echo "InvoiceID: " . $s['InvoiceID'] . '<br>';

    exit;


    echo '<pre>';
    var_dump($salesInvoice);
    echo '/<pre>';
    echo "Invoice created: " . $salesInvoice->InvoiceNumber;
} catch (\Exception $e) {
    echo 'FOUT - ' . get_class($e) . ' : ' . $e->getMessage();
}