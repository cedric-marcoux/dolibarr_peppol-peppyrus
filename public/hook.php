<?php

namespace custom\peppol;

/**
 *     	\file       htdocs/custom/peppolpeppyrus/public/hook.php
 *		\ingroup    peppol
 *		\brief      Webhook endpoint for Peppol notifications
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', 1);
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// For MultiCompany module
$entity = (!empty($_GET['entity']) ? (int) $_GET['entity'] : (!empty($_POST['entity']) ? (int) $_POST['entity'] : 1));
if (is_numeric($entity)) {
	define('DOLENTITY', $entity);
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	--$i;
	--$j;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	exit('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
dol_include_once('/peppolpeppyrus/class/peppolimport.class.php');
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

// Get raw POST data
$rawPayload = file_get_contents('php://input');
$headers = getallheaders();

dol_syslog('Peppol webhook received - Headers: ' . json_encode($headers), LOG_DEBUG);
dol_syslog('Peppol webhook received - Payload length: ' . strlen($rawPayload), LOG_DEBUG);

// Verify HMAC signature
$secretKey = getDolGlobalString('PEPPOL_AP_WEBHOOK_KEY');
if (!empty($secretKey)) {
	$hmacReceived = isset($headers['x-scrada-hmac-sha256']) ? $headers['x-scrada-hmac-sha256'] : '';

	if (empty($hmacReceived)) {
		dol_syslog('Peppol webhook error: No HMAC signature provided', LOG_ERR);
		http_response_code(403);
		echo json_encode(['error' => 'No HMAC signature']);
		exit;
	}

	$hmacCalculated = hash_hmac('sha256', $rawPayload, $secretKey);

	if (!hash_equals($hmacCalculated, $hmacReceived)) {
		dol_syslog('Peppol webhook error: HMAC verification failed', LOG_ERR);
		dol_syslog('Expected: ' . $hmacCalculated . ' Got: ' . $hmacReceived, LOG_DEBUG);
		http_response_code(403);
		echo json_encode(['error' => 'Invalid HMAC signature']);
		exit;
	}

	dol_syslog('Peppol webhook: HMAC verification successful', LOG_DEBUG);
} else {
	dol_syslog('Peppol webhook warning: No secret key configured, skipping HMAC verification', LOG_WARNING);
}

// Extract webhook metadata from headers
$topic = isset($headers['x-scrada-topic']) ? $headers['x-scrada-topic'] : '';
$eventId = isset($headers['x-scrada-event-id']) ? $headers['x-scrada-event-id'] : '';
$companyId = isset($headers['x-scrada-company-id']) ? $headers['x-scrada-company-id'] : '';
$triggeredAt = isset($headers['x-scrada-triggered-at']) ? $headers['x-scrada-triggered-at'] : '';
$attempt = isset($headers['x-scrada-attempt']) ? (int)$headers['x-scrada-attempt'] : 1;
$apiVersion = isset($headers['x-scrada-api-version']) ? $headers['x-scrada-api-version'] : '';

dol_syslog("Peppol webhook - Topic: $topic, Event ID: $eventId, Attempt: $attempt", LOG_INFO);

// Check for duplicate event
if (!empty($eventId)) {
	// TODO: Store processed event IDs in database to detect duplicates
	// For now, just log it
	dol_syslog("Peppol webhook - Processing event ID: $eventId", LOG_DEBUG);
}

// Process based on topic
try {
	switch ($topic) {
		case 'peppolInboundDocument/new':
			handleInboundDocument($db, $headers, $rawPayload);
			break;

		case 'peppolOutboundDocument/statusUpdate':
			handleOutboundStatusUpdate($db, $headers, $rawPayload);
			break;

		case 'salesInvoice/sendStatusUpdate':
			handleSalesInvoiceSendStatus($db, $headers, $rawPayload);
			break;

		case 'purchaseInvoice/new':
			handlePurchaseInvoiceNew($db, $headers, $rawPayload);
			break;

		default:
			dol_syslog("Peppol webhook - Unknown topic: $topic", LOG_WARNING);
			http_response_code(200); // Return 200 anyway to avoid retries
			echo json_encode(['status' => 'ignored', 'message' => 'Unknown topic']);
			exit;
	}

	// Success response
	http_response_code(200);
	echo json_encode(['status' => 'success', 'eventId' => $eventId]);
} catch (\Exception $e) {
	dol_syslog('Peppol webhook error: ' . $e->getMessage(), LOG_ERR);
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle new inbound Peppol document
 *
 * @param   DoliDB  $db         Database handler
 * @param   array   $headers    HTTP headers
 * @param   string  $xml    	Raw data (XML document)
 * @return  void
 */
function handleInboundDocument($db, $headers, $xml)
{
	global $conf, $user, $db;

	// Extract Peppol-specific headers
	$documentId = $headers['x-scrada-document-id'] ?? '';
	$internalNumber = $headers['x-scrada-document-internal-number'] ?? '';
	$senderId = $headers['x-scrada-peppol-sender-id'] ?? '';
	$receiverId = $headers['x-scrada-peppol-receiver-id'] ?? '';
	$senderScheme = $headers['x-scrada-peppol-sender-scheme'] ?? '';
	$receiverScheme = $headers['x-scrada-peppol-receiver-scheme'] ?? '';
	$c1CountryCode = $headers['x-scrada-peppol-c1-country-code'] ?? '';
	$c2Timestamp = $headers['x-scrada-peppol-c2-timestamp'] ?? '';
	$c2SeatId = $headers['x-scrada-peppol-c2-seat-id'] ?? '';
	$c2MessageId = $headers['x-scrada-peppol-c2-message-id'] ?? '';
	$c3IncomingUniqueId = $headers['x-scrada-peppol-c3-incoming-unique-id'] ?? '';
	$c3MessageId = $headers['x-scrada-peppol-c3-message-id'] ?? '';
	$c3Timestamp = $headers['x-scrada-peppol-c3-timestamp'] ?? '';
	$conversationId = $headers['x-scrada-peppol-conversation-id'] ?? '';
	$sbdhInstanceId = $headers['x-scrada-peppol-sbdh-instance-identifier'] ?? '';
	$processScheme = $headers['x-scrada-peppol-process-scheme'] ?? '';
	$processValue = $headers['x-scrada-peppol-process-value'] ?? '';
	$documentTypeScheme = $headers['x-scrada-peppol-document-type-scheme'] ?? '';
	$documentTypeValue = $headers['x-scrada-peppol-document-type-value'] ?? '';

	dol_syslog("Peppol webhook - New inbound document: $documentId from $senderId", LOG_INFO);

	// Check if document already exists
	$pi = new Peppolimport($db);

	// Try to load by peppolid
	$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "peppol_import WHERE peppolid = '" . $db->escape($documentId) . "'";
	$resql = $db->query($sql);
	if ($resql) {
		if ($db->num_rows($resql) > 0) {
			dol_syslog("Peppol webhook - Document $documentId already exists, skipping", LOG_INFO);
			return;
		}
	}

	// Create new Peppolimport
	$pi->setValues([
		'peppolid' => $documentId,
		'internalNumber' => $internalNumber,
		'senderScheme' => $senderScheme,
		'senderID' => $senderId,
		'receiverScheme' => $receiverScheme,
		'receiverID' => $receiverId,
		'c1CountryCode' => $c1CountryCode,
		'c2Timestamp' => $c2Timestamp,
		'c2SeatID' => $c2SeatId,
		'c2MessageID' => $c2MessageId,
		'c3IncomingUniqueID' => $c3IncomingUniqueId,
		'c3MessageID' => $c3MessageId,
		'c3Timestamp' => $c3Timestamp,
		'conversationID' => $conversationId,
		'sbdhInstanceID' => $sbdhInstanceId,
		'processScheme' => $processScheme,
		'processValue' => $processValue,
		'documentTypeScheme' => $documentTypeScheme,
		'documentTypeValue' => $documentTypeValue,
	]);

	$res = $pi->create($user);
	if ($res > 0) {
		$res = $pi->validate($user);

		// Save XML content
		$resSaveXml = $pi->saveXML($xml);

		if ($resSaveXml > 0) {
			dol_syslog("Peppol webhook - Document $documentId saved successfully", LOG_INFO);

			// Try to get PDF (optional, may not always be available immediately)
			// This could be done asynchronously or via a cron job

			//TODO create supplier invoice, import XML data, add PDF file
			//add extrafield value $object->array_options['options_peppol_id'] = $uuid;
			//update pi->fk_supplier and pi->fk_invoice

		} else {
			dol_syslog("Peppol webhook - Error saving XML for document $documentId", LOG_ERR);
			throw new \Exception("Failed to save XML document");
		}
	} else {
		dol_syslog("Peppol webhook - Error creating Peppolimport for document $documentId", LOG_ERR);
		throw new \Exception("Failed to create Peppolimport");
	}
}

/**
 * Handle outbound document status update
 *
 * @param   DoliDB  $db         Database handler
 * @param   array   $headers    HTTP headers
 * @param   string  $payload    JSON payload
 * @return  void
 */
function handleOutboundStatusUpdate($db, $headers, $payload)
{
	$data = json_decode($payload, true);

	if (!$data || !isset($data['id'])) {
		throw new \Exception("Invalid payload for outbound status update");
	}

	$documentId = $data['id'];
	$status = $data['status'] ?? '';
	$errorMessage = $data['errorMessage'] ?? '';
	$externalReference = $data['externalReference'] ?? '';

	dol_syslog("Peppol webhook - Outbound document $documentId status: $status", LOG_INFO);

	// TODO: Update corresponding invoice/document status in Dolibarr
	// You could search by externalReference or peppolid in extrafields
	// and update a status field

	if ($status === 'Error' && !empty($errorMessage)) {
		dol_syslog("Peppol webhook - Outbound error: $errorMessage", LOG_ERR);
		// TODO: Create a notification or event for the user
	}
}

/**
 * Handle sales invoice send status update
 *
 * @param   DoliDB  $db         Database handler
 * @param   array   $headers    HTTP headers
 * @param   string  $payload    JSON payload
 * @return  void
 */
function handleSalesInvoiceSendStatus($db, $headers, $payload)
{
	$data = json_decode($payload, true);

	if (!$data || !isset($data['id'])) {
		throw new \Exception("Invalid payload for sales invoice send status");
	}

	dol_syslog("Peppol webhook - Sales invoice send status update: " . json_encode($data), LOG_INFO);

	// TODO: Process sales invoice send status
}

/**
 * Handle new purchase invoice (full subscription only)
 *
 * @param   DoliDB  $db         Database handler
 * @param   array   $headers    HTTP headers
 * @param   string  $payload    Document payload (XML or PDF)
 * @return  void
 */
function handlePurchaseInvoiceNew($db, $headers, $payload)
{
	$invoiceId = $headers['x-scrada-invoice-id'] ?? '';
	$invoiceNumber = $headers['x-scrada-invoice-number'] ?? '';
	$supplierName = $headers['x-scrada-supplier-party-name'] ?? '';
	$totalInclVat = $headers['x-scrada-total-incl-vat'] ?? '';
	$isCreditNote = ($headers['x-scrada-credit-invoice'] ?? 'false') === 'true';

	dol_syslog("Peppol webhook - New purchase invoice: $invoiceNumber from $supplierName", LOG_INFO);

	// This webhook includes all Peppol headers if it came via Peppol
	// So you can reuse handleInboundDocument logic if needed
	if (isset($headers['x-scrada-peppol-sender-id'])) {
		handleInboundDocument($db, $headers, $payload);
	} else {
		// Handle non-Peppol purchase invoice (uploaded manually, etc.)
		dol_syslog("Peppol webhook - Non-Peppol purchase invoice received", LOG_INFO);
		// TODO: Implement manual invoice handling if needed
	}
}
