<?php

class CRM_Invoicestoexact_ExactHelper {
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
      $exactOL = new CRM_Exactonline_Utils();
      $exactOL->exactConnection->connect();

      // find the customer
      $customerFinder = new \Picqer\Financials\Exact\Account($exactOL->exactConnection);
      $c = $customerFinder->filter("trim(Code) eq '" . $params['contact_code'] . "'");
      if (count($c) !== 1) {
        throw new Exception("klant niet gevonden");
      }
      $customer = $c[0];

      // find the product (article)
      $itemFinder = new \Picqer\Financials\Exact\Item($exactOL->exactConnection);
      $i = $itemFinder->filter("Code eq '" . $params['item_code'] . "'");
      if (count($i) !== 1) {
        throw new Exception("artikel niet gevonden");
      }
      $item = $i[0];

      // create the invoice
      $salesInvoice = new \Picqer\Financials\Exact\SalesInvoice($exactOL->exactConnection);
      $salesInvoice->InvoiceTo = $customer->ID;
      $salesInvoice->OrderedBy = $customer->ID;
      $salesInvoice->Description = $params['invoice_description'];

      // create the invoice line
      $salesInvoiceLine = new \Picqer\Financials\Exact\SalesInvoiceLine($exactOL->exactConnection);
      $salesInvoiceLine->Item = $item->ID;
      $salesInvoiceLine->Quantity = 1;
      $salesInvoiceLine->UnitPrice = $params['unit_price'];
      $salesInvoiceLine->Notes = $params['line_notes'];

      // add line to invoice
      $salesInvoice->SalesInvoiceLines = array($salesInvoiceLine);

      // send to Exact!
      $s = $salesInvoice->insert();

      // success, return the order number
      $returnArr['is_error'] = 0;
      $returnArr['error_message'] = '';
      $returnArr['order_number'] = $s['OrderNumber'];
    }
    catch (Exception $e) {
      $returnArr['is_error'] = 1;
      $returnArr['error_message'] = $e->getMessage();
      $returnArr['order_number'] = -1;
    }

    return $returnArr;
  }
}
