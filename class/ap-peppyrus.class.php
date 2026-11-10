<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    peppol/class/peppyrus.class.php
 * \ingroup peppol
 * \brief   Peppyrus access point implementation
 *
 */

namespace custom\peppolpeppyrus;

require_once 'peppolap.class.php';
require_once __DIR__ . '/../lib/peppol.lib.php';

/**
 * Class Peppyrus
 */
class Peppyrus extends PeppolAP
{
	public $description = "Peppyrus is a free and reliable PEPPOL Access Point";
	public $operatorurl = "https://www.peppyrus.be/";
	public $status = 8;
	public $supplierInvoicesList;

	/** @var array{listed:int,new:int,retried:int,failed:int} Counts of the last getSupplierInvoicesList() (no names or ids) */
	public $fetchStats = array('listed' => 0, 'new' => 0, 'retried' => 0, 'failed' => 0);
	public $setupNeeds = ['PEPPOL_AP_SENDER_ID', 'PEPPOL_AP_API_KEY', 'PEPPOL_PROD'];

	/**
	 * Get the appropriate API key based on current mode (PROD or DEV)
	 *
	 * @return  string  API key for current environment
	 */
	protected function getApiKey()
	{
		$isProd = getDolGlobalString('PEPPOL_PROD', '0') == '1';

		if ($isProd) {
			return peppolGetApiKey('PEPPOL_AP_API_KEY');
		} else {
			// Use DEV key if available, fallback to PROD key for backward compatibility
			$devKey = peppolGetApiKey('PEPPOL_AP_DEV_API_KEY');
			return !empty($devKey) ? $devKey : peppolGetApiKey('PEPPOL_AP_API_KEY');
		}
	}

	/**
	 * Validate API configuration
	 *
	 * @return  int <= 0 if configuration invalid, > 0 if valid
	 */
	protected function validateConfiguration()
	{
		$apiKey = $this->getApiKey();
		$isProd = getDolGlobalString('PEPPOL_PROD', '0') == '1';

		if (empty($apiKey)) {
			$keyName = $isProd ? 'PEPPOL_AP_API_KEY' : 'PEPPOL_AP_DEV_API_KEY';
			dol_syslog("Peppyrus::validateConfiguration Missing API key for " . ($isProd ? 'PROD' : 'DEV') . " mode", LOG_ERR);
			$this->errors[] = "Missing API key configuration (" . $keyName . ")";
			return -1;
		}

		return 1;
	}

	/**
	 * Handle API response with proper error codes
	 *
	 * @param   array   $result   Result of peppolApiRequest() (same keys as getURLContent())
	 * @param   string  $context  Method name for logging
	 *
	 * @return  array   ['success' => bool, 'data' => mixed, 'error_code' => int, 'error_msg' => string]
	 */
	protected function handleApiResponse($result, $context = '')
	{
		global $langs;

		// Check CURL errors first (connection, timeout, etc.)
		if (isset($result['curl_error_msg']) && !empty($result['curl_error_msg'])) {
			dol_syslog("Peppyrus::{$context} CURL error: " . $result['curl_error_msg'], LOG_ERR);
			return [
				'success' => false,
				'data' => null,
				'error_code' => -100,
				'error_msg' => $langs->trans('peppolErrorConnection') . ': ' . $result['curl_error_msg']
			];
		}

		$httpCode = $result['http_code'] ?? 0;
		$content = $result['content'] ?? '';

		// Handle by HTTP code according to Peppyrus API documentation
		switch ($httpCode) {
			case 200:
				if (empty($content)) {
					// Some endpoints return empty content on success (like confirm)
					return ['success' => true, 'data' => true, 'error_code' => 0, 'error_msg' => ''];
				}
				$json = json_decode($content);
				if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
					dol_syslog("Peppyrus::{$context} JSON decode error: " . json_last_error_msg(), LOG_ERR);
					return [
						'success' => false,
						'data' => null,
						'error_code' => -101,
						'error_msg' => $langs->trans('peppolErrorJsonDecode') . ': ' . json_last_error_msg()
					];
				}
				return ['success' => true, 'data' => $json, 'error_code' => 0, 'error_msg' => ''];

			case 401:
				dol_syslog("Peppyrus::{$context} Authentication failed (401)", LOG_ERR);
				$isProd = getDolGlobalString('PEPPOL_PROD', '0') == '1';
				$errorMsg = $isProd
					? $langs->trans('peppolErrorAuthenticationProd')
					: $langs->trans('peppolErrorAuthenticationTest');
				return [
					'success' => false,
					'data' => null,
					'error_code' => -401,
					'error_msg' => $errorMsg
				];

			case 404:
				dol_syslog("Peppyrus::{$context} Resource not found (404)", LOG_WARNING);
				return [
					'success' => false,
					'data' => null,
					'error_code' => -404,
					'error_msg' => $langs->trans('peppolErrorNotFound')
				];

			case 422:
				$errorMsg = $this->parseValidationError($content);
				dol_syslog("Peppyrus::{$context} Validation error (422): {$errorMsg}", LOG_ERR);
				return [
					'success' => false,
					'data' => null,
					'error_code' => -422,
					'error_msg' => $langs->trans('peppolErrorValidation') . ': ' . $errorMsg
				];

			case 0:
				dol_syslog("Peppyrus::{$context} No response (HTTP 0) - possible timeout or network issue", LOG_ERR);
				return [
					'success' => false,
					'data' => null,
					'error_code' => -100,
					'error_msg' => $langs->trans('peppolErrorConnection')
				];

			case 402:
				// Since 2.12.0: the maximum number of messages of the plan is reached
				dol_syslog("Peppyrus::{$context} limit of the plan reached (HTTP 402)", LOG_WARNING);
				return [
					'success' => false,
					'data' => null,
					'error_code' => -402,
					'error_msg' => $langs->trans('peppolErrorLimitReached')
				];

			default:
				dol_syslog("Peppyrus::{$context} Unexpected HTTP code: {$httpCode}", LOG_ERR);
				return [
					'success' => false,
					'data' => null,
					'error_code' => -$httpCode,
					'error_msg' => $langs->trans('peppolErrorUnexpected') . " (HTTP {$httpCode})"
				];
		}
	}

	/**
	 * Parse validation error from 422 response
	 *
	 * @param   string  $content  Response content
	 *
	 * @return  string  Formatted error message
	 */
	protected function parseValidationError($content)
	{
		$errorMsg = 'Unknown validation error';

		if (empty($content)) {
			return $errorMsg;
		}

		$json = json_decode($content);

		// The texts come from the access point: escaped, only the line breaks added here are HTML
		if ($json && isset($json->message)) {
			$errorMsg = dol_escape_htmltag((string) $json->message);
			if (isset($json->errors) && is_array($json->errors)) {
				foreach ($json->errors as $e) {
					$errorMsg .= '<br>- ' . dol_escape_htmltag(is_string($e) ? $e : (string) json_encode($e));
				}
			}
		} elseif (!empty($content)) {
			// Fallback: the raw content without HTML tags, escaped
			$errorMsg = dol_escape_htmltag(strip_tags($content));
		}

		return $errorMsg;
	}

	/**
	 * Get api url (test / prod endpoint code factoring)
	 *
	 * @return  string  uri like https://....
	 */
	protected function getApiUrl()
	{
		$prod = getDolGlobalString('PEPPOL_PROD', '0');
		$url = "https://api.test.peppyrus.be/v1/";

		if ($prod == '1') {
			$url = "https://api.peppyrus.be/v1/";
		}

		dol_syslog("Peppyrus::getApiUrl PEPPOL_PROD=" . $prod . " -> URL=" . $url);

		return $url;
	}

	/**
	 * Get document type value based on object type
	 *
	 * @param   object  $object  invoice or credit note object
	 *
	 * @return  string  Peppol document type value
	 */
	protected function getDocumentTypeValue($object)
	{
		// Check if it's a credit note (type 2 in Dolibarr)
		if (isset($object->type) && $object->type == 2) {
			return 'busdox-docid-qns::urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2::CreditNote##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';
		}

		// Default: Invoice
		return 'busdox-docid-qns::urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';
	}

	/**
	 * Get process type value
	 *
	 * @return  string  Peppol process type value
	 */
	protected function getProcessTypeValue()
	{
		return 'cenbii-procid-ubl::urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';
	}

	/**
	 * Check your access point
	 *
	 * @param   object  $object  object description
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	public function checkAccessPoint($object)
	{
		global $langs;

		$url = "";
		$check_invoice = false;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		if (is_object($object)) {
			$object->fetch_optionals();
			if (array_key_exists('options_peppol_id', $object->array_options)) {
				if ($object->array_options['options_peppol_id'] != "") {
					// Invoice has been sent - check message status
					$url = $this->getApiUrl() . "message/" . urlencode($object->array_options['options_peppol_id']);
					$check_invoice = true;
				}
			}
		}

		// If invoice not yet sent, check the recipient in Peppol directory instead
		if ($url == "" && is_object($object)) {
			// Try to get thirdparty from the object (invoice)
			if (empty($object->thirdparty) && is_callable(array($object, 'fetch_thirdparty'))) {
				$object->fetch_thirdparty();
			}

			if (!empty($object->thirdparty) && is_object($object->thirdparty)) {
				// Check if recipient exists in Peppol directory
				dol_syslog("Peppyrus::checkAccessPoint Invoice not sent yet, checking recipient in Peppol directory");
				return $this->checkThirdparty($object->thirdparty);
			}
		}

		// Fallback: just verify API connectivity
		if ($url == "") {
			$url = $this->getApiUrl() . "organization/info";
		}

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'GET', '', $headers);

		dol_syslog("Peppyrus::checkAccessPoint HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'checkAccessPoint');

		if ($response['success']) {
			$json = $response['data'];
			if ($check_invoice) {
				$message = $langs->trans('peppolAccessPointCheckInvoiceSuccess');
				if (isset($json->confirmed)) {
					$message .= ' - ' . $langs->trans('peppolAccessPointCheckInvoiceSuccessConfirmed');
				}
				if (isset($json->folder)) {
					$message .= ' - folder: ' . dol_escape_htmltag((string) $json->folder);
				}
			} else {
				$message = $langs->trans('peppolAccessPointCheckSuccess');
				if (isset($json->name)) {
					$message .= ' - Organization: ' . dol_escape_htmltag((string) $json->name);
				}
			}
			setEventMessages($message, null, 'mesgs');
			return 1;
		} else {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}
	}

	/**
	 * Send document (invoice or credit note) to peppol access point
	 *
	 * @param   string  $filename  path to the XML file
	 * @param   object  $object    object (Facture)
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	public function sendToAccessPoint($filename, $object)
	{
		global $langs;

		$ret = 0;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();
		$mySenderPeppolId = getDolGlobalString('PEPPOL_AP_SENDER_ID');

		if (empty($object->thirdparty)) {
			if (is_callable(array($object, 'fetch_thirdparty'))) {
				$result = $object->fetch_thirdparty();
				if ($result < 0) {
					dol_syslog("Peppyrus::sendToAccessPoint can't fetch thirdparty", LOG_ERR);
				}
			}
		}

		$customerPeppolId = $object->thirdparty->array_options['options_peppol_id'] ?? '';

		if (empty($customerPeppolId)) {
			setEventMessage($langs->trans('PeppolCheckErrorCustomerPeppolID'), 'errors');
			dol_syslog("Peppyrus::sendToAccessPoint customerPeppolId is empty", LOG_ERR);
			return -1;
		}

		if (empty($mySenderPeppolId)) {
			setEventMessage($langs->trans('PeppolCheckErrorSenderPeppolID'), 'errors');
			dol_syslog("Peppyrus::sendToAccessPoint senderPeppolId is empty", LOG_ERR);
			return -2;
		}

		// Verify thirdparty exists in PEPPOL network (non-blocking check, just a warning)
		$checkResult = $this->checkThirdparty($object->thirdparty);
		if ($checkResult < 0) {
			// In TEST mode, participant may not be in directory - continue anyway
			if (!getDolGlobalString('PEPPOL_PROD')) {
				dol_syslog("Peppyrus::sendToAccessPoint checkThirdparty failed but we are in TEST mode, continuing...", LOG_WARNING);
			}
			// Don't block the send - the check is informational only
		}

		// Read the XML file content and encode it in base64
		$xmlContent = file_get_contents($filename);
		if ($xmlContent === false) {
			setEventMessage($langs->trans('ErrorReadingFile'), 'errors');
			dol_syslog("Peppyrus::sendToAccessPoint cannot read file: " . $filename, LOG_ERR);
			return -3;
		}

		$fileContentBase64 = base64_encode($xmlContent);

		$url = $this->getApiUrl() . "message";

		$documentType = $this->getDocumentTypeValue($object);
		$processType = $this->getProcessTypeValue();

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;
		$headers[] = 'Content-Type: application/json';

		// Prepare the message body according to Peppyrus API
		$data = [
			"sender" => $mySenderPeppolId,
			"recipient" => $customerPeppolId,
			"processType" => $processType,
			"documentType" => $documentType,
			"fileContent" => $fileContentBase64
		];

		$body = json_encode($data);

		$result = peppolApiRequest($url, 'POST', $body, $headers);

		dol_syslog("Peppyrus::sendToAccessPoint HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'sendToAccessPoint');

		// Every sending in the Peppol log, shown on the Peppol tab of the invoice (from the original module, #18)
		peppolAddLog(
			'peppyrus',
			(int) $object->id,
			1,
			(string) ($result['http_code'] ?? 0),
			$response['success'] ? $langs->transnoentitiesnoconv('peppolSendSuccess') : strip_tags((string) $response['error_msg']),
			(string) json_encode(array('http_code' => $result['http_code'] ?? 0, 'response' => $response['success'] ? $response['data'] : ($result['content'] ?? '')))
		);

		if ($response['success']) {
			$json = $response['data'];

			if (isset($json->id)) {
				$message = $langs->trans('peppolSendSuccess') . ' - Message ID: ' . dol_escape_htmltag(peppolSanitizeIdentifier($json->id));
				setEventMessages($message, null, 'mesgs');

				// Store the message ID in the object for tracking
				if (!isset($object->array_options) || empty($object->array_options)) {
					$object->fetch_optionals();
				}

				// Save Peppyrus message ID if extrafield exists
				if (array_key_exists('options_peppol_id', $object->array_options)) {
					dol_syslog("Peppol update extrafields : store the Peppyrus message id on the invoice");
					$object->array_options['options_peppol_id'] = peppolSanitizeIdentifier($json->id);
					$object->updateExtraField('peppol_id');
				} else {
					dol_syslog("Peppol update extrafields : can't get object->array_options['options_peppol_id'] !");
				}

				return 1;
			} else {
				setEventMessage($langs->trans('peppolSendErrorNoMessageId'), 'errors');
				return -4;
			}
		} else {
			// Error handled by handleApiResponse
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}
	}

	/**
	 * Check thirdparty identity thanks to peppol id
	 *
	 * @param   Societe  $thirdparty  dolibarr thirdparty
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	public function checkThirdparty(\Societe $thirdparty)
	{
		global $langs;

		$ret = 0;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		if (!isset($thirdparty->array_options) || empty($thirdparty->array_options)) {
			$thirdparty->fetch_optionals();
		}

		$peppolId = $thirdparty->array_options['options_peppol_id'] ?? '';

		if (empty($peppolId)) {
			setEventMessage($langs->trans('peppolCheckErrorNoPeppolID'), 'errors');
			dol_syslog("Peppyrus::checkThirdparty peppolId is empty", LOG_ERR);
			return -1;
		}

		// Use the lookup endpoint to verify participant
		$url = $this->getApiUrl() . "peppol/lookup?participantId=" . urlencode($peppolId);

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'GET', '', $headers);

		dol_syslog("Peppyrus::checkThirdparty HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'checkThirdparty');

		if ($response['success']) {
			$json = $response['data'];

			if (isset($json->participantId)) {
				$message = $langs->trans('peppolThirdpartyCheckSuccess') . ' - ' . $json->participantId;

				// Display available services
				if (isset($json->services) && is_array($json->services)) {
					$message .= ' (' . count($json->services) . ' services available)';
				}

				setEventMessages($message, null, 'mesgs');
				return 1;
			} else {
				setEventMessage($langs->trans('peppolThirdpartyCheckError'), 'errors');
				return -2;
			}
		} elseif ($response['error_code'] == -404) {
			// In TEST mode, show as warning instead of error
			if (!getDolGlobalString('PEPPOL_PROD')) {
				setEventMessage($langs->trans('peppolThirdpartyNotFoundTestMode'), 'warnings');
			} else {
				setEventMessage($langs->trans('peppolThirdpartyNotFound'), 'errors');
			}
			return -3;
		} else {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}
	}

	/**
	 * Get all invoices waiting into your peppol AP
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	public function getSupplierInvoicesList()
	{
		global $langs, $conf, $user, $db;

		$ret = 0;
		$this->fetchStats = array('listed' => 0, 'new' => 0, 'retried' => 0, 'failed' => 0);

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		// Get messages from INBOX folder, filter for unconfirmed messages
		$url = $this->getApiUrl() . "message/list?folder=INBOX&confirmed=false&perPage=100";

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'GET', '', $headers);

		dol_syslog("Peppyrus::getSupplierInvoicesList HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'getSupplierInvoicesList');

		if (!$response['success']) {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}

		$json = $response['data'];

		// The access point answered: remember when (shown on the list of incoming documents)
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		dolibarr_set_const($db, 'PEPPOL_INBOX_LAST_FETCH', (string) dol_now(), 'chaine', 0, '', $conf->entity);

		if (!isset($json->items) || !is_array($json->items)) {
			setEventMessage($langs->trans('peppolNoInvoicesFound'), 'warnings');
			return 0;
		}

		$messageCount = count($json->items);
		$this->fetchStats['listed'] = $messageCount;

		$message = $langs->trans('peppolInvoicesListSuccess') . ' - ' . $messageCount . ' message(s) found';

		if (isset($json->meta)) {
			$message .= ' (Page ' . ((int) $json->meta->currentPage) . '/' . ((int) $json->meta->pages) . ')';
		}

		setEventMessages($message, null, 'mesgs');

		// Process each message
		foreach ($json->items as $entry) {
			if (!is_object($entry) || empty($entry->id) || !is_scalar($entry->id)) {
				$this->fetchStats['failed']++;
				continue;
			}
			// Since 2.13.0: an answer of a customer to one of our invoices, not an incoming invoice
			if (stripos((string) ($entry->documentType ?? ''), 'ApplicationResponse') !== false) {
				$this->fetchStats['responses'] = ($this->fetchStats['responses'] ?? 0) + 1;
				if ($this->processInvoiceResponse($entry) <= 0) {
					$this->fetchStats['failed']++;
				}
				continue;
			}
			$pi = new Peppolimport($db);
			$res = $pi->fetch(0, null, (string) $entry->id);
			if ($res > 0) {
				// Known, but still unconfirmed at the access point: the earlier download or confirmation failed, try again
				dol_syslog("Peppyrus::getSupplierInvoicesList document known but not confirmed, retry download");
				$resXml = $this->getSupplierInvoice($pi);
				if ($resXml > 0 && $this->confirmSupplierInvoice($pi) > 0) {
					$this->fetchStats['retried']++;
				} else {
					$this->fetchStats['failed']++;
				}
				continue;
			}

			$pi->setValues([
				"peppolid" => peppolSanitizeIdentifier($entry->id),
				"senderID" => peppolSanitizeIdentifier($entry->sender ?? ''),
				"receiverID" => peppolSanitizeIdentifier($entry->recipient ?? ''),
			]);
			$res = $pi->create($user);
			if ($res > 0) {
				$res = $pi->validate($user);
				dol_syslog("Peppolimport Validate return " . ((int) $res));
				$resXml = $this->getSupplierInvoice($pi);
				$resPdf = $this->getSupplierInvoicePdf($pi);

				// Confirm to AP in case of no errors
				$resConfirm = 0;
				if ($resXml > 0 && $resPdf > 0) {
					$resConfirm = $this->confirmSupplierInvoice($pi);
					if ($resConfirm <= 0) {
						dol_syslog("Peppyrus::getSupplierInvoicesList Error confirming invoice: " . $resConfirm, LOG_WARNING);
					}
				}
				if ($resConfirm > 0) {
					$this->fetchStats['new']++;
				} else {
					$this->fetchStats['failed']++;
				}
			} else {
				dol_syslog("Peppolimport Error : " . ((int) $res));
				$this->fetchStats['failed']++;
			}
		}

		return $messageCount;
	}

	/**
	 * Process an Invoice Response of a customer (since 2.13.0): fetch it, read it, find the own invoice by number,
	 * and store it in the Peppol log of that invoice (status IR:<code>). Only when the sender is the Peppol ID of the
	 * customer of that invoice. On a rejection or a query also an agenda event. Then confirm it.
	 *
	 * @param	object	$entry	Message of message/list
	 * @return	int		1 stored with an invoice, 2 stored without invoice, <= 0 error
	 */
	public function processInvoiceResponse($entry)
	{
		global $langs;

		$id = peppolSanitizeIdentifier($entry->id ?? '');
		if ($id === '') {
			return -1;
		}
		$headers = array('X-Api-Key: ' . $this->getApiKey());

		// Already processed (confirming failed last time)?
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "peppol WHERE entity IN (" . getEntity('invoice') . ") AND status LIKE 'IR:%'";
		$sql .= " AND fulldata LIKE '%\"messageId\":\"" . $this->db->escape($this->db->escapeforlike($id)) . "\"%'"; // @phan-suppress-current-line SqlInjectionUnquotedEscape -- inside the quotes of LIKE, the id is already sanitized
		$resql = $this->db->query($sql);
		$known = ($resql && $this->db->num_rows($resql) > 0);

		if (!$known) {
			$result = peppolApiRequest($this->getApiUrl() . 'message/' . rawurlencode($id), 'GET', '', $headers);
			$response = $this->handleApiResponse($result, 'processInvoiceResponse');
			if (!$response['success'] || !isset($response['data']->fileContent)) {
				return -2;
			}
			$ir = peppolParseInvoiceResponse((string) base64_decode((string) $response['data']->fileContent));
			if ($ir === null) {
				dol_syslog('Peppyrus::processInvoiceResponse message ' . $id . ' is not a readable Invoice Response', LOG_WARNING);
				return -3;
			}
			$sender = $ir['sender'] !== '' ? $ir['sender'] : peppolSanitizeIdentifier($entry->sender ?? '');

			// The own invoice with this number, of a customer with this Peppol ID
			$fid = 0;
			$socid = 0;
			$ref = '';
			if ($ir['invoiceId'] !== '') {
				$sql = "SELECT f.rowid, f.ref, f.fk_soc, se.peppol_id FROM " . MAIN_DB_PREFIX . "facture AS f";
				$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe_extrafields AS se ON se.fk_object = f.fk_soc";
				$sql .= " WHERE f.entity IN (" . getEntity('invoice') . ") AND f.ref = '" . $this->db->escape($ir['invoiceId']) . "'";
				$resql = $this->db->query($sql);
				while ($resql && ($obj = $this->db->fetch_object($resql))) {
					if (peppolSameParticipant((string) $obj->peppol_id, $sender)) {
						$fid = (int) $obj->rowid;
						$socid = (int) $obj->fk_soc;
						$ref = (string) $obj->ref;
					}
				}
			}

			$label = $langs->transnoentitiesnoconv(peppolResponseCodes()[$ir['code']]);
			$reason = isset(peppolRejectReasons()[$ir['reason']]) ? $langs->transnoentitiesnoconv(peppolRejectReasons()[$ir['reason']]) : '';
			$detail = trim($reason . ($reason !== '' && $ir['reasonText'] !== '' ? ': ' : '') . $ir['reasonText']);
			$text = $langs->transnoentitiesnoconv('peppolResponseReceived', $label) . ($detail !== '' ? ' - ' . $detail : '');
			if ($fid <= 0) {
				$text = $langs->transnoentitiesnoconv('peppolResponseUnmatched', $ir['invoiceId'], $label);
			}
			peppolAddLog('peppyrus', $fid, 1, 'IR:' . $ir['code'], $text, (string) json_encode(array(
				'messageId' => $id,
				'code' => $ir['code'],
				'reason' => $ir['reason'],
				'reasonText' => dol_trunc($ir['reasonText'], 500, 'right', 'UTF-8', 1),
				'invoiceId' => $ir['invoiceId'],
				'invoiceDate' => $ir['invoiceDate'],
			)));
			if ($fid > 0 && in_array($ir['code'], array('RE', 'UQ'), true)) {
				$this->agendaResponse($fid, $socid, $ref, $ir['code'], $text);
			}
		}

		// Confirm at Peppyrus, so it does not come again
		$confirm = peppolApiRequest($this->getApiUrl() . 'message/' . rawurlencode($id) . '/confirm', 'PATCH', '', $headers);
		$this->handleApiResponse($confirm, 'processInvoiceResponse confirm');
		return 1;
	}

	/**
	 * Agenda event on a rejection or query of the customer, when the agenda is enabled (since 2.13.0)
	 *
	 * @param	int		$fid	Invoice
	 * @param	int		$socid	Customer
	 * @param	string	$ref	Invoice number
	 * @param	string	$code	RE or UQ
	 * @param	string	$text	Description
	 * @return	void
	 */
	private function agendaResponse($fid, $socid, $ref, $code, $text)
	{
		global $user, $langs;
		if (!isModEnabled('agenda')) {
			return;
		}
		require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
		$ac = new \ActionComm($this->db);
		$ac->type_code = 'AC_OTH_AUTO';
		$ac->code = 'AC_PEPPOL_IR_' . $code;
		$ac->label = dol_trunc($langs->transnoentitiesnoconv($code === 'RE' ? 'peppolResponseRejectedShort' : 'peppolResponseQueryShort') . ' (' . $ref . ')', 120, 'right', 'UTF-8', 1);
		$ac->note_private = $text;
		$ac->datep = dol_now();
		$ac->userownerid = (int) $user->id;
		$ac->percentage = -1;
		$ac->socid = (int) $socid;
		$ac->fk_element = (int) $fid;
		$ac->elementtype = 'invoice';
		if ($ac->create($user) < 0) {
			dol_syslog('Peppyrus::agendaResponse ' . $ac->error, LOG_WARNING);
		}
	}

	/**
	 * Get invoice $pi waiting into your peppol AP
	 *
	 * @param   Peppolimport  $pi  peppolimport object to import
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	public function getSupplierInvoice(Peppolimport $pi)
	{
		global $langs;

		$ret = 0;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		if (empty($pi->peppolid)) {
			setEventMessage($langs->trans('peppolGetInvoiceErrorNoPeppolID'), 'errors');
			dol_syslog("Peppyrus::getSupplierInvoice peppolid is empty", LOG_ERR);
			return -1;
		}

		// Get message details including content
		$url = $this->getApiUrl() . "message/" . rawurlencode((string) $pi->peppolid);

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'GET', '', $headers);

		dol_syslog("Peppyrus::getSupplierInvoice CURL result code: " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'getSupplierInvoice');

		if (!$response['success']) {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}

		$json = $response['data'];

		if (!isset($json->fileContent)) {
			setEventMessage($langs->trans('peppolGetInvoiceErrorNoContent'), 'errors');
			return -4;
		}

		// Decode base64 content
		$xmlContent = base64_decode($json->fileContent);

		// Save XML content
		$retsave = $pi->saveXML($xmlContent);

		if ($retsave <= 0) {
			dol_syslog("Peppyrus::getSupplierInvoice error saving XML file : " . $retsave, LOG_ERR);
			setEventMessage($langs->trans('peppolGetInvoiceErrorSave'), 'errors');
			return -3;
		}

		$message = $langs->trans('peppolGetInvoiceSuccess');

		// Add message details
		if (isset($json->sender)) {
			$message .= ' - Sender: ' . dol_escape_htmltag((string) $json->sender);
		}
		if (isset($json->created)) {
			$message .= ' - Date: ' . dol_escape_htmltag((string) $json->created);
		}

		setEventMessages($message, null, 'mesgs');
		return 1;
	}

	/**
	 * Separate PDF when fetching: not needed. Since 2.13.0 Peppyrus makes one on booking when the supplier put no PDF
	 * in the message (renderMessage()); fetching just goes on.
	 *
	 * @param   Peppolimport  $pi  Incoming document
	 * @return  int  1
	 */
	public function getSupplierInvoicePdf(Peppolimport $pi)
	{
		return 1;
	}

	/**
	 * A received invoice or credit note as PDF, made by Peppyrus (GET message/{id}/render), since 2.13.0
	 *
	 * @param   string  $messageId  Message id at Peppyrus
	 * @return  string  The PDF, or '' when it fails
	 */
	public function renderMessage($messageId)
	{
		if ($this->validateConfiguration() < 0 || (string) $messageId === '') {
			return '';
		}
		$url = $this->getApiUrl() . "message/" . rawurlencode((string) $messageId) . "/render?format=PDF";
		$result = peppolApiRequest($url, 'GET', '', array('X-Api-Key: ' . $this->getApiKey()));
		$response = $this->handleApiResponse($result, 'renderMessage');
		if (!$response['success'] || !is_object($response['data']) || !isset($response['data']->content)) {
			return '';
		}
		$pdf = base64_decode((string) $response['data']->content, true);
		return (is_string($pdf) && strncmp($pdf, '%PDF', 4) === 0) ? $pdf : '';
	}

	/**
	 * Confirm reception of supplier invoice
	 *
	 * @param   Peppolimport  $pi  peppolimport object to import
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	public function confirmSupplierInvoice(Peppolimport $pi)
	{
		global $langs;

		$ret = 0;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		if (empty($pi->peppolid)) {
			setEventMessage($langs->trans('peppolConfirmErrorNoPeppolID'), 'errors');
			dol_syslog("Peppyrus::confirmSupplierInvoice peppolid is empty", LOG_ERR);
			return -1;
		}

		// Confirm message reception
		$url = $this->getApiUrl() . "message/" . rawurlencode((string) $pi->peppolid) . "/confirm";

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'PATCH', '', $headers);

		dol_syslog("Peppyrus::confirmSupplierInvoice HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'confirmSupplierInvoice');

		$ret = 0;
		$message = '';

		if ($response['success']) {
			$message = $langs->trans('peppolConfirmSuccess');
			setEventMessage($message, 'mesgs');
			$ret = 1;
		} elseif ($response['error_code'] == -404) {
			// Already confirmed or not found - treat as warning not error
			$message = $langs->trans('peppolConfirmErrorNotFound');
			setEventMessage($message, 'warnings');
			$ret = -2;
		} else {
			$message = $response['error_msg'];
			setEventMessage($message, 'errors');
			$ret = $response['error_code'];
		}

		// Log the confirmation action
		if (function_exists('peppolAddLog')) {
			peppolAddLog('peppyrus', $pi->fk_invoice, 2, $result['http_code'] ?? 0, $message, json_encode($result));
		}

		return $ret;
	}

	/**
	 * Reject an incoming invoice (since 2.13.0): an Invoice Response with status RE to the supplier.
	 *
	 * @param   Peppolimport  $pi      Inkomend document
	 * @param   string        $reason  Reason: a code of peppolRejectReasons(), or an own text
	 *
	 * @return  int  1 sent, otherwise the result of sendInvoiceResponse()
	 */
	public function rejectSupplierInvoice(Peppolimport $pi, $reason)
	{
		$code = isset(peppolRejectReasons()[(string) $reason]) ? (string) $reason : 'OTH';
		$text = $code === 'OTH' ? (string) $reason : '';
		return $this->sendInvoiceResponse($pi, array('code' => 'RE', 'reason' => $code, 'reasonText' => $text));
	}

	/**
	 * An Invoice Response (Peppol BIS 63) to the supplier of an incoming invoice, since 2.13.0. First the Peppol
	 * directory tells whether the supplier can receive such a message; if not, nothing is sent.
	 *
	 * @param   Peppolimport  $pi    Incoming document (senderID is the supplier)
	 * @param   array{code:string,reason?:string,reasonText?:string,invoiceId?:string,invoiceDate?:string,typeCode?:string,receiverName?:string}  $resp  Status, reason and the invoice
	 *
	 * @return  int  1 sent, -5 supplier cannot receive an Invoice Response, -6 directory not reachable,
	 *               -7 data incomplete, other value <= 0 error of the API
	 */
	public function sendInvoiceResponse(Peppolimport $pi, array $resp)
	{
		global $langs;

		$this->error = '';
		if ($this->validateConfiguration() < 0) {
			$this->error = implode(', ', $this->errors);
			return -10;
		}
		$sender = getDolGlobalString('PEPPOL_AP_SENDER_ID');
		$receiver = peppolSanitizeIdentifier((string) $pi->senderID);
		if ($sender === '' || $receiver === '') {
			$this->error = $langs->transnoentitiesnoconv('peppolResponseNoParticipant');
			return -7;
		}

		$headers = array('X-Api-Key: ' . $this->getApiKey());

		// Can the supplier receive an Invoice Response?
		$lookup = peppolApiRequest($this->getApiUrl() . 'peppol/lookup?participantId=' . urlencode($receiver), 'GET', '', $headers);
		$lookupResponse = $this->handleApiResponse($lookup, 'sendInvoiceResponse lookup');
		if (!$lookupResponse['success'] && (int) $lookupResponse['error_code'] !== -404) {
			$this->error = $langs->transnoentitiesnoconv('peppolResponseLookupFailed');
			return -6;
		}
		if (!$lookupResponse['success'] || !peppolSupportsInvoiceResponse($lookupResponse['data'])) {
			$this->error = $langs->transnoentitiesnoconv('peppolResponseNotSupported');
			return -5;
		}

		$vandaag = dol_print_date(dol_now(), '%Y-%m-%d', 'gmt');
		$xml = peppolInvoiceResponseXml(array(
			'id' => 'IR-' . preg_replace('/[^A-Za-z0-9._-]/', '', (string) $pi->ref) . '-' . dol_print_date(dol_now(), '%Y%m%d%H%M%S', 'gmt'),
			'date' => $vandaag,
			'sender' => $sender,
			'senderName' => getDolGlobalString('MAIN_INFO_SOCIETE_NOM'),
			'receiver' => $receiver,
			'receiverName' => (string) ($resp['receiverName'] ?? ''),
			'code' => (string) $resp['code'],
			'reason' => (string) ($resp['reason'] ?? ''),
			'reasonText' => (string) ($resp['reasonText'] ?? ''),
			'invoiceId' => (string) ($resp['invoiceId'] ?? ''),
			'invoiceDate' => (string) ($resp['invoiceDate'] ?? ''),
			'typeCode' => (string) ($resp['typeCode'] ?? '380'),
		));
		if ($xml === '') {
			$this->error = $langs->transnoentitiesnoconv('peppolResponseIncomplete');
			return -7;
		}

		$headers[] = 'Content-Type: application/json';
		$body = json_encode(array(
			'sender' => $sender,
			'recipient' => $receiver,
			'processType' => PEPPOL_IR_PROCESS,
			'documentType' => PEPPOL_IR_DOCTYPE,
			'fileContent' => base64_encode($xml),
		));
		$result = peppolApiRequest($this->getApiUrl() . 'message', 'POST', (string) $body, $headers);
		$response = $this->handleApiResponse($result, 'sendInvoiceResponse');

		// In the Peppol log with the incoming document (type 3)
		peppolAddLog(
			'peppyrus',
			(int) $pi->id,
			3,
			'IR:' . (string) $resp['code'],
			$response['success'] ? $langs->transnoentitiesnoconv('peppolResponseSent', $langs->transnoentitiesnoconv(peppolResponseCodes()[(string) $resp['code']])) : strip_tags((string) $response['error_msg']),
			(string) json_encode(array('http_code' => $result['http_code'] ?? 0, 'messageId' => $response['success'] && isset($response['data']->id) ? peppolSanitizeIdentifier($response['data']->id) : ''))
		);

		if (!$response['success']) {
			$this->error = strip_tags((string) $response['error_msg']);
			return (int) $response['error_code'] < 0 ? (int) $response['error_code'] : -1;
		}
		return 1;
	}

	/**
	 * Get delivery report for a sent message
	 *
	 * @param   string  $messageId  UUID of the message
	 *
	 * @return  array|int  Report data array or <= 0 on error
	 */
	public function getMessageReport($messageId)
	{
		global $langs;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		if (empty($messageId)) {
			setEventMessage($langs->trans('peppolReportErrorNoMessageId'), 'errors');
			return -1;
		}

		$url = $this->getApiUrl() . "message/" . urlencode($messageId) . "/report";

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'GET', '', $headers);

		dol_syslog("Peppyrus::getMessageReport HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'getMessageReport');

		if ($response['success']) {
			// Since 2.12.0 no message on success: the report is fetched every time the tab is opened
			return json_decode($result['content'], true);
		} else {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}
	}

	/**
	 * Sent messages Peppyrus could not deliver (folder FAILED), since 2.12.0
	 *
	 * @param   int  $maxPages  Maximum number of pages of 100
	 * @return  array<int,array{id:string,created:string}>|int  List, or < 0 on error
	 */
	public function getFailedMessages($maxPages = 10)
	{
		return $this->getOutgoingFolder('FAILED', $maxPages);
	}

	/**
	 * Outgoing messages in a folder at Peppyrus (since 2.12.0): OUTBOX (waiting), SENT (sent) or FAILED (not
	 * delivered). Only the id and the date; at most $maxPages pages of 100.
	 *
	 * @param   string  $folder    OUTBOX, SENT or FAILED
	 * @param   int     $maxPages  Maximum number of pages
	 * @return  array<int,array{id:string,created:string}>|int  List, or < 0 on error
	 */
	public function getOutgoingFolder($folder, $maxPages = 10)
	{
		if (!in_array($folder, array('OUTBOX', 'SENT', 'FAILED'), true)) {
			return -1;
		}
		if ($this->validateConfiguration() < 0) {
			return -10;
		}
		$headers = array('X-Api-Key: ' . $this->getApiKey());
		$out = array();
		$maxPages = max(1, (int) $maxPages);
		for ($page = 1; $page <= $maxPages; $page++) {
			$url = $this->getApiUrl() . "message/list?folder=" . $folder . "&perPage=100&page=" . ((int) $page);
			$result = peppolApiRequest($url, 'GET', '', $headers);
			$response = $this->handleApiResponse($result, 'getOutgoingFolder');
			if (!$response['success']) {
				// Peppyrus answers 404 for an empty folder
				return $response['error_code'] === -404 ? $out : $response['error_code'];
			}
			$json = $response['data'];
			$items = (is_object($json) && isset($json->items) && is_array($json->items)) ? $json->items : array();
			foreach ($items as $item) {
				if (is_object($item) && isset($item->id) && (!isset($item->direction) || $item->direction === 'OUT')) {
					$out[] = array('id' => peppolSanitizeIdentifier((string) $item->id), 'created' => isset($item->created) ? (string) $item->created : '');
				}
			}
			$pages = (is_object($json) && isset($json->meta->pages)) ? (int) $json->meta->pages : 1;
			if ($page >= $pages || empty($items)) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Peppyrus plan (GET organization/license), since 2.12.0: status, sent this month and the maximum
	 *
	 * @return  array<string,mixed>|int  Data, or < 0 on error
	 */
	public function getOrganizationLicense()
	{
		if ($this->validateConfiguration() < 0) {
			return -10;
		}
		$result = peppolApiRequest($this->getApiUrl() . "organization/license", 'GET', '', array('X-Api-Key: ' . $this->getApiKey()));
		$response = $this->handleApiResponse($result, 'getOrganizationLicense');
		if (!$response['success']) {
			return $response['error_code'];
		}
		$data = json_decode((string) ($result['content'] ?? ''), true);
		return is_array($data) ? $data : -101;
	}

	/**
	 * Find PEPPOL participant ID by VAT number
	 *
	 * @param   string  $vatNumber    VAT number without country prefix
	 * @param   string  $countryCode  ISO country code (BE, NL, FR, etc.)
	 *
	 * @return  array|int  Participant data array or <= 0 on error
	 */
	public function findPeppolIdByVat($vatNumber, $countryCode = '')
	{
		global $langs;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		if (empty($vatNumber)) {
			setEventMessage($langs->trans('peppolBestMatchErrorNoVat'), 'errors');
			return -1;
		}

		$params = [];
		$params[] = 'vatNumber=' . urlencode($vatNumber);
		if (!empty($countryCode)) {
			$params[] = 'countryCode=' . urlencode($countryCode);
		}

		$url = $this->getApiUrl() . "peppol/bestMatch?" . implode('&', $params);

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'GET', '', $headers);

		dol_syslog("Peppyrus::findPeppolIdByVat HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'findPeppolIdByVat');

		if ($response['success']) {
			$json = json_decode($result['content'], true);
			if (isset($json['participantId'])) {
				$message = $langs->trans('peppolBestMatchSuccess') . ' - ' . $json['participantId'];
				setEventMessage($message, 'mesgs');
			}
			return $json;
		} elseif ($response['error_code'] == -404) {
			setEventMessage($langs->trans('peppolBestMatchNotFound'), 'warnings');
			return -2;
		} else {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}
	}

	/**
	 * Search PEPPOL directory
	 *
	 * @param   array  $params  Search parameters (query, participantId, name, country, etc.)
	 *
	 * @return  array|int  Search results array or <= 0 on error
	 */
	public function searchPeppolDirectory($params = [])
	{
		global $langs;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		$queryParams = [];
		$allowedParams = ['query', 'participantId', 'name', 'country', 'geoInfo', 'contact', 'identifierScheme', 'identifierValue'];

		foreach ($allowedParams as $param) {
			if (!empty($params[$param])) {
				$queryParams[] = $param . '=' . urlencode($params[$param]); // @phan-suppress-current-line SqlInjection -- URL query of the access point, not SQL (names from a fixed list, values urlencoded)
			}
		}

		$url = $this->getApiUrl() . "peppol/search";
		if (!empty($queryParams)) {
			$url .= '?' . implode('&', $queryParams);
		}

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'GET', '', $headers);

		dol_syslog("Peppyrus::searchPeppolDirectory HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'searchPeppolDirectory');

		if ($response['success']) {
			$json = json_decode($result['content'], true);
			$count = is_array($json) ? count($json) : 0;
			$message = $langs->trans('peppolSearchSuccess', $count);
			setEventMessage($message, 'mesgs');
			return $json;
		} else {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}
	}

	/**
	 * Get organization's PEPPOL specific info
	 *
	 * @return  array|int  Organization PEPPOL info array or <= 0 on error
	 */
	public function getOrganizationPeppolInfo()
	{
		global $langs;

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		$url = $this->getApiUrl() . "organization/peppol";

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = peppolApiRequest($url, 'GET', '', $headers);

		dol_syslog("Peppyrus::getOrganizationPeppolInfo HTTP code " . ($result['http_code'] ?? 'unknown'));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'getOrganizationPeppolInfo');

		if ($response['success']) {
			$json = json_decode($result['content'], true);
			setEventMessage($langs->trans('peppolOrgInfoSuccess'), 'mesgs');
			return $json;
		} else {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}
	}
}
