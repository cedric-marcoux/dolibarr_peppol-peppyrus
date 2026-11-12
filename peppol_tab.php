<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 *      \file       peppol_tab.php
 *      \ingroup    peppol
 *      \brief      View peppol history and details from AP
 */

//if (! defined('NOREQUIREDB'))			  define('NOREQUIREDB', '1');				// Do not create database handler $db
//if (! defined('NOREQUIREUSER'))			define('NOREQUIREUSER', '1');				// Do not load object $user
//if (! defined('NOREQUIRESOC'))			 define('NOREQUIRESOC', '1');				// Do not load object $mysoc
//if (! defined('NOREQUIRETRAN'))			define('NOREQUIRETRAN', '1');				// Do not load object $langs
//if (! defined('NOSCANGETFORINJECTION'))	define('NOSCANGETFORINJECTION', '1');		// Do not check injection attack on GET parameters
//if (! defined('NOSCANPOSTFORINJECTION'))   define('NOSCANPOSTFORINJECTION', '1');		// Do not check injection attack on POST parameters
//if (! defined('NOCSRFCHECK'))			  define('NOCSRFCHECK', '1');				// Do not check CSRF attack (test on referer + on token if option MAIN_SECURITY_CSRF_WITH_TOKEN is on).
//if (! defined('NOTOKENRENEWAL'))		   define('NOTOKENRENEWAL', '1');				// Do not roll the Anti CSRF token (used if MAIN_SECURITY_CSRF_WITH_TOKEN is on)
//if (! defined('NOSTYLECHECK'))			 define('NOSTYLECHECK', '1');				// Do not check style html tag into posted data
//if (! defined('NOREQUIREMENU'))			define('NOREQUIREMENU', '1');				// If there is no need to load and show top and left menu
//if (! defined('NOREQUIREHTML'))			define('NOREQUIREHTML', '1');				// If we don't need to load the html.form.class.php
//if (! defined('NOREQUIREAJAX'))			define('NOREQUIREAJAX', '1');	   	  	// Do not load ajax.lib.php library
//if (! defined("NOLOGIN"))				  define("NOLOGIN", '1');					// If this page is public (can be called outside logged session). This include the NOIPCHECK too.
//if (! defined('NOIPCHECK'))				define('NOIPCHECK', '1');					// Do not check IP defined into conf $dolibarr_main_restrict_ip
//if (! defined("MAIN_LANG_DEFAULT"))		define('MAIN_LANG_DEFAULT', 'auto');					// Force lang to a particular value
//if (! defined("MAIN_AUTHENTICATION_MODE")) define('MAIN_AUTHENTICATION_MODE', 'aloginmodule');	// Force authentication handler
//if (! defined("NOREDIRECTBYMAINTOLOGIN"))  define('NOREDIRECTBYMAINTOLOGIN', 1);		// The main.inc.php does not make a redirect if not logged, instead show simple error message
//if (! defined("FORCECSP"))				 define('FORCECSP', 'none');				// Disable all Content Security Policies
//if (! defined('CSRFCHECK_WITH_TOKEN'))	 define('CSRFCHECK_WITH_TOKEN', '1');		// Force use of CSRF protection with tokens even for GET
//if (! defined('NOBROWSERNOTIF'))	 		 define('NOBROWSERNOTIF', '1');				// Disable browser notification
// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}


/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';

// load peppol libraries
dol_include_once('/peppolpeppyrus/class/peppol.class.php');
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

use custom\peppolpeppyrus\Peppol;


// Load translation files required by the page
$langs->loadLangs(array('peppol@peppolpeppyrus', 'companies', 'bills'));

$id = (GETPOST('id', 'int') ? GETPOST('id', 'int') : GETPOST('facid', 'int')); // For backward compatibility
$ref = GETPOST('ref', 'alpha');
$socid = GETPOST('socid', 'int');
$action = GETPOST('action', 'aZ09');

$object = new Facture($db);
$res = 0;
// Load object
if ($id > 0 || !empty($ref)) {
	$res = $object->fetch($id, $ref, '', '', getDolGlobalInt('INVOICE_USE_SITUATION'));
}
if ($res < 0) {
	accessforbidden();
}
$peppol = new Peppol($db, $object);

// Security check
$socid = 0;
if ($user->socid) {
	$socid = $user->socid;
}
$hookmanager->initHooks(array('invoicepeppol'));

if (!isModEnabled('peppolpeppyrus') || !$user->hasRight('peppolpeppyrus', 'read')) {
	accessforbidden();
}
$result = restrictedArea($user, 'facture', $object->id, '');

$usercancreate = $user->hasRight("facture", "creer");

/*
 * Actions
 */

$reshook = $hookmanager->executeHooks('doActions', array(), $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}
if (empty($reshook)) {
	include DOL_DOCUMENT_ROOT . '/core/actions_setnotes.inc.php'; // Must be include, not include_once
}



/*
 * View
 */

$form = new Form($db);

if (empty($object->id)) {
	$title = $object->ref . " - " . $langs->trans('Peppol');
} else {
	$title = $langs->trans('Peppol');
}
$helpurl = '';

llxHeader('', $title, $helpurl);

if (empty($object->id)) {
	$langs->load('errors');
	echo '<div class="error">' . $langs->trans("ErrorRecordNotFound") . '</div>';
	llxFooter();
	exit;
}


if ($id > 0 || !empty($ref)) {
	peppolCheckAccessPoint($object);
	$object->fetch_thirdparty();

	$head = facture_prepare_head($object);

	$totalpaid = $object->getSommePaiement();

	print dol_get_fiche_head($head, 'tabPeppol', $langs->trans("InvoiceCustomer"), -1, 'bill');

	// Invoice content

	$linkback = '<a href="' . DOL_URL_ROOT . '/compta/facture/list.php?restore_lastsearch_values=1' . (!empty($socid) ? '&socid=' . $socid : '') . '">' . $langs->trans("BackToList") . '</a>';

	$morehtmlref = '<div class="refidno">';
	// Ref customer
	$morehtmlref .= $form->editfieldkey("RefCustomer", 'ref_client', $object->ref_client, $object, 0, 'string', '', 0, 1);
	$morehtmlref .= $form->editfieldval("RefCustomer", 'ref_client', $object->ref_client, $object, 0, 'string', '', null, null, '', 1);
	// Thirdparty
	$morehtmlref .= '<br>' . $object->thirdparty->getNomUrl(1, 'customer');
	// Project
	if (isModEnabled('project')) {
		$langs->load("projects");
		$morehtmlref .= '<br>';
		if (!empty($object->fk_project)) {
			$proj = new Project($db);
			$proj->fetch($object->fk_project);
			$morehtmlref .= $proj->getNomUrl(1);
			if ($proj->title) {
				$morehtmlref .= '<span class="opacitymedium"> - ' . dol_escape_htmltag($proj->title) . '</span>';
			}
		}
	}
	$morehtmlref .= '</div>';

	$object->totalpaid = $totalpaid; // To give a chance to dol_banner_tab to use already paid amount to show correct status

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '', 0);

	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';


	$cssclass = "titlefield";

	print "<p>" . $langs->trans("PeppolTrackDocumentInAP") . "</p>";


	$sql = "SELECT status, message, fulldata FROM " . MAIN_DB_PREFIX . "peppol WHERE fk_object = " . ((int) $object->id) . " AND object_typeid = 1";
	$sql .= " AND entity IN (" . getEntity('invoice') . ") ORDER BY tms";
	$dupres = $db->query($sql);

	while ($obj = $db->fetch_object($dupres)) {
		print "<p>" . $langs->trans("Status") . " : " . dol_escape_htmltag($obj->status) . "</p>";
		print "<p>" . $langs->trans("Message") . " : " . dol_escape_htmltag($obj->message) . "</p>";
		print "<p>" . $langs->trans("Details") . " : <br /><pre>" . dol_escape_htmltag((string) $obj->fulldata) . "</pre></p>";
	}

	// Since 2.12.0: the Peppyrus report once (not per log line), with the fields Peppyrus really returns: the validation
	// rules (FATAL or WARNING) and the transmission rules
	$object->fetch_optionals();
	$peppolMessageId = isset($object->array_options['options_peppol_id']) ? (string) $object->array_options['options_peppol_id'] : '';
	if ($peppolMessageId !== '') {
		$ap = peppolGetAPObject();
		if ($ap && method_exists($ap, 'getMessageReport')) {
			$report = peppolReportRules($ap->getMessageReport($peppolMessageId));
			print '<div class="fichecenter" style="margin-top: 20px;">';
			print '<h3>' . $langs->trans('peppolDeliveryReport') . '</h3>';
			print '<table class="border centpercent">';
			print '<tr><td class="titlefield">' . $langs->trans('peppolReportTransmission') . '</td><td>' . ($report['transport'] !== '' ? dol_escape_htmltag($report['transport']) : '<span class="opacitymedium">' . $langs->trans('peppolReportNone') . '</span>') . '</td></tr>';
			print '<tr><td>' . $langs->trans('peppolReportValidation') . '</td><td>';
			if (empty($report['rules'])) {
				print img_picto('', 'tick') . ' ' . $langs->trans('peppolReportNoRules');
			} else {
				print '<table class="noborder centpercent"><tr class="liste_titre"><th>' . $langs->trans('Type') . '</th><th>' . $langs->trans('peppolReportRule') . '</th><th>' . $langs->trans('peppolReportLocation') . '</th><th>' . $langs->trans('Message') . '</th></tr>';
				foreach ($report['rules'] as $r) {
					print '<tr class="oddeven"><td class="nowraponall' . ($r['type'] === 'FATAL' ? ' error' : '') . '">' . dol_escape_htmltag($r['type']) . '</td><td class="nowraponall">' . dol_escape_htmltag($r['id']) . '</td>';
					print '<td class="small">' . dol_escape_htmltag($r['location']) . '</td><td>' . dol_escape_htmltag($r['message']) . '</td></tr>';
				}
				print '</table>';
			}
			print '</td></tr>';
			print '</table>';
			print '</div>';
		}
	}


	print dol_get_fiche_end();
}

// End of page
llxFooter();
$db->close();
