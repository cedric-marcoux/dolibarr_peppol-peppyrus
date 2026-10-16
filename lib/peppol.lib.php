<?php

require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

/**
 * \file    peppol/lib/peppol.lib.php
 * \ingroup peppol
 * \brief   Library files with common functions for Peppol
 */

/**
 * Return a Peppyrus API key setting.
 * The test key used to be stored as PEPPOL_AP_API_KEY_DEV, a name that is not encrypted by
 * dolibarr_set_const(); it is now PEPPOL_AP_DEV_API_KEY. The old name is still read until the key is saved again.
 *
 * @param	string	$name	PEPPOL_AP_API_KEY or PEPPOL_AP_DEV_API_KEY
 * @return	string			The key, or '' if not configured
 */
function peppolGetApiKey($name)
{
	$value = getDolGlobalString($name);
	if ($value === '' && $name == 'PEPPOL_AP_DEV_API_KEY') {
		$value = getDolGlobalString('PEPPOL_AP_API_KEY_DEV');
	}
	return $value;
}

/**
 * Search terms to find a third party in the Peppol directory, in the order to try them.
 * Participants are registered with different identifiers depending on the country: for example VAT number (9944 for NL,
 * 9925 for BE), registration number (0106 KvK for NL, 0208 for BE) or another national number. The directory search
 * matches identifiers and names, so try the VAT number, then professional ID 1 (KvK number in the Netherlands), then the name.
 *
 * @param	Societe	$thirdparty		Third party
 * @return	array<string,string>	criterion => search term ('vat', 'profid1', 'name'), empty or duplicate terms left out
 */
function peppolDirectorySearchCandidates($thirdparty)
{
	$candidates = array();
	$vat = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($thirdparty->tva_intra ?? '')));
	if ($vat !== '') {
		$candidates['vat'] = $vat;
	}
	$profid1 = preg_replace('/[^A-Za-z0-9]/', '', (string) ($thirdparty->idprof1 ?? ''));
	if ($profid1 !== '' && strlen($profid1) >= 6 && !in_array($profid1, $candidates, true)) {
		$candidates['profid1'] = $profid1;
	}
	$name = trim(preg_replace('/\s+/', ' ', (string) ($thirdparty->name ?? '')));
	if ($name !== '') {
		$candidates['name'] = $name;
	}
	return $candidates;
}

/**
 * Label of a Peppol participant identifier scheme (ICD / EAS code), for display.
 *
 * @param	string	$icd	Scheme code, e.g. 0106
 * @return	string			Translation key result, or '' when unknown
 */
function peppolSchemeLabel($icd)
{
	global $langs;
	if (!preg_match('/^\d{4}$/', (string) $icd)) {
		return '';
	}
	$key = 'peppolScheme' . $icd;
	$label = $langs->trans($key);
	return ($label === $key) ? '' : $label;
}

/**
 * Turn one match of the Peppol directory into the data shown to the user, and compare it with the third party.
 *
 * @param	array		$match		One element of the "matches" list of the directory JSON
 * @param	Societe|null	$thirdparty	Third party the search was started from, or null
 * @return	array					participantId, scheme, icd, value, name, country, location, regDate,
 *									additionalInfo, acceptsInvoice (true/false/null when unknown), matchedField ('' when no match)
 */
function peppolDirectoryMatchInfo($match, $thirdparty = null)
{
	$info = array(
		'participantId' => '', 'scheme' => '', 'icd' => '', 'value' => '', 'name' => '', 'country' => '',
		'location' => '', 'regDate' => '', 'additionalInfo' => '', 'acceptsInvoice' => null, 'matchedField' => '',
	);
	if (!is_array($match)) {
		return $info;
	}
	$info['participantId'] = (string) ($match['participantID']['value'] ?? '');
	$info['scheme'] = (string) ($match['participantID']['scheme'] ?? '');
	if (preg_match('/^(\d{4}):(.+)$/', $info['participantId'], $reg)) {
		$info['icd'] = $reg[1];
		$info['value'] = $reg[2];
	} else {
		$info['value'] = $info['participantId'];
	}
	$entity = (isset($match['entities'][0]) && is_array($match['entities'][0])) ? $match['entities'][0] : array();
	$info['name'] = (string) ($entity['name'][0]['name'] ?? '');
	$info['country'] = (string) ($entity['countryCode'] ?? '');
	$info['location'] = trim(preg_replace('/\s+/', ' ', (string) ($entity['geoInfo'] ?? '')));
	$info['regDate'] = (string) ($entity['regDate'] ?? '');
	$info['additionalInfo'] = trim((string) ($entity['additionalInfo'] ?? ''));

	// Can this ID receive invoices? Look for the UBL Invoice document type in the registered document types.
	if (isset($match['docTypes']) && is_array($match['docTypes'])) {
		$info['acceptsInvoice'] = false;
		foreach ($match['docTypes'] as $doctype) {
			$value = (string) ($doctype['value'] ?? '');
			if (strpos($value, 'Invoice-2::Invoice') !== false) {
				$info['acceptsInvoice'] = true;
				break;
			}
		}
	}

	// Does the ID match the VAT number or a professional ID of the third party?
	if (is_object($thirdparty)) {
		$normalize = function ($v) {
			return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
		};
		$value = $normalize($info['value']);
		if ($value !== '') {
			$fields = array('tva_intra' => 'VATIntra', 'idprof1' => 'ProfId1', 'idprof2' => 'ProfId2', 'idprof3' => 'ProfId3', 'idprof4' => 'ProfId4', 'idprof5' => 'ProfId5', 'idprof6' => 'ProfId6');
			foreach ($fields as $field => $labelkey) {
				$own = $normalize($thirdparty->$field ?? '');
				if ($own !== '' && $own === $value) {
					$info['matchedField'] = $field;
					break;
				}
			}
		}
	}
	return $info;
}

/**
 * URL of the third party card to return to after the Peppol ID search.
 * Built from a fixed list and the third party id, never from a URL given in the request (no open redirect).
 *
 * @param	int		$socid		Third party id
 * @param	string	$backto		'card' (third party), 'comm' (customer) or 'fourn' (supplier)
 * @return	string				Relative URL, '' without third party
 */
function peppolThirdpartyBackUrl($socid, $backto)
{
	if ((int) $socid <= 0) {
		return '';
	}
	$pages = array('card' => '/societe/card.php', 'comm' => '/comm/card.php', 'fourn' => '/fourn/card.php');
	$page = isset($pages[$backto]) ? $pages[$backto] : $pages['card'];
	return DOL_URL_ROOT . $page . '?socid=' . ((int) $socid);
}

/**
 * Check the anti-CSRF token of a request that changes data through a link (GET)
 *
 * @return	bool	true if the token sent with the request is the current session token
 */
function peppolCheckToken()
{
	$token = GETPOST('token', 'alpha');
	return ($token !== '' && function_exists('currentToken') && hash_equals((string) currentToken(), $token));
}

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
	dol_syslog('peppolGetIdentifierSchemeFromVatNumber country prefix=' . $search);

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

	$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
	if ($ext != "xml") {
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
	global $db, $user, $conf;
	$sql = "INSERT INTO ".MAIN_DB_PREFIX."peppol(entity,fk_object,object_typeid,status,message,fulldata,ap_name,fk_user_creat) VALUES (";
	$sql .= ((int) $conf->entity) . ", ";
	$sql .= ((int) $fk_object) . ", ";
	$sql .= ((int) $object_typeid) . ", ";
	$sql .= "'" . $db->escape(dol_trunc((string) $status, 16, 'right', 'UTF-8', 1)) . "', ";
	$sql .= "'" . $db->escape(dol_trunc((string) $message, 255, 'right', 'UTF-8', 1)) . "', ";
	$sql .= "'" . $db->escape((string) $fulldata) . "', ";
	$sql .= "'" . $db->escape(dol_trunc((string) $ap_name, 16, 'right', 'UTF-8', 1)) . "', ";
	$sql .= ((int) (is_object($user) ? $user->id : 0)) . ")";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog("peppolAddLog error " . $db->lasterror(), LOG_ERR);
		return -1;
	}
	return 1;
}
