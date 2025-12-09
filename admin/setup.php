<?php

/**
 * \file    peppol/admin/setup.php
 * \ingroup peppol
 * \brief   Peppyrus configuration page - unified setup
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $langs, $user, $conf, $db;

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

// Translations
$langs->loadLangs(array("admin", "peppol@peppolpeppyrus"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

/*
 * Actions
 */
if ($action == 'update' && !empty($user->admin)) {
	$error = 0;

	// Update PEPPOL_AP_SENDER_ID
	$sender_id = GETPOST('PEPPOL_AP_SENDER_ID', 'alpha');
	if (!dolibarr_set_const($db, 'PEPPOL_AP_SENDER_ID', $sender_id, 'chaine', 0, '', $conf->entity)) {
		$error++;
	}

	// Update PEPPOL_AP_API_KEY (Production)
	// Use 'alphanohtml' to allow alphanumeric characters (API keys contain numbers)
	$api_key = GETPOST('PEPPOL_AP_API_KEY', 'alphanohtml');
	if (!dolibarr_set_const($db, 'PEPPOL_AP_API_KEY', $api_key, 'chaine', 0, '', $conf->entity)) {
		$error++;
	}

	// Update PEPPOL_AP_API_KEY_DEV (Test/Development)
	// Use 'alphanohtml' to allow alphanumeric characters (API keys contain numbers)
	$api_key_dev = GETPOST('PEPPOL_AP_API_KEY_DEV', 'alphanohtml');
	if (!dolibarr_set_const($db, 'PEPPOL_AP_API_KEY_DEV', $api_key_dev, 'chaine', 0, '', $conf->entity)) {
		$error++;
	}

	// Update PEPPOL_PROD
	$prod_mode = GETPOST('PEPPOL_PROD', 'int');
	if (!dolibarr_set_const($db, 'PEPPOL_PROD', $prod_mode, 'chaine', 0, '', $conf->entity)) {
		$error++;
	}

	// Update PEPPOL_FORCE_XML_WITH_VATNULL
	$force_xml = GETPOST('PEPPOL_FORCE_XML_WITH_VATNULL', 'int');
	if (!dolibarr_set_const($db, 'PEPPOL_FORCE_XML_WITH_VATNULL', $force_xml, 'chaine', 0, '', $conf->entity)) {
		$error++;
	}

	// Update PEPPOL_USE_TRIGGER
	$use_trigger = GETPOST('PEPPOL_USE_TRIGGER', 'int');
	if (!dolibarr_set_const($db, 'PEPPOL_USE_TRIGGER', $use_trigger, 'chaine', 0, '', $conf->entity)) {
		$error++;
	}

	// Auto-set Peppyrus as the access point
	dolibarr_set_const($db, 'PEPPOL_CHOOSE_AP', 'ap-peppyrus.class.php', 'chaine', 0, '', $conf->entity);

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}

	header("Location: " . $_SERVER["PHP_SELF"]);
	exit;
}

// Test connection action
if ($action == 'check') {
	$fact = new Facture($db);
	peppolCheckAccessPoint($fact);
}

/*
 * View
 */
$form = new Form($db);

$page_name = "PeppolSetup";
llxHeader('', $langs->trans($page_name), '');

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = peppolAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, "peppol@peppolpeppyrus");

// Current mode info box
$isProd = getDolGlobalString('PEPPOL_PROD', '');
$apiUrl = $isProd ? 'https://api.peppyrus.be/v1/' : 'https://api.test.peppyrus.be/v1/';
$modeLabel = $isProd ? $langs->trans('peppolModeProduction') : $langs->trans('peppolModeTest');
$modeClass = $isProd ? 'warning' : 'info';

print '<div class="'.$modeClass.'" style="margin-bottom: 20px; padding: 15px;">';
print '<i class="fas fa-'.($isProd ? 'exclamation-triangle' : 'info-circle').'"></i> ';
print '<strong>' . $langs->trans('peppolCurrentMode') . ':</strong> ';
print '<span style="font-weight: bold; color: '.($isProd ? '#c00' : '#060').';">' . $modeLabel . '</span><br>';
print '<strong>' . $langs->trans('peppolApiUrl') . ':</strong> <code>' . $apiUrl . '</code>';
if (!$isProd) {
	print '<br><br><em style="color: #666;">' . $langs->trans('peppolTestModeWarning') . '</em>';
}
print '</div>';

if ($action == 'edit') {
	// Edit mode
	print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="update">';

	print '<table class="noborder centpercent">';
	print '<thead>';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("Parameter") . '</td>';
	print '<td>' . $langs->trans("Value") . '</td>';
	print '</tr>';
	print '</thead>';
	print '<tbody>';

	// PEPPOL_AP_SENDER_ID
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_AP_SENDER_ID">' . $langs->trans("PEPPOL_AP_SENDER_ID") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_AP_SENDER_IDTooltip") . '</span></td>';
	print '<td><input type="text" name="PEPPOL_AP_SENDER_ID" id="PEPPOL_AP_SENDER_ID" class="flat minwidth400" value="' . getDolGlobalString('PEPPOL_AP_SENDER_ID', '') . '" placeholder="0208:0000000097"></td>';
	print '</tr>';

	// PEPPOL_AP_API_KEY (Production)
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_AP_API_KEY">' . $langs->trans("PEPPOL_AP_API_KEY") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_AP_API_KEYTooltip") . '</span></td>';
	print '<td><input type="text" name="PEPPOL_AP_API_KEY" id="PEPPOL_AP_API_KEY" class="flat minwidth400" value="' . getDolGlobalString('PEPPOL_AP_API_KEY', '') . '"></td>';
	print '</tr>';

	// PEPPOL_AP_API_KEY_DEV (Test/Development)
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_AP_API_KEY_DEV">' . $langs->trans("PEPPOL_AP_API_KEY_DEV") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_AP_API_KEY_DEVTooltip") . '</span></td>';
	print '<td><input type="text" name="PEPPOL_AP_API_KEY_DEV" id="PEPPOL_AP_API_KEY_DEV" class="flat minwidth400" value="' . getDolGlobalString('PEPPOL_AP_API_KEY_DEV', '') . '"></td>';
	print '</tr>';

	// PEPPOL_PROD
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_PROD">' . $langs->trans("PEPPOL_PROD") . '</label></td>';
	print '<td>';
	print '<select name="PEPPOL_PROD" id="PEPPOL_PROD" class="flat">';
	print '<option value="0"' . (getDolGlobalString('PEPPOL_PROD', '') ? '' : ' selected') . '>' . $langs->trans("No") . ' (' . $langs->trans("peppolModeTest") . ')</option>';
	print '<option value="1"' . (getDolGlobalString('PEPPOL_PROD', '') ? ' selected' : '') . '>' . $langs->trans("Yes") . ' (' . $langs->trans("peppolModeProduction") . ')</option>';
	print '</select>';
	print '</td>';
	print '</tr>';

	print '</tbody>';
	print '</table>';

	// Section Configuration générale
	print '<br>';
	print '<table class="noborder centpercent">';
	print '<thead>';
	print '<tr class="liste_titre">';
	print '<td colspan="2">' . $langs->trans("PEPPOL_CONFIG") . '</td>';
	print '</tr>';
	print '</thead>';
	print '<tbody>';

	// PEPPOL_FORCE_XML_WITH_VATNULL
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_FORCE_XML_WITH_VATNULL">' . $langs->trans("PEPPOL_FORCE_XML_WITH_VATNULL") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_FORCE_XML_WITH_VATNULLTooltip") . '</span></td>';
	print '<td>';
	print '<select name="PEPPOL_FORCE_XML_WITH_VATNULL" id="PEPPOL_FORCE_XML_WITH_VATNULL" class="flat">';
	print '<option value="0"' . (getDolGlobalString('PEPPOL_FORCE_XML_WITH_VATNULL', '') ? '' : ' selected') . '>' . $langs->trans("No") . '</option>';
	print '<option value="1"' . (getDolGlobalString('PEPPOL_FORCE_XML_WITH_VATNULL', '') ? ' selected' : '') . '>' . $langs->trans("Yes") . '</option>';
	print '</select>';
	print '</td>';
	print '</tr>';

	// PEPPOL_USE_TRIGGER
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_USE_TRIGGER">' . $langs->trans("PEPPOL_USE_TRIGGER") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_USE_TRIGGERTooltip") . '</span></td>';
	print '<td>';
	print '<select name="PEPPOL_USE_TRIGGER" id="PEPPOL_USE_TRIGGER" class="flat">';
	print '<option value="0"' . (getDolGlobalString('PEPPOL_USE_TRIGGER', '') ? '' : ' selected') . '>' . $langs->trans("No") . '</option>';
	print '<option value="1"' . (getDolGlobalString('PEPPOL_USE_TRIGGER', '') ? ' selected' : '') . '>' . $langs->trans("Yes") . '</option>';
	print '</select>';
	print '</td>';
	print '</tr>';

	print '</tbody>';
	print '</table>';

	print '<br>';
	print '<div class="center">';
	print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
	print ' &nbsp; ';
	print '<a class="button button-cancel" href="' . $_SERVER["PHP_SELF"] . '">' . $langs->trans("Cancel") . '</a>';
	print '</div>';

	print '</form>';

} else {
	// View mode
	print '<table class="noborder centpercent">';
	print '<thead>';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("Parameter") . '</td>';
	print '<td>' . $langs->trans("Value") . '</td>';
	print '</tr>';
	print '</thead>';
	print '<tbody>';

	// PEPPOL_AP_SENDER_ID
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_AP_SENDER_ID") . '</td>';
	$sender_id = getDolGlobalString('PEPPOL_AP_SENDER_ID', '');
	print '<td>' . ($sender_id ? $sender_id : '<span class="opacitymedium">' . $langs->trans("NotConfigured") . '</span>') . '</td>';
	print '</tr>';

	// PEPPOL_AP_API_KEY (Production)
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_AP_API_KEY") . '</td>';
	$api_key = getDolGlobalString('PEPPOL_AP_API_KEY', '');
	if ($api_key) {
		// Mask API key for security
		$masked = substr($api_key, 0, 4) . str_repeat('*', max(0, strlen($api_key) - 8)) . substr($api_key, -4);
		print '<td>' . $masked . '</td>';
	} else {
		print '<td><span class="opacitymedium">' . $langs->trans("NotConfigured") . '</span></td>';
	}
	print '</tr>';

	// PEPPOL_AP_API_KEY_DEV (Test/Development)
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_AP_API_KEY_DEV") . '</td>';
	$api_key_dev = getDolGlobalString('PEPPOL_AP_API_KEY_DEV', '');
	if ($api_key_dev) {
		// Mask API key for security
		$masked_dev = substr($api_key_dev, 0, 4) . str_repeat('*', max(0, strlen($api_key_dev) - 8)) . substr($api_key_dev, -4);
		print '<td>' . $masked_dev . '</td>';
	} else {
		print '<td><span class="opacitymedium">' . $langs->trans("NotConfigured") . '</span></td>';
	}
	print '</tr>';

	// PEPPOL_PROD
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_PROD") . '</td>';
	print '<td>' . yn(getDolGlobalString('PEPPOL_PROD', '')) . '</td>';
	print '</tr>';

	print '</tbody>';
	print '</table>';

	// Section Configuration générale
	print '<br>';
	print '<table class="noborder centpercent">';
	print '<thead>';
	print '<tr class="liste_titre">';
	print '<td colspan="2">' . $langs->trans("PEPPOL_CONFIG") . '</td>';
	print '</tr>';
	print '</thead>';
	print '<tbody>';

	// PEPPOL_FORCE_XML_WITH_VATNULL
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_FORCE_XML_WITH_VATNULL") . '</td>';
	print '<td>' . yn(getDolGlobalString('PEPPOL_FORCE_XML_WITH_VATNULL', '')) . '</td>';
	print '</tr>';

	// PEPPOL_USE_TRIGGER
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_USE_TRIGGER") . '</td>';
	print '<td>' . yn(getDolGlobalString('PEPPOL_USE_TRIGGER', '')) . '</td>';
	print '</tr>';

	print '</tbody>';
	print '</table>';

	print '<div class="tabsAction">';
	print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=edit&token=' . newToken() . '">' . $langs->trans("Modify") . '</a>';
	print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=check&token=' . newToken() . '">' . $langs->trans("peppolBtnCheckServer") . '</a>';
	print '</div>';
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
