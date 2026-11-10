<?php
// SPDX-License-Identifier: GPL-3.0-or-later

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
 * @param	array<string,mixed>|mixed	$match		One element of the "matches" list of the directory JSON
 * @param	Societe|\stdClass|null	$thirdparty	Third party the search was started from, or null
 * @return	array{participantId:string,scheme:string,icd:string,value:string,name:string,country:string,location:string,regDate:string,additionalInfo:string,acceptsInvoice:bool|null,matchedField:string}
 *			participantId, scheme, icd, value, name, country, location, regDate, additionalInfo,
 *			acceptsInvoice (true/false/null when unknown), matchedField ('' when no match)
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
 * Exemption reasons that can be chosen for exempt supplies (VAT category E), with the setting
 * PEPPOL_VAT_EXEMPT_CODE. 'O' keeps the old behaviour: category O (not subject to VAT).
 *
 * @return	array<string,string>	code => description
 */
function peppolVatExemptChoices()
{
	dol_include_once('/peppolpeppyrus/class/peppolvatexemption.class.php');
	$choices = array();
	foreach (\custom\peppolpeppyrus\PeppolVatExemption::VAT_EXEMPT_CODES as $code => $label) {
		if (strpos($code, 'VATEX-EU-132') === 0) {
			$choices[$code] = $label;
		}
	}
	return $choices;
}

/**
 * Exemption reason code for exempt supplies from the setting, or '' for category O (old behaviour).
 * Default: VATEX-EU-132-1C (medical care by medical professions, for example district nursing).
 *
 * @return	string
 */
function peppolVatExemptCode()
{
	$code = getDolGlobalString('PEPPOL_VAT_EXEMPT_CODE', 'VATEX-EU-132-1C');
	$choices = peppolVatExemptChoices();
	return isset($choices[$code]) ? $code : '';
}

/**
 * HTTPS request to the Peppyrus API, without Dolibarr's getURLContent().
 *
 * getURLContent() writes the headers it sent into the Dolibarr log ("request without content body",
 * Dolibarr 24), and the X-Api-Key header is among them. This function sends the same request with curl
 * and logs only the method, the host and the HTTP code: never headers, query string or content.
 * Only the Peppyrus hosts are allowed, over HTTPS with certificate verification, and redirects are not
 * followed (the API key would go along with them). The proxy settings of Dolibarr (MAIN_PROXY_*) are used.
 *
 * @param	string		$url		Full URL (https://api.peppyrus.be/... or https://api.test.peppyrus.be/...)
 * @param	string		$method		GET, POST, PATCH, PUT or DELETE
 * @param	string		$body		Request body ('' for none)
 * @param	string[]	$headers	Header lines "Name: value"
 * @return	array{http_code:int, content:string, curl_error_no:int, curl_error_msg:string}	Same keys as getURLContent()
 */
function peppolApiRequest($url, $method, $body, $headers)
{
	$result = array('http_code' => 0, 'content' => '', 'curl_error_no' => 0, 'curl_error_msg' => '');
	$parts = parse_url((string) $url);
	$host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
	// Peppyrus API (all methods) and the public Peppol directory (GET only)
	$allowed = (in_array($host, array('api.peppyrus.be', 'api.test.peppyrus.be'), true) && in_array($method, array('GET', 'POST', 'PATCH', 'PUT', 'DELETE'), true))
		|| ($host === 'directory.peppol.eu' && $method === 'GET');
	if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !$allowed || isset($parts['user']) || isset($parts['port'])) {
		dol_syslog('peppolApiRequest refused: not an HTTPS request to the Peppyrus API or the Peppol directory', LOG_ERR);
		$result['curl_error_no'] = -1;
		$result['curl_error_msg'] = 'Request refused (only HTTPS to the Peppyrus API or the Peppol directory)';
		return $result;
	}
	if (!function_exists('curl_init')) {
		$result['curl_error_no'] = -2;
		$result['curl_error_msg'] = 'PHP curl extension is missing';
		return $result;
	}

	$ch = curl_init($url);
	$options = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_CONNECTTIMEOUT => max(1, getDolGlobalInt('MAIN_USE_CONNECT_TIMEOUT', 10)),
		CURLOPT_TIMEOUT => max(1, getDolGlobalInt('MAIN_USE_RESPONSE_TIMEOUT', 30)),
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_USERAGENT => 'Dolibarr peppolpeppyrus',
	);
	if (defined('CURLOPT_PROTOCOLS_STR')) {
		$options[CURLOPT_PROTOCOLS_STR] = 'https';
	} else {
		$options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
	}
	if ($method !== 'GET' && $method !== 'DELETE') {
		$options[CURLOPT_POSTFIELDS] = (string) $body;
	}
	if (getDolGlobalString('MAIN_PROXY_USE')) {
		$options[CURLOPT_PROXY] = getDolGlobalString('MAIN_PROXY_HOST').':'.getDolGlobalString('MAIN_PROXY_PORT');
		if (getDolGlobalString('MAIN_PROXY_USER')) {
			$options[CURLOPT_PROXYUSERPWD] = getDolGlobalString('MAIN_PROXY_USER').':'.getDolGlobalString('MAIN_PROXY_PASS');
		}
	}
	curl_setopt_array($ch, $options);
	$response = curl_exec($ch);
	$result['http_code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$result['curl_error_no'] = (int) curl_errno($ch);
	$result['curl_error_msg'] = (string) curl_error($ch);
	$result['content'] = is_string($response) ? $response : '';
	// The handle is not closed explicitly: closing does nothing since PHP 8.0 and is deprecated in PHP 8.5; unset() frees it in every version
	unset($ch, $options, $headers);

	dol_syslog('peppolApiRequest '.$method.' '.$host.' HTTP '.$result['http_code'].($result['curl_error_no'] ? ' curl error '.$result['curl_error_no'] : ''));

	return $result;
}

/**
 * Search the public Peppol directory (directory.peppol.eu).
 * Goes through peppolApiRequest(), so the search term (a name, VAT or registration number) does not end up in the log.
 *
 * @param	string	$query	Search term
 * @return	array<string,mixed>	Decoded JSON of the directory ('matches' => [...]), or array('error' => message, 'reason' => ..., 'http_code' => ...)
 */
function peppolDirectorySearch($query)
{
	$url = 'https://directory.peppol.eu/search/1.0/json?' . http_build_query(array('q' => (string) $query));
	$result = peppolApiRequest($url, 'GET', '', array('Accept: application/json'));
	// On error also return the technical reason (HTTP code, curl error number): no search term in it
	$fail = array('http_code' => (int) $result['http_code'], 'curl_error_no' => (int) $result['curl_error_no']);
	if ($result['curl_error_no']) {
		return array('error' => 'Connection error: ' . $result['curl_error_msg'], 'reason' => 'curl error ' . $result['curl_error_no']) + $fail;
	}
	if ($result['http_code'] != 200) {
		return array('error' => 'HTTP error: ' . $result['http_code'], 'reason' => 'HTTP ' . $result['http_code']) + $fail;
	}
	if ($result['content'] === '') {
		return array('error' => 'Empty response from PEPPOL Directory', 'reason' => 'empty response') + $fail;
	}
	$data = json_decode($result['content'], true);
	if (!is_array($data)) {
		return array('error' => 'JSON decode error: ' . json_last_error_msg(), 'reason' => 'invalid JSON') + $fail;
	}
	return $data;
}

/**
 * Number of new Peppol candidates (third parties without a Peppol ID found in the directory) that the user may see
 *
 * @param	User	$user	User
 * @return	int				Number, -1 when unknown (for example the tables do not exist yet)
 */
function peppolCountNewCandidates($user)
{
	global $db, $conf;

	$sql = "SELECT COUNT(DISTINCT c.fk_soc) as nb FROM " . MAIN_DB_PREFIX . "peppol_candidate as c";
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = c.fk_soc";
	if (!$user->hasRight('societe', 'client', 'voir')) {
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe_commerciaux as sc ON sc.fk_soc = s.rowid AND sc.fk_user = " . ((int) $user->id);
	}
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe_extrafields as se ON se.fk_object = s.rowid";
	$sql .= " WHERE c.entity = " . ((int) $conf->entity) . " AND s.entity IN (" . getEntity('societe') . ")";
	$sql .= " AND c.status = 0 AND (se.peppol_id IS NULL OR se.peppol_id = '')";
	$resql = $db->query($sql);
	if (!$resql) {
		return -1;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	return $obj ? (int) $obj->nb : 0;
}

/**
 * Button to the Peppol candidates page, with the number of new candidates, for the title bar of a list
 *
 * @param	User	$user	User
 * @return	string			HTML, '' when the user may not see the page
 */
function peppolCandidatesButton($user)
{
	global $langs;

	if (!$user->hasRight('peppolpeppyrus', 'read') || !$user->hasRight('societe', 'lire') || !empty($user->socid)) {
		return '';
	}
	$langs->load("peppol@peppolpeppyrus");
	$nb = peppolCountNewCandidates($user);
	$label = $langs->trans('peppolCandidatesMenu');
	if ($nb > 0) {
		$label .= ' <span class="badge badge-status4">' . $langs->trans('peppolCandidatesNewCount', $nb) . '</span>';
	}
	return '<a class="butAction" href="' . dol_buildpath('/peppolpeppyrus/peppol_candidates.php', 1) . '">' . $label . '</a>';
}

/**
 * Release the session before a long action (an external service, a loop over many third parties), so the other
 * pages of this user do not wait (a long action must not block the browser). Call it after the checks of rights and token.
 *
 * @return void
 */
function peppol_sessie_los()
{
	ignore_user_abort(true);
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_write_close();
		// Remember that this function closed the session: only then peppol_sessie_terug() opens it again
		$GLOBALS['peppol_sessie_gesloten'] = true;
	}
}

/**
 * Open the session again after peppol_sessie_los(), keeping the messages set in the meantime
 * (they are only in memory and would be lost when the session is opened again).
 *
 * @return void
 */
function peppol_sessie_terug()
{
	if (empty($GLOBALS['peppol_sessie_gesloten']) || session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
		return;
	}
	$GLOBALS['peppol_sessie_gesloten'] = false;
	$meldingen = (array) ($_SESSION['dol_events'] ?? array());
	@session_start();
	foreach ($meldingen as $soort => $lijst) {
		$_SESSION['dol_events'][$soort] = array_merge((array) ($_SESSION['dol_events'][$soort] ?? array()), (array) $lijst);
	}
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

/**
 * Next line reference of the Peppol document
 *
 * @return int Next line reference
 */
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

/**
 * Join messages into one text
 *
 * @param array $arr Messages
 * @return string Messages as one text
 */
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

/**
 * Fetch the list of new supplier documents from the access point
 *
 * @return void
 */
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

/**
 * Write a line in the Peppol log (llx_peppol)
 *
 * @param string $ap_name Access point name
 * @param int $fk_object Object id
 * @param int $object_typeid 1 customer invoice, 2 supplier invoice
 * @param string $status Status
 * @param string $message Message
 * @param string $fulldata Full answer (JSON)
 * @return int 1 if OK, -1 on error
 */
function peppolAddLog($ap_name, $fk_object, $object_typeid, $status, $message, $fulldata)
{
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

/**
 * Link of the button "Book": books the incoming document as supplier invoice (class/peppolbooking.class.php).
 * Empty when the document is already booked, its file is missing or the user lacks a right (import Peppol
 * documents, create supplier invoices).
 *
 * @param	object	$object	Peppolimport
 * @param	User	$user	User
 * @return	string			URL with token, or ''
 */
function peppolBookingUrl($object, $user)
{
	if (!isModEnabled('peppolpeppyrus') || !is_object($object) || empty($object->id) || !empty($object->fk_invoice)) {
		return '';
	}
	if (!$user->hasRight('peppolpeppyrus', 'import') || !$user->hasRight('fournisseur', 'facture', 'creer')) {
		return '';
	}
	if (!method_exists($object, 'fileExist') || !$object->fileExist()) {
		return '';
	}
	return dol_buildpath('/peppolpeppyrus/peppolimport_card.php', 1) . '?id=' . ((int) $object->id) . '&action=book&token=' . newToken();
}

/**
 * Scheduled jobs of the module that can be switched on and off on the setup page: method => [setting, default]
 *
 * @return	array<string,array{0:string,1:int}>
 */
function peppolCronSettings()
{
	return array(
		'runDailyFetch' => array('PEPPOL_JOB_FETCH', 1),
		'runWeeklyCheck' => array('PEPPOL_JOB_CANDIDATES', 1),
		'runAutoSend' => array('PEPPOL_AUTO_SEND', 0),
	);
}

/**
 * Status of a scheduled job of the module in this entity
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$method	Method of the job (runDailyFetch, runWeeklyCheck, runAutoSend)
 * @return	int		1 enabled, 0 disabled, -1 the job does not exist (module not reactivated after an update)
 */
function peppolCronJobStatus($db, $method)
{
	global $conf;
	$sql = "SELECT status FROM " . MAIN_DB_PREFIX . "cronjob WHERE module_name = 'peppolpeppyrus'";
	$sql .= " AND methodename = '" . $db->escape($method) . "' AND entity = " . ((int) $conf->entity);
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		return ((int) $obj->status) > 0 ? 1 : 0;
	}
	return -1;
}

/**
 * Switch a scheduled job of the module on or off in this entity, and remember the choice (also after reactivating)
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$method	Method of the job
 * @param	int		$on		1 on, 0 off
 * @return	bool	true when the setting was saved
 */
function peppolSetCronJob($db, $method, $on)
{
	global $conf;
	$settings = peppolCronSettings();
	if (!isset($settings[$method])) {
		return false;
	}
	$on = $on ? 1 : 0;
	$ok = dolibarr_set_const($db, $settings[$method][0], (string) $on, 'chaine', 0, '', $conf->entity) > 0;
	$sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob SET status = " . ((int) $on);
	$sql .= " WHERE module_name = 'peppolpeppyrus' AND methodename = '" . $db->escape($method) . "' AND entity = " . ((int) $conf->entity);
	return $db->query($sql) && $ok;
}

/**
 * After (re)activating the module: give the scheduled jobs the status chosen on the setup page
 *
 * @param	DoliDB	$db		Database handler
 * @return	void
 */
function peppolApplyCronSettings($db)
{
	global $conf;
	foreach (peppolCronSettings() as $method => $setting) {
		$on = getDolGlobalString($setting[0]) === '' ? $setting[1] : getDolGlobalInt($setting[0]);
		$sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob SET status = " . ($on ? 1 : 0);
		$sql .= " WHERE module_name = 'peppolpeppyrus' AND methodename = '" . $db->escape($method) . "' AND entity = " . ((int) $conf->entity);
		$db->query($sql);
	}
}

/**
 * The most recent versions of a changelog in Markdown ("## <version>" headings), for the About page
 *
 * @param	string	$markdown	Content of CHANGELOG.md
 * @param	int		$count		Number of versions
 * @return	string				Markdown of those versions, without the title of the file
 */
function peppolChangelogExcerpt($markdown, $count)
{
	$parts = preg_split('/^(?=## )/m', str_replace("\r\n", "\n", (string) $markdown));
	$versions = array();
	foreach ($parts as $part) {
		if (strncmp($part, '## ', 3) === 0) {
			$versions[] = rtrim($part) . "\n";
		}
	}
	return implode("\n", array_slice($versions, 0, max(1, (int) $count)));
}

/**
 * Whether this Dolibarr version is older than the recommended one (22.0). Dolibarr 18 up to 21 still works,
 * but those versions get no security updates any more and are not tested with PHP 8.4 or 8.5.
 *
 * @param	string	$version	Dolibarr version (DOL_VERSION)
 * @return	bool
 */
function peppolDolibarrBelowRecommended($version)
{
	return version_compare((string) $version, '22.0', '<');
}

/**
 * Notice on the setup and About pages when Dolibarr is older than the recommended version
 *
 * @return	string	HTML, or '' when the version is recent enough
 */
function peppolDolibarrRecommendedNotice()
{
	global $langs;
	if (!peppolDolibarrBelowRecommended(DOL_VERSION)) {
		return '';
	}
	return '<div class="warning">' . $langs->trans('peppolDolibarrRecommended', dol_escape_htmltag(DOL_VERSION), dol_escape_htmltag(PHP_VERSION)) . '</div>';
}

/**
 * May this customer invoice or credit note go through Peppol (make the XML, send)?
 *
 * Same rule as Dolibarr for sending by email: validated or paid/closed. A credit note that was converted into a
 * discount or refunded is "paid" and must stay sendable. Not drafts and not abandoned documents. From the original
 * module (#12); before, only "validated" counted.
 *
 * @param	object	$invoice	Customer invoice
 * @return	bool
 */
function peppolIsInvoiceStatusEligible($invoice)
{
	if (!is_object($invoice)) {
		return false;
	}
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
	$status = isset($invoice->status) ? (int) $invoice->status : (isset($invoice->statut) ? (int) $invoice->statut : -1);
	return in_array($status, array(Facture::STATUS_VALIDATED, Facture::STATUS_CLOSED), true);
}

/**
 * May this user see this customer invoice? Read right on invoices plus the access rules of Dolibarr (external
 * users, sales representatives limited to their own customers, entity). From the original module, with hasRight().
 *
 * @param	User	$user		User
 * @param	object	$invoice	Customer invoice (fetched)
 * @return	bool
 */
function peppolUserCanReadInvoice($user, $invoice)
{
	if (!is_object($user) || !is_object($invoice) || empty($invoice->id)) {
		return false;
	}
	if (!$user->hasRight('facture', 'lire')) {
		return false;
	}
	require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
	return (bool) checkUserAccessToObject($user, array('facture'), (int) $invoice->id, 'facture', '', 'fk_soc', 'rowid');
}

/**
 * Keep only the allowed characters of an identifier (Peppol ID, message ID) from the access point
 *
 * @param	mixed	$value	Received value
 * @return	string			Letters, digits and . _ : @ -
 */
function peppolSanitizeIdentifier($value)
{
	return (string) preg_replace('/[^A-Za-z0-9._:@-]/', '', is_scalar($value) ? (string) $value : '');
}

/**
 * Does the object belong to one of the entities of the current user (multicompany)? From the original module.
 * CommonObject::fetch() by id does not filter on entity, so a page that loads an object by id must check this.
 *
 * @param	object	$object	Loaded object with entity and element
 * @return	bool
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
 * The report of a sent message at Peppyrus (GET message/{id}/report) in a fixed form (since 2.12.0): the validation
 * rules (FATAL or WARNING) and the transmission rules. Peppyrus returns validationRules (id, type, location, message)
 * and transmissionRules (text); unknown or missing fields become empty.
 *
 * @param	mixed	$report		The report (decoded JSON)
 * @return	array{rules:array<int,array{type:string,id:string,location:string,message:string}>,transport:string,fatal:int,warnings:int}
 */
function peppolReportRules($report)
{
	$out = array('rules' => array(), 'transport' => '', 'fatal' => 0, 'warnings' => 0);
	if (!is_array($report)) {
		return $out;
	}
	foreach ((array) ($report['validationRules'] ?? array()) as $r) {
		if (!is_array($r)) {
			continue;
		}
		$type = strtoupper((string) ($r['type'] ?? ''));
		$type = in_array($type, array('FATAL', 'WARNING'), true) ? $type : '';
		$out['rules'][] = array('type' => $type, 'id' => (string) ($r['id'] ?? ''), 'location' => (string) ($r['location'] ?? ''), 'message' => (string) ($r['message'] ?? ''));
		if ($type === 'FATAL') {
			$out['fatal']++;
		} elseif ($type === 'WARNING') {
			$out['warnings']++;
		}
	}
	$transport = $report['transmissionRules'] ?? '';
	$out['transport'] = is_scalar($transport) ? (string) $transport : (string) json_encode($transport);
	return $out;
}


/**
 * Which failed messages (folder FAILED at Peppyrus) still have to be reported (since 2.12.0): only those of an own
 * invoice that is not yet marked as failed in the Peppol log of that invoice. One report per invoice.
 *
 * @param	array<int,array{id:string,created:string}>	$failed		Failed messages from Peppyrus
 * @param	array<string,int>							$invoices	Message id => invoice id (own invoices)
 * @param	array<int,bool>								$reported		Invoice id => already reported
 * @return	array<int,array{invoice:int,id:string,created:string}>
 */
function peppolFailedToReport($failed, $invoices, $reported)
{
	$out = array();
	foreach ($failed as $f) {
		$id = (string) ($f['id'] ?? '');
		$fid = (int) ($invoices[$id] ?? 0);
		if ($id === '' || $fid <= 0 || !empty($reported[$fid]) || isset($out[$fid])) {
			continue;
		}
		$out[$fid] = array('invoice' => $fid, 'id' => $id, 'created' => (string) ($f['created'] ?? ''));
	}
	return array_values($out);
}

/**
 * Signal for the Peppyrus plan (since 2.12.0): "limit" when the maximum number of sent messages this month is reached
 * (Peppyrus then refuses with HTTP 402), "near" from 90%, otherwise empty. Without a maximum always empty.
 *
 * @param	array<string,mixed>	$license	From GET organization/license
 * @return	string
 */
function peppolLicenseSignal($license)
{
	$max = $license['messagesLimit'] ?? null;
	if ($max === null || (int) $max <= 0) {
		return '';
	}
	$sent = (int) ($license['messagesSent'] ?? 0);
	if ($sent >= (int) $max) {
		return 'limit';
	}
	return $sent >= 0.9 * (int) $max ? 'near' : '';
}

/**
 * Status of a sent invoice at Peppyrus (since 2.12.0): "queued" while the message is still in OUTBOX, "failed" when
 * it is in FAILED, otherwise "sent" (Peppyrus takes a message out of OUTBOX once it has been sent).
 *
 * @param	string				$messageId	Message id of the invoice
 * @param	array<string,bool>	$outbox		Message ids in OUTBOX
 * @param	array<string,bool>	$failed		Message ids in FAILED
 * @return	string	queued, failed or sent
 */
function peppolOutgoingStatus($messageId, $outbox, $failed)
{
	if (isset($failed[$messageId])) {
		return 'failed';
	}
	return isset($outbox[$messageId]) ? 'queued' : 'sent';
}
