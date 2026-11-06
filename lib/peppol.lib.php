<?php

require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

/**
 * \file    peppol/lib/peppol.lib.php
 * \ingroup peppol
 * \brief   Library files with common functions for Peppol
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function peppolAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("peppol@peppolpeppyrus");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/peppolpeppyrus/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/peppolpeppyrus/admin/update.php", 1);
	$head[$h][1] = $langs->trans("PeppolUpdateTab");
	$head[$h][2] = 'update';
	$h++;

	$head[$h][0] = dol_buildpath("/peppolpeppyrus/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'peppol');

	return $head;
}

/**
 * return schema identifyer from vat number
 *
 * @param   string  $vatNumber  VAT number
 *
 * @return  string|null         Scheme identifier or null
 */
function peppolGetIdentifierSchemeFromVatNumber($vatNumber)
{
	global $conf;
	$res = null;
	$search = substr($vatNumber, 0, 2);
	if ($search == 'RO') {
		return null;
	}
	dol_syslog('peppolGetIdentifierSchemeFromVatNumber=' . $vatNumber . ", search=" . $search);

	$csvFile = __DIR__ . "/PeppolCodeLists-ParticipantIdentifierSchemesv7.5.csv";

	if (($handle = fopen($csvFile, "r")) !== false) {
		while (($data = fgetcsv($handle, 1024, ";")) !== false) {
			if ($data[0] == $search) {
				$res = $data[1];
				break;
			}
		}
		fclose($handle);
	}

	dol_syslog('peppolGetIdentifierSchemeFromVatNumber=' . $res);
	return $res;
}

/**
 * Clean amount string to float
 *
 * @param   string  $str  Amount string
 * @return  string        Cleaned amount
 */
function peppolAmountToFloat($str)
{
	$number = preg_replace('/[^-?\d.,]+/', '', $str);
	$ret = number_format($number, 2, '.', '');
	dol_syslog(" peppol clean amount as float $str -> $ret");
	return $ret;
}

/**
 * Payment reference written as remittance information (cbc:PaymentID, BT-83), the same as on
 * the PDF and in the EPC QR code (#6): the payment reference stored on the invoice (newer
 * Dolibarr versions), otherwise the Belgian structured communication when the Dolibarr option
 * INVOICE_PAYMENT_ENABLE_STRUCTURED_COMMUNICATION is on, otherwise the invoice reference.
 *
 * @param  Facture $invoice Invoice
 * @return string           Payment reference
 */
function peppolPaymentReference($invoice)
{
	if (!empty($invoice->payment_reference)) {
		return (string) $invoice->payment_reference;
	}
	if (getDolGlobalString('INVOICE_PAYMENT_ENABLE_STRUCTURED_COMMUNICATION')) {
		return peppolBEStructuredCommunication($invoice->ref, $invoice->type);
	}
	return (string) $invoice->ref;
}

/**
 * Belgian structured communication (+++123/4567/89012+++) of an invoice, as computed by
 * dolBECalculateStructuredCommunication() of Dolibarr 21 and later: invoice type prefix,
 * last 8 digits of the reference, modulo 97 check digits. Dolibarr's function is used when it
 * exists, the same computation otherwise (older Dolibarr versions).
 *
 * @param  string     $ref  Invoice reference
 * @param  int|string $type Invoice type (Facture::TYPE_*)
 * @return string           Structured communication
 */
function peppolBEStructuredCommunication($ref, $type)
{
	if (!function_exists('dolBECalculateStructuredCommunication') && is_readable(DOL_DOCUMENT_ROOT . '/core/lib/functions_be.lib.php')) {
		include_once DOL_DOCUMENT_ROOT . '/core/lib/functions_be.lib.php';
	}
	if (function_exists('dolBECalculateStructuredCommunication')) {
		return dolBECalculateStructuredCommunication((string) $ref, $type);
	}
	$prefixes = array('0' => '20', '1' => '30', '2' => '40', '3' => '21', '5' => '20');
	$number = (isset($prefixes[(string) $type]) ? $prefixes[(string) $type] : '00') . substr('00000000' . preg_replace('/[^0-9]/', '', (string) $ref), -8);
	$check = ((int) $number) % 97;
	$number .= sprintf('%02d', $check === 0 ? 97 : $check);
	return '+++' . substr($number, 0, 3) . '/' . substr($number, 3, 4) . '/' . substr($number, 7, 5) . '+++';
}

/**
 * Convert Dolibarr payment codes to UNCL4461 codes
 *
 * @param   string  $search  Dolibarr payment code
 * @return  array|null       UNCL4461 code and label
 */
function dolibarrToPeppolMeansCode($search)
{
	global $conf;
	$res = null;
	dol_syslog("dolibarrToPeppolMeansCode search=$search");

	$csvFile = __DIR__ . "/PeppolUNCL4461.csv";

	if (($handle = fopen($csvFile, "r")) !== false) {
		while (($data = fgetcsv($handle, 1024, ";")) !== false) {
			if ($data[0] == $search) {
				$res['code'] = $data[1];
				$res['label'] = $data[2];
				break;
			}
		}
		fclose($handle);
	}

	dol_syslog('dolibarrToPeppolMeansCode returns=' . json_encode($res));
	return $res;
}

function peppolNextRefLine()
{
	global $peppolLineRef;
	$peppolLineRef++;
	return $peppolLineRef;
}

/**
 * Get amount of RetainedWarranty
 *
 * @param   object  $invoice   Invoice object
 * @param   int     $rounding  Rounding
 * @return  float              Retained warranty amount
 */
function peppolGetRetainedWarrantyAmount($invoice, $rounding)
{
	if (is_callable(array($invoice, 'getRetainedWarrantyAmount'))) {
		return $invoice->getRetainedWarrantyAmount($rounding);
		$retainedDataReturnValue = $invoice->getRetainedWarrantyAmount($rounding);
		return $retainedDataReturnValue == -1 ? 0 : $retainedDataReturnValue;
	}
	return 0;
}

/**
 * Can this customer invoice or credit note go through Peppol (XML generation, sending)?
 *
 * Same rule as Dolibarr for sending a document by e-mail: validated or paid/closed.
 * A credit note converted into a discount or refunded is "paid", it must stay sendable.
 * Drafts and abandoned documents are excluded.
 *
 * @param   CommonObject  $invoice  Customer invoice
 * @return  bool
 */
function peppolIsInvoiceStatusEligible($invoice)
{
	if (!is_object($invoice)) {
		return false;
	}
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
	$status = isset($invoice->status) ? (int) $invoice->status : (int) $invoice->statut;
	return in_array($status, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true);
}

/**
 * May this user send customer invoices through Peppol?
 *
 * Module "write" right, or the rights Dolibarr asks for sending an invoice by e-mail
 * (create invoices, or the advanced "send" right).
 *
 * @param   User  $user  User
 * @return  bool
 */
function peppolUserCanSend($user)
{
	if (!is_object($user)) {
		return false;
	}
	return !empty($user->admin)
		|| !empty($user->rights->peppolpeppyrus->write)
		|| !empty($user->rights->facture->creer)
		|| !empty($user->rights->facture->invoice_advance->send);
}

/**
 * Checks done before sending a document to the access point (confirm_peppolSendFile).
 *
 * @param   User          $user     User
 * @param   CommonObject  $invoice  Customer invoice
 * @param   string        $confirm  Answer of the confirmation dialog
 * @return  string                  '' when the document may be sent, otherwise the translation key of the reason
 */
function peppolSendRefusalReason($user, $invoice, $confirm)
{
	if ($confirm !== 'yes') {
		return 'PeppolSendNotConfirmed';
	}
	if (!is_object($invoice) || !isset($invoice->element) || $invoice->element !== 'facture') {
		return 'ErrorBadParameters';
	}
	if (!peppolUserCanSend($user)) {
		return 'NotEnoughPermissions';
	}
	if (!peppolDisclaimerAcceptance()) {
		return 'PeppolDisclaimerNotAccepted';
	}
	if (!peppolIsInvoiceStatusEligible($invoice)) {
		return 'PeppolErrorStatusNotEligible';
	}
	return '';
}

/**
 * Version of the disclaimer text: increase it when the text changes, a new acceptance is then required.
 */
if (!defined('PEPPOL_DISCLAIMER_VERSION')) {
	define('PEPPOL_DISCLAIMER_VERSION', 1);
}

/**
 * Folder of the disclaimer acceptance records (#24).
 *
 * A private folder of the data directory, not the Dolibarr constants: no Dolibarr screen can write
 * it, so an administrator (of this or another entity) cannot forge or alter the record through
 * the constants page.
 *
 * @return string
 */
function peppolDisclaimerDir()
{
	return defined('PEPPOL_DISCLAIMER_DIR') ? PEPPOL_DISCLAIMER_DIR : DOL_DATA_ROOT . '/peppolpeppyrus_update';
}

/**
 * Read all acceptance records.
 *
 * @return array  entity => [version, user_id, login, date]
 */
function peppolDisclaimerRecords()
{
	$file = peppolDisclaimerDir() . '/disclaimer.json';
	if (!is_file($file) || is_link($file)) {
		return array();
	}
	$data = json_decode((string) @file_get_contents($file), true);
	return is_array($data) ? $data : array();
}

/**
 * Acceptance of the current disclaimer in the current entity (#24).
 *
 * @return array|null  [version, user_id, login, date] or null when not accepted
 */
function peppolDisclaimerAcceptance()
{
	global $conf;
	$records = peppolDisclaimerRecords();
	$data = $records[(string) ((int) $conf->entity)] ?? null;
	if (!is_array($data) || (int) ($data['version'] ?? 0) !== PEPPOL_DISCLAIMER_VERSION || empty($data['user_id']) || empty($data['date'])) {
		return null;
	}
	return array('version' => (int) $data['version'], 'user_id' => (int) $data['user_id'], 'login' => (string) ($data['login'] ?? ''), 'date' => (int) $data['date']);
}

/**
 * Warning shown while the disclaimer is not accepted (#25): why sending is blocked, and for
 * administrators a link to the module setup where it can be accepted.
 *
 * @param User      $user  Current user
 * @param Translate $langs Translations
 * @return string          HTML (text escaped)
 */
function peppolDisclaimerNotice($user, $langs)
{
	$langs->load('peppol@peppolpeppyrus');
	$html = dol_escape_htmltag($langs->transnoentitiesnoconv('PeppolDisclaimerNotAcceptedNotice'));
	if (!empty($user->admin)) {
		$html .= ' <a href="' . dol_escape_htmltag(dol_buildpath('/peppolpeppyrus/admin/setup.php', 1)) . '">' . dol_escape_htmltag($langs->transnoentitiesnoconv('PeppolDisclaimerGoToSetup')) . '</a>';
	}
	return $html;
}

/**
 * Record that an administrator accepted the disclaimer (current record + append-only history).
 *
 * @param DoliDB $db   Database
 * @param User   $user Administrator
 * @return bool
 */
function peppolDisclaimerAccept($db, $user)
{
	global $conf;
	if (empty($user->admin) || !empty($user->socid)) {
		return false;
	}
	$dir = peppolDisclaimerDir();
	if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir)) {
		return false;
	}
	$lock = @fopen($dir . '/disclaimer.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX)) {
		return false;
	}
	$ok = false;
	try {
		$entry = array('version' => PEPPOL_DISCLAIMER_VERSION, 'user_id' => (int) $user->id, 'login' => (string) $user->login, 'date' => dol_now(), 'entity' => (int) $conf->entity);
		// History first: an acceptance is never recorded without its trace
		$log = $dir . '/disclaimer.log';
		if (is_link($log)) {
			return false;
		}
		$line = json_encode($entry) . "\n";
		$fh = @fopen($log, 'ab');
		if (!$fh || fwrite($fh, $line) !== strlen($line) || !fclose($fh)) {
			return false;
		}
		@chmod($log, 0600);
		$file = $dir . '/disclaimer.json';
		$records = peppolDisclaimerRecords();
		// Never overwrite a record file that cannot be read: the other entities would lose their acceptance
		if (is_file($file) && !$records && trim((string) @file_get_contents($file)) !== '') {
			return false;
		}
		$records[(string) ((int) $conf->entity)] = $entry;
		$json = json_encode($records);
		$tmp = $dir . '/disclaimer-' . bin2hex(random_bytes(8)) . '.tmp';
		$fh = @fopen($tmp, 'xb');
		if (!$fh) {
			return false;
		}
		$ok = fwrite($fh, $json) === strlen($json);
		$ok = fclose($fh) && $ok;
		@chmod($tmp, 0600);
		$ok = $ok && @rename($tmp, $dir . '/disclaimer.json');
		if (!$ok) {
			@unlink($tmp);
		}
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
	dol_syslog('peppolpeppyrus disclaimer version ' . PEPPOL_DISCLAIMER_VERSION . ' accepted by user ' . ((int) $user->id) . ' in entity ' . ((int) $conf->entity) . ($ok ? '' : ' (not saved)'), LOG_NOTICE);
	return $ok;
}

/**
 * Authentication of a webhook request.
 *
 * @param   string  $secretKey     Configured secret (PEPPOL_AP_WEBHOOK_KEY); empty = webhook disabled
 * @param   string  $rawPayload    Raw request body
 * @param   string  $hmacReceived  Signature received with the request (hex HMAC-SHA256 of the body)
 * @return  string                 '' when the request is authentic, otherwise the reason (never contains a secret)
 */
function peppolWebhookAuthRefusal($secretKey, $rawPayload, $hmacReceived)
{
	if ((string) $secretKey === '') {
		return 'webhook disabled (no secret key configured)';
	}
	if ((string) $hmacReceived === '') {
		return 'no signature';
	}
	if (!hash_equals(hash_hmac('sha256', (string) $rawPayload, (string) $secretKey), strtolower(trim((string) $hmacReceived)))) {
		return 'invalid signature';
	}
	return '';
}

/**
 * Does this object belong to one of the entities the current user may see (Multicompany)?
 *
 * @param   CommonObject  $object  Object with an entity property
 * @return  bool
 */
function peppolObjectIsInUserEntities($object)
{
	if (!is_object($object) || !isset($object->entity)) {
		return false;
	}
	$entities = array_map('intval', explode(',', getEntity($object->element)));
	return in_array((int) $object->entity, $entities, true);
}

/**
 * May this user see this customer invoice (read right + Dolibarr object access rules:
 * external users, sales representatives restricted to their customers, entities)?
 *
 * @param   User     $user     User
 * @param   Facture  $invoice  Customer invoice (loaded)
 * @return  bool
 */
function peppolUserCanReadInvoice($user, $invoice)
{
	if (!is_object($user) || !is_object($invoice) || empty($invoice->id)) {
		return false;
	}
	if (empty($user->admin) && empty($user->rights->facture->lire)) {
		return false;
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
	return (bool) checkUserAccessToObject($user, array('facture'), (int) $invoice->id, 'facture', '', 'fk_soc', 'rowid');
}

/**
 * Keep only the characters of an identifier (Peppol ids, message ids) received from the access point.
 *
 * @param   mixed   $value  Value received
 * @return  string          Letters, digits and . _ : @ - only
 */
function peppolSanitizeIdentifier($value)
{
	return preg_replace('/[^A-Za-z0-9._:@-]/', '', (string) $value);
}

/**
 * CSRF token check for actions the module handles itself.
 *
 * @param   string  $tokenGiven     Token received with the request
 * @param   string  $tokenExpected  Token of the session (currentToken())
 * @return  bool
 */
function peppolTokenIsValid($tokenGiven, $tokenExpected)
{
	return (string) $tokenGiven !== '' && (string) $tokenExpected !== '' && hash_equals((string) $tokenExpected, (string) $tokenGiven);
}

/**
 * May this user open the incoming Peppol documents (module right "Import Peppol invoices")?
 *
 * @param   User  $user  User
 * @return  bool
 */
function peppolUserCanImport($user)
{
	return is_object($user) && empty($user->socid) && (!empty($user->admin) || !empty($user->rights->peppolpeppyrus->import));
}

/**
 * Peppol ID stored on a third party, from a directory search result.
 *
 * @param   string  $peppolScheme   Scheme of the directory, e.g. "iso6523-actorid-upis::0208"
 * @param   string  $participantId  Participant identifier, e.g. "0000000097"
 * @return  string                  "schemeCode:participantId" (e.g. "0208:0000000097"), or the participant id alone when the scheme is unknown
 */
function peppolBuildThirdpartyPeppolId($peppolScheme, $participantId)
{
	$participantId = trim((string) $participantId);
	if (preg_match('/::(\d+)$/', (string) $peppolScheme, $m) && $participantId !== '') {
		return $m[1] . ':' . $participantId;
	}
	return $participantId;
}

/**
 * Checks before storing a Peppol ID on a third party from the search page.
 *
 * @param   User    $user           User
 * @param   string  $tokenGiven     Token received with the request
 * @param   string  $tokenExpected  Token of the session (currentToken())
 * @return  string                  '' when allowed, otherwise the translation key of the reason
 */
function peppolSetThirdpartyIdRefusalReason($user, $tokenGiven, $tokenExpected)
{
	if (!peppolTokenIsValid($tokenGiven, $tokenExpected)) {
		return 'ErrorBadValueForParameter';
	}
	if (!is_object($user) || (empty($user->admin) && empty($user->rights->societe->creer))) {
		return 'NotEnoughPermissions';
	}
	return '';
}

/**
 * Get list of files linked to object
 *
 * @param   CommonObject  $obj  Object
 * @return  array               List of files
 */
function peppolListOfFilesLinkedTo(CommonObject $obj)
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';

	$ecmfile = new EcmFiles($obj->db);
	$result = $ecmfile->fetchAll('', '', 0, 0, array('t.src_object_type' => $obj->element, 't.src_object_id' => $obj->id));

	$filearray = array();
	if (is_array($ecmfile->lines) && count($ecmfile->lines) > 0) {
		foreach ($ecmfile->lines as $key => $fileEntry) {
			$peppolxml = str_replace(".pdf", "_peppol.xml", $fileEntry->filename);
			$pfx = DOL_DATA_ROOT . '/' . $fileEntry->filepath . '/' . $peppolxml;
			if (file_exists($pfx)) {
				$filearray[$peppolxml] = $peppolxml;
			}
		}
	}

	// Fallback: search directly on disk if not found via ECM
	if (count($filearray) == 0 && $obj->element == 'facture') {
		$dir = $conf->facture->dir_output . '/' . $obj->ref;
		$xmlfile = $obj->ref . '_peppol.xml';
		$fullpath = $dir . '/' . $xmlfile;
		if (file_exists($fullpath)) {
			$filearray[$xmlfile] = $xmlfile;
		}
	}

	dol_syslog("peppolListOfFilesLinkedTo list is = " . json_encode($filearray));
	return $filearray;
}

/**
 * Try to find file to use based on last_main_doc if exists
 *
 * @param   CommonObject  $obj          Object
 * @param   string        $defaultPath  Default path
 * @return  string                      File name
 */
function peppolFindFileToUse(CommonObject $obj, $defaultPath)
{
	global $conf;
	$filename = "";

	if (GETPOSTISSET('selectFilename')) {
		$filename = dol_sanitizeFileName(GETPOST('selectFilename', "aZ09"));
	} else {
		$peppolxml = str_replace(".pdf", "_peppol.xml", $obj->last_main_doc);
		if (dol_is_file(DOL_DATA_ROOT . '/' . $peppolxml)) {
			$filename = $peppolxml;
		}
	}

	// Only the Peppol XML generated by the module can be sent (#21)
	$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
	if ($ext != "xml" || !preg_match('/_peppol\.xml$/i', (string) $filename)) {
		dol_syslog("peppolFindFileToUse fix race condition, ext was=" . $ext);
		return -1;
	}
	$pfx = DOL_DATA_ROOT . '/' . str_replace(basename($obj->last_main_doc), '', $obj->last_main_doc) . '/' . $filename;

	if (dol_is_file($pfx)) {
		dol_syslog("peppolFindFileToUse filename=$pfx, file found; return fullpath");
		$filename = $pfx;
	}
	return $filename;
}

/**
 * Instantiate the Peppyrus AP class
 *
 * @return  mixed  PeppolAP class or false if error loading the class
 */
function peppolGetAPObject()
{
	global $db;
	$obj = false;

	// Always use Peppyrus
	$ap = 'ap-peppyrus.class.php';

	dol_syslog("peppolGetAPObject ap=$ap");
	if (dol_include_once('/peppolpeppyrus/class/' . $ap)) {
		$classname = "\\custom\\peppolpeppyrus\\Peppyrus";
		dol_syslog("peppolGetAPObject classname=$classname");
		$obj = new $classname($db);
	}
	return $obj;
}

/**
 * Send file to peppol access point
 *
 * @param   string  $filename  File name
 * @param   object  $invoice   Invoice object
 * @return  void
 */
function peppolSendToAccessPoint($filename, $invoice)
{
	global $langs, $conf, $user, $db;
	// Nothing is sent before an administrator accepted the disclaimer (#24)
	if (!peppolDisclaimerAcceptance()) {
		$langs->load('peppol@peppolpeppyrus');
		setEventMessages($langs->trans('PeppolDisclaimerNotAccepted'), null, 'errors');
		return;
	}
	$obj = peppolGetAPObject();
	if ($obj) {
		$obj->sendToAccessPoint($filename, $invoice);
	} else {
		dol_syslog("peppolSendToAccessPoint :: obj is null !");
	}
}

/**
 * Check access point connection
 *
 * @param   object  $invoice  Invoice object
 * @return  void
 */
function peppolCheckAccessPoint($invoice)
{
	global $langs, $conf, $user, $db;
	$obj = peppolGetAPObject();
	if ($obj) {
		$obj->checkAccessPoint($invoice);
	} else {
		dol_syslog("peppolCheckAccessPoint :: obj is null !");
	}
}

function peppolConcatMessages($arr)
{
	$message = "";
	foreach ($arr as $entry) {
		$message = $entry->rejectionDetail->message . "\n";
		foreach ($entry->rejectionDetail->errors as $err) {
			$message .= $err->message;
		}
	}
	return $message;
}

function peppolGetSupplierInvoicesList()
{
	global $langs, $conf, $user, $db;
	$obj = peppolGetAPObject();
	if ($obj) {
		$obj->getSupplierInvoicesList();
	} else {
		dol_syslog("peppolGetSupplierInvoicesList :: obj is null !");
	}
}

function peppolAddLog($ap_name, $fk_object, $object_typeid, $status, $message, $fulldata) {
	global $db, $user;
	// Values quoted and cut to the column sizes of llx_peppol; fk_user_creat is mandatory (#18)
	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "peppol (fk_object, object_typeid, status, message, fulldata, ap_name, fk_user_creat) VALUES (";
	$sql .= ((int) $fk_object) . ", ";
	$sql .= ((int) $object_typeid) . ", ";
	$sql .= "'" . $db->escape(dol_substr((string) $status, 0, 16)) . "', ";
	$sql .= "'" . $db->escape(dol_substr((string) $message, 0, 255)) . "', ";
	$sql .= "'" . $db->escape(dol_substr((string) $fulldata, 0, 65000)) . "', ";
	$sql .= "'" . $db->escape(dol_substr((string) $ap_name, 0, 16)) . "', ";
	$sql .= ((int) (is_object($user) ? $user->id : 0)) . ")";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog("peppolAddLog error: " . $db->lasterror(), LOG_ERR);
		return -1;
	}
	return 1;
}
