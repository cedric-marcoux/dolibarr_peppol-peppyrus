<?php

/**
 * \file    peppol/class/peppyrus.class.php
 * \ingroup peppol
 * \brief   Peppyrus access point implementation
 *
 */

namespace custom\peppolpeppyrus;

require_once 'peppolap.class.php';

/**
 * Class Peppyrus
 */
class Peppyrus extends PeppolAP
{
	public $description = "Peppyrus is a free and reliable PEPPOL Access Point";
	public $operatorurl = "https://www.peppyrus.be/";
	public $status = 8;
	public $supplierInvoicesList;
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
			return getDolGlobalString('PEPPOL_AP_API_KEY', '');
		} else {
			// Use DEV key if available, fallback to PROD key for backward compatibility
			$devKey = getDolGlobalString('PEPPOL_AP_API_KEY_DEV', '');
			return !empty($devKey) ? $devKey : getDolGlobalString('PEPPOL_AP_API_KEY', '');
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
			$keyName = $isProd ? 'PEPPOL_AP_API_KEY' : 'PEPPOL_AP_API_KEY_DEV';
			dol_syslog("Peppyrus::validateConfiguration Missing API key for " . ($isProd ? 'PROD' : 'DEV') . " mode", LOG_ERR);
			$this->errors[] = "Missing API key configuration (" . $keyName . ")";
			return -1;
		}

		return 1;
	}

	/**
	 * Handle API response with proper error codes
	 *
	 * @param   array   $result   CURL result from getURLContent
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

		if ($json && isset($json->message)) {
			$errorMsg = $json->message;
			if (isset($json->errors) && is_array($json->errors)) {
				foreach ($json->errors as $e) {
					$errorMsg .= '<br>- ' . (is_string($e) ? $e : json_encode($e));
				}
			}
		} elseif (!empty($content)) {
			// Fallback: use raw content without HTML tags
			$errorMsg = strip_tags($content);
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

		$result = getURLContent($url, 'GET', '', 1, $headers);

		dol_syslog("Peppyrus::checkAccessPoint CURL result is " . json_encode($result));

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
					$message .= ' - folder: ' . $json->folder;
				}
			} else {
				$message = $langs->trans('peppolAccessPointCheckSuccess');
				if (isset($json->name)) {
					$message .= ' - Organization: ' . $json->name;
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

		$result = getURLContent($url, 'POST', $body, 1, $headers);

		dol_syslog("Peppyrus::sendToAccessPoint CURL result is " . json_encode($result));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'sendToAccessPoint');

		if ($response['success']) {
			$json = $response['data'];

			if (isset($json->id)) {
				$message = $langs->trans('peppolSendSuccess') . ' - Message ID: ' . $json->id;
				setEventMessages($message, null, 'mesgs');

				// Store the message ID in the object for tracking
				if (!isset($object->array_options) || empty($object->array_options)) {
					$object->fetch_optionals();
				}

				// Save Peppyrus message ID if extrafield exists
				if (array_key_exists('options_peppol_id', $object->array_options)) {
					dol_syslog("Peppol update extrafields : update object->array_options['options_peppol_id'] to " . $json->id);
					$object->array_options['options_peppol_id'] = $json->id;
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

		$result = getURLContent($url, 'GET', '', 1, $headers);

		dol_syslog("Peppyrus::checkThirdparty CURL result is " . json_encode($result));

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

		if ($this->validateConfiguration() < 0) {
			setEventMessages("Error", $this->errors, 'errors');
			return -10;
		}

		$apiKey = $this->getApiKey();

		// Get messages from INBOX folder, filter for unconfirmed messages
		$url = $this->getApiUrl() . "message/list?folder=INBOX&confirmed=false&perPage=100";

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = getURLContent($url, 'GET', '', 1, $headers);

		dol_syslog("Peppyrus::getSupplierInvoicesList CURL result is " . json_encode($result));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'getSupplierInvoicesList');

		if (!$response['success']) {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}

		$json = $response['data'];

		if (!isset($json->items) || !is_array($json->items)) {
			setEventMessage($langs->trans('peppolNoInvoicesFound'), 'warnings');
			return 0;
		}

		$messageCount = count($json->items);

		$message = $langs->trans('peppolInvoicesListSuccess') . ' - ' . $messageCount . ' message(s) found';

		if (isset($json->meta)) {
			$message .= ' (Page ' . $json->meta->currentPage . '/' . $json->meta->pages . ')';
		}

		setEventMessages($message, null, 'mesgs');

		// Process each message
		foreach ($json->items as $entry) {
			$pi = new Peppolimport($db);
			$res = $pi->fetch(0, null, $entry->id);
			if ($res > 0) {
				dol_syslog("Invoice already imported, next");
				continue;
			}

			$pi->setValues([
				"peppolid" => $entry->id,
				"senderID" => $entry->sender,
				"receiverID" => $entry->recipient,
			]);
			$res = $pi->create($user);
			if ($res > 0) {
				$res = $pi->validate($user);
				dol_syslog("Peppolimport Validate return " . json_encode($res));
				$resXml = $this->getSupplierInvoice($pi);
				$resPdf = $this->getSupplierInvoicePdf($pi);

				// Confirm to AP in case of no errors
				if ($resXml > 0 && $resPdf > 0) {
					$resConfirm = $this->confirmSupplierInvoice($pi);
					if ($resConfirm <= 0) {
						dol_syslog("Peppyrus::getSupplierInvoicesList Error confirming invoice: " . $resConfirm, LOG_WARNING);
					}
				}
			} else {
				dol_syslog("Peppolimport Error : " . json_encode($res));
			}
		}

		return $messageCount;
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
		$url = $this->getApiUrl() . "message/" . $pi->peppolid;

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = getURLContent($url, 'GET', '', 1, $headers);

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
			$message .= ' - Sender: ' . $json->sender;
		}
		if (isset($json->created)) {
			$message .= ' - Date: ' . $json->created;
		}

		setEventMessages($message, null, 'mesgs');
		return 1;
	}

	/**
	 * Get PDF file of invoice $pi waiting into your peppol AP
	 *
	 * @param   Peppolimport  $pi  peppolimport object to import
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	public function getSupplierInvoicePdf(Peppolimport $pi)
	{
		global $langs;

		// Peppyrus API does not provide a specific PDF endpoint
		// PDFs are typically embedded in the message or need to be generated
		// For now, return success but log that PDF extraction is not available

		dol_syslog("Peppyrus::getSupplierInvoicePdf - Peppyrus does not provide a separate PDF endpoint", LOG_INFO);
		setEventMessage($langs->trans('peppolPdfNotAvailable'), 'warnings');

		// Return success to not block the import process
		return 1;
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
		$url = $this->getApiUrl() . "message/" . $pi->peppolid . "/confirm";

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = getURLContent($url, 'PATCH', '', 1, $headers);

		dol_syslog("Peppyrus::confirmSupplierInvoice CURL result is " . json_encode($result));

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
	 * Reject supplier invoice (in case of trouble / error / other)
	 *
	 * @param   Peppolimport  $pi  peppolimport object to import
	 * @param   string        $reason  reason for rejection
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	public function rejectSupplierInvoice(Peppolimport $pi, $reason)
	{
		global $langs;

		// Peppyrus API does not provide a specific reject endpoint in the OpenAPI spec
		// The rejection would typically be handled by not confirming the message
		// and potentially sending a negative response message

		dol_syslog("Peppyrus::rejectSupplierInvoice - Peppyrus does not provide a specific reject endpoint", LOG_INFO);
		dol_syslog("Peppyrus::rejectSupplierInvoice - Reason: " . $reason, LOG_INFO);

		setEventMessage($langs->trans('peppolRejectNotAvailable'), 'warnings');

		// Return error to indicate the operation is not available
		// The calling code should handle this appropriately
		return -1;
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

		$result = getURLContent($url, 'GET', '', 1, $headers);

		dol_syslog("Peppyrus::getMessageReport CURL result is " . json_encode($result));

		// Use centralized response handler
		$response = $this->handleApiResponse($result, 'getMessageReport');

		if ($response['success']) {
			$json = json_decode($result['content'], true);
			setEventMessage($langs->trans('peppolReportSuccess'), 'mesgs');
			return $json;
		} else {
			setEventMessage($response['error_msg'], 'errors');
			return $response['error_code'];
		}
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

		$result = getURLContent($url, 'GET', '', 1, $headers);

		dol_syslog("Peppyrus::findPeppolIdByVat CURL result is " . json_encode($result));

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
				$queryParams[] = $param . '=' . urlencode($params[$param]);
			}
		}

		$url = $this->getApiUrl() . "peppol/search";
		if (!empty($queryParams)) {
			$url .= '?' . implode('&', $queryParams);
		}

		$headers = [];
		$headers[] = 'X-Api-Key: ' . $apiKey;

		$result = getURLContent($url, 'GET', '', 1, $headers);

		dol_syslog("Peppyrus::searchPeppolDirectory CURL result is " . json_encode($result));

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

		$result = getURLContent($url, 'GET', '', 1, $headers);

		dol_syslog("Peppyrus::getOrganizationPeppolInfo CURL result is " . json_encode($result));

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
