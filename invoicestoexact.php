<?php

require_once 'invoicestoexact.civix.php';
use CRM_Invoicestoexact_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function invoicestoexact_civicrm_config(&$config) {
  _invoicestoexact_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function invoicestoexact_civicrm_install() {
  _invoicestoexact_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function invoicestoexact_civicrm_enable() {
  _invoicestoexact_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---
/**
 * Implements hook_civicrm_searchTasks().
 */
function invoicestoexact_civicrm_searchTasks($objectType, &$tasks) {
  if ($objectType == 'contribution') {
    $tasks[] = [
      'title' => 'Send Invoice(s) to Exact',
      'class' => 'CRM_Invoicestoexact_Form_Task_InvoiceExact',
    ];
  }
}

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *

 // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function invoicestoexact_civicrm_navigationMenu(&$menu) {
  _invoicestoexact_civix_insert_navigation_menu($menu, NULL, array(
    'label' => E::ts('The Page'),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _invoicestoexact_civix_navigationMenu($menu);
} // */
