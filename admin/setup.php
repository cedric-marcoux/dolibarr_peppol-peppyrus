<?php
// SPDX-License-Identifier: GPL-3.0-or-later

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


/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
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

	// Secrets (names end with _KEY so dolibarr_set_const() stores them encrypted).
	// An empty field means: keep the current value. Secrets are never sent back to the browser.
	$secretfields = array(
		'PEPPOL_AP_API_KEY' => 'PEPPOL_AP_API_KEY',
		'PEPPOL_AP_DEV_API_KEY' => 'PEPPOL_AP_DEV_API_KEY',
		'PEPPOL_AP_WEBHOOK_KEY' => 'PEPPOL_AP_WEBHOOK_KEY',
	);
	foreach ($secretfields as $field => $constname) {
		$secret = trim(GETPOST($field, 'alphanohtml'));
		if ($secret !== '') {
			if (!dolibarr_set_const($db, $constname, $secret, 'chaine', 0, '', $conf->entity)) {
				$error++;
			} elseif ($constname == 'PEPPOL_AP_DEV_API_KEY') {
				// Old name was not encrypted, remove it once the new one is stored
				dolibarr_del_const($db, 'PEPPOL_AP_API_KEY_DEV', $conf->entity);
			}
		}
	}

	// VAT category of 0% lines: exemption reason (category E) or 'O' (not subject to VAT, old behaviour)
	$exemptcode = GETPOST('PEPPOL_VAT_EXEMPT_CODE', 'alphanohtml');
	if ($exemptcode !== 'O' && !array_key_exists($exemptcode, peppolVatExemptChoices())) {
		$error++;
	} elseif (!dolibarr_set_const($db, 'PEPPOL_VAT_EXEMPT_CODE', $exemptcode, 'chaine', 0, '', $conf->entity)) {
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

	// PEPPOL_EMBED_PDF (since 2.11.0): embed the PDF in the Peppol file, on by default
	if (!dolibarr_set_const($db, 'PEPPOL_EMBED_PDF', (string) (((int) GETPOST('PEPPOL_EMBED_PDF', 'int')) ? 1 : 0), 'chaine', 0, '', $conf->entity)) {
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

// Automation and booking (own form on this page)
if ($action == 'updateauto' && !empty($user->admin)) {
	$error = 0;
	$mode = GETPOST('PEPPOL_BOOK_MODE', 'aZ09') === 'draft' ? 'draft' : 'validate';
	$error += dolibarr_set_const($db, 'PEPPOL_BOOK_MODE', $mode, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	$error += dolibarr_set_const($db, 'PEPPOL_BOOK_CREATE_SUPPLIER', (string) (((int) GETPOST('PEPPOL_BOOK_CREATE_SUPPLIER', 'int')) ? 1 : 0), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	$error += dolibarr_set_const($db, 'PEPPOL_BOOK_UPDATE_SUPPLIER', (string) (((int) GETPOST('PEPPOL_BOOK_UPDATE_SUPPLIER', 'int')) ? 1 : 0), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;

	$error += peppolSetCronJob($db, 'runDailyFetch', ((int) GETPOST('PEPPOL_JOB_FETCH', 'int'))) ? 0 : 1;
	$error += peppolSetCronJob($db, 'runWeeklyCheck', ((int) GETPOST('PEPPOL_JOB_CANDIDATES', 'int'))) ? 0 : 1;

	// Automatic sending: when switched on, only invoices validated from now on are sent
	$sendOn = ((int) GETPOST('PEPPOL_AUTO_SEND', 'int')) ? 1 : 0;
	if ($sendOn && !getDolGlobalInt('PEPPOL_AUTO_SEND')) {
		dolibarr_set_const($db, 'PEPPOL_AUTO_SEND_SINCE', (string) dol_now(), 'chaine', 0, '', $conf->entity);
		dolibarr_del_const($db, 'PEPPOL_AUTO_SEND_LAST_SLOT', $conf->entity);
	}
	$error += peppolSetCronJob($db, 'runAutoSend', $sendOn) ? 0 : 1;
	require_once __DIR__ . '/../class/peppolautosend.class.php';
	$hours = implode(',', PeppolAutoSend::hours(GETPOST('PEPPOL_AUTO_SEND_HOURS', 'alphanohtml')));
	$error += dolibarr_set_const($db, 'PEPPOL_AUTO_SEND_HOURS', $hours, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
	$max = min(500, max(1, ((int) GETPOST('PEPPOL_AUTO_SEND_MAX', 'int'))));
	$error += dolibarr_set_const($db, 'PEPPOL_AUTO_SEND_MAX', (string) $max, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;

	setEventMessages($langs->trans($error ? "Error" : "SetupSaved"), null, $error ? 'errors' : 'mesgs');
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

print peppolDolibarrRecommendedNotice();
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
	print '<td><input type="text" name="PEPPOL_AP_SENDER_ID" id="PEPPOL_AP_SENDER_ID" class="flat minwidth400" value="' . dol_escape_htmltag(getDolGlobalString('PEPPOL_AP_SENDER_ID', '')) . '" placeholder="0208:0000000097"></td>';
	print '</tr>';

	// PEPPOL_AP_API_KEY (Production)
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_AP_API_KEY">' . $langs->trans("PEPPOL_AP_API_KEY") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_AP_API_KEYTooltip") . '</span></td>';
	print '<td><input type="password" name="PEPPOL_AP_API_KEY" id="PEPPOL_AP_API_KEY" class="flat minwidth400" value="" autocomplete="new-password" placeholder="' . dol_escape_htmltag($langs->trans(peppolGetApiKey('PEPPOL_AP_API_KEY') ? "PeppolSecretKeepCurrent" : "NotConfigured")) . '"></td>';
	print '</tr>';

	// PEPPOL_AP_DEV_API_KEY (Test/Development)
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_AP_DEV_API_KEY">' . $langs->trans("PEPPOL_AP_API_KEY_DEV") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_AP_API_KEY_DEVTooltip") . '</span></td>';
	print '<td><input type="password" name="PEPPOL_AP_DEV_API_KEY" id="PEPPOL_AP_DEV_API_KEY" class="flat minwidth400" value="" autocomplete="new-password" placeholder="' . dol_escape_htmltag($langs->trans(peppolGetApiKey('PEPPOL_AP_DEV_API_KEY') ? "PeppolSecretKeepCurrent" : "NotConfigured")) . '"></td>';
	print '</tr>';

	// PEPPOL_AP_WEBHOOK_KEY
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_AP_WEBHOOK_KEY">' . $langs->trans("PEPPOL_AP_WEBHOOK_KEY") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_AP_WEBHOOK_KEYTooltip") . '</span></td>';
	print '<td><input type="password" name="PEPPOL_AP_WEBHOOK_KEY" id="PEPPOL_AP_WEBHOOK_KEY" class="flat minwidth400" value="" autocomplete="new-password" placeholder="' . dol_escape_htmltag($langs->trans(getDolGlobalString('PEPPOL_AP_WEBHOOK_KEY') ? "PeppolSecretKeepCurrent" : "NotConfigured")) . '"></td>';
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

	// PEPPOL_VAT_EXEMPT_CODE
	$curexempt = peppolVatExemptCode();
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_VAT_EXEMPT_CODE">' . $langs->trans("PEPPOL_VAT_EXEMPT_CODE") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_VAT_EXEMPT_CODETooltip") . '</span></td>';
	print '<td><select name="PEPPOL_VAT_EXEMPT_CODE" id="PEPPOL_VAT_EXEMPT_CODE" class="flat minwidth400">';
	print '<option value="O"' . ($curexempt === '' ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans("PeppolVatCategoryO")) . '</option>';
	foreach (peppolVatExemptChoices() as $code => $label) {
		print '<option value="' . dol_escape_htmltag($code) . '"' . ($curexempt === $code ? ' selected' : '') . '>' . dol_escape_htmltag($code . ' - ' . $label) . '</option>';
	}
	print '</select></td>';
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

	// PEPPOL_EMBED_PDF (since 2.11.0)
	print '<tr class="oddeven">';
	print '<td><label for="PEPPOL_EMBED_PDF">' . $langs->trans("PEPPOL_EMBED_PDF") . '</label>';
	print '<br><span class="opacitymedium small">' . $langs->trans("PEPPOL_EMBED_PDFTooltip") . '</span></td>';
	print '<td>';
	print '<select name="PEPPOL_EMBED_PDF" id="PEPPOL_EMBED_PDF" class="flat">';
	print '<option value="1"' . (getDolGlobalInt('PEPPOL_EMBED_PDF', 1) === 1 ? ' selected' : '') . '>' . $langs->trans("Yes") . '</option>';
	print '<option value="0"' . (getDolGlobalInt('PEPPOL_EMBED_PDF', 1) === 1 ? '' : ' selected') . '>' . $langs->trans("No") . '</option>';
	print '</select>';
	print '</td>';
	print '</tr>';

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
	print '<td>' . ($sender_id ? dol_escape_htmltag($sender_id) : '<span class="opacitymedium">' . $langs->trans("NotConfigured") . '</span>') . '</td>';
	print '</tr>';

	// Secrets: only show whether they are configured, never (part of) the value
	$secretrows = array(
		'PEPPOL_AP_API_KEY' => peppolGetApiKey('PEPPOL_AP_API_KEY'),
		'PEPPOL_AP_API_KEY_DEV' => peppolGetApiKey('PEPPOL_AP_DEV_API_KEY'),
		'PEPPOL_AP_WEBHOOK_KEY' => getDolGlobalString('PEPPOL_AP_WEBHOOK_KEY'),
	);
	foreach ($secretrows as $label => $secretvalue) {
		print '<tr class="oddeven">';
		print '<td>' . $langs->trans($label) . '</td>';
		if ($secretvalue !== '') {
			print '<td>' . $langs->trans("PeppolSecretConfigured") . '</td>';
		} else {
			print '<td><span class="opacitymedium">' . $langs->trans("NotConfigured") . '</span></td>';
		}
		print '</tr>';
	}

	// PEPPOL_PROD
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_PROD") . '</td>';
	print '<td>' . yn(getDolGlobalString('PEPPOL_PROD', '')) . '</td>';
	print '</tr>';

	// PEPPOL_VAT_EXEMPT_CODE
	$curexempt = peppolVatExemptCode();
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_VAT_EXEMPT_CODE") . '</td>';
	print '<td>' . dol_escape_htmltag($curexempt !== '' ? 'E: ' . $curexempt . ' - ' . peppolVatExemptChoices()[$curexempt] : $langs->trans("PeppolVatCategoryO")) . '</td>';
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

	// PEPPOL_EMBED_PDF (since 2.11.0)
	print '<tr class="oddeven">';
	print '<td>' . $langs->trans("PEPPOL_EMBED_PDF") . '</td>';
	print '<td>' . yn(getDolGlobalInt('PEPPOL_EMBED_PDF', 1)) . '</td>';
	print '</tr>';

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

// Automation and booking: what the module does by itself, and how incoming invoices are booked
$jobStatus = array();
foreach (array_keys(peppolCronSettings()) as $method) {
	$jobStatus[$method] = peppolCronJobStatus($db, $method);
}
$yesno = function ($name, $value) use ($langs) {
	$html = '<select name="' . $name . '" id="' . $name . '" class="flat">';
	$html .= '<option value="1"' . ($value ? ' selected' : '') . '>' . $langs->trans('Yes') . '</option>';
	$html .= '<option value="0"' . ($value ? '' : ' selected') . '>' . $langs->trans('No') . '</option></select>';
	return $html;
};
$jobNote = function ($status) use ($langs) {
	return $status < 0 ? '<br><span class="warning small">' . $langs->trans('peppolSetupJobMissing') . '</span>' : '';
};

print '<br>';
print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="updateauto">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans('peppolSetupBooking') . '</td></tr>';

print '<tr class="oddeven"><td><label for="PEPPOL_BOOK_MODE">' . $langs->trans('peppolSetupBookMode') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('peppolSetupBookModeHelp') . '</span></td><td>';
print '<select name="PEPPOL_BOOK_MODE" id="PEPPOL_BOOK_MODE" class="flat">';
foreach (array('validate' => 'peppolSetupBookModeValidate', 'draft' => 'peppolSetupBookModeDraft') as $k => $label) {
	print '<option value="' . $k . '"' . (getDolGlobalString('PEPPOL_BOOK_MODE', 'validate') === $k ? ' selected' : '') . '>' . $langs->trans($label) . '</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td><label for="PEPPOL_BOOK_CREATE_SUPPLIER">' . $langs->trans('peppolSetupCreateSupplier') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('peppolSetupCreateSupplierHelp') . '</span></td>';
print '<td>' . $yesno('PEPPOL_BOOK_CREATE_SUPPLIER', getDolGlobalInt('PEPPOL_BOOK_CREATE_SUPPLIER', 1)) . '</td></tr>';

print '<tr class="oddeven"><td><label for="PEPPOL_BOOK_UPDATE_SUPPLIER">' . $langs->trans('peppolSetupUpdateSupplier') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('peppolSetupUpdateSupplierHelp') . '</span></td>';
print '<td>' . $yesno('PEPPOL_BOOK_UPDATE_SUPPLIER', getDolGlobalInt('PEPPOL_BOOK_UPDATE_SUPPLIER', 1)) . '</td></tr>';

print '<tr class="liste_titre"><td colspan="2">' . $langs->trans('peppolSetupAutomation') . '</td></tr>';

print '<tr class="oddeven"><td><label for="PEPPOL_JOB_FETCH">' . $langs->trans('peppolSetupJobFetch') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('peppolSetupJobFetchHelp') . '</span>' . $jobNote($jobStatus['runDailyFetch']) . '</td>';
print '<td>' . $yesno('PEPPOL_JOB_FETCH', $jobStatus['runDailyFetch'] > 0) . '</td></tr>';

print '<tr class="oddeven"><td><label for="PEPPOL_JOB_CANDIDATES">' . $langs->trans('peppolSetupJobCandidates') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('peppolSetupJobCandidatesHelp') . '</span>' . $jobNote($jobStatus['runWeeklyCheck']) . '</td>';
print '<td>' . $yesno('PEPPOL_JOB_CANDIDATES', $jobStatus['runWeeklyCheck'] > 0) . '</td></tr>';

print '<tr class="oddeven"><td><label for="PEPPOL_AUTO_SEND">' . $langs->trans('peppolSetupAutoSend') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('peppolSetupAutoSendHelp') . '</span>' . $jobNote($jobStatus['runAutoSend']);
if ($jobStatus['runAutoSend'] > 0 && getDolGlobalInt('PEPPOL_AUTO_SEND_SINCE') > 0) {
	print '<br><span class="small">' . $langs->trans('peppolSetupAutoSendSince', dol_print_date(getDolGlobalInt('PEPPOL_AUTO_SEND_SINCE'), 'dayhour', 'tzuserrel')) . '</span>';
}
if ($jobStatus['runAutoSend'] > 0 && !getDolGlobalString('PEPPOL_PROD')) {
	print '<br><span class="warning small">' . $langs->trans('peppolSetupAutoSendTestMode') . '</span>';
}
print '</td><td>' . $yesno('PEPPOL_AUTO_SEND', $jobStatus['runAutoSend'] > 0) . '</td></tr>';

print '<tr class="oddeven"><td><label for="PEPPOL_AUTO_SEND_HOURS">' . $langs->trans('peppolSetupAutoSendHours') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('peppolSetupAutoSendHoursHelp') . '</span></td>';
print '<td><input type="text" class="flat width100" name="PEPPOL_AUTO_SEND_HOURS" id="PEPPOL_AUTO_SEND_HOURS" value="' . dol_escape_htmltag(getDolGlobalString('PEPPOL_AUTO_SEND_HOURS', '9')) . '" placeholder="9"></td></tr>';

print '<tr class="oddeven"><td><label for="PEPPOL_AUTO_SEND_MAX">' . $langs->trans('peppolSetupAutoSendMax') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('peppolSetupAutoSendMaxHelp') . '</span></td>';
print '<td><input type="number" min="1" max="500" class="flat width75" name="PEPPOL_AUTO_SEND_MAX" id="PEPPOL_AUTO_SEND_MAX" value="' . getDolGlobalInt('PEPPOL_AUTO_SEND_MAX', 20) . '"></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('peppolSetupCronList') . '</td><td>';
if (isModEnabled('cron')) {
	print '<a href="' . DOL_URL_ROOT . '/cron/list.php?search_module_name=peppolpeppyrus">' . img_picto('', 'cron', 'class="pictofixedwidth"') . $langs->trans('peppolSetupCronListLink') . '</a>';
} else {
	print '<span class="warning">' . $langs->trans('peppolSetupCronModuleOff') . '</span>';
}
print '</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="' . $langs->trans('Save') . '"></div>';
print '</form>';

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
