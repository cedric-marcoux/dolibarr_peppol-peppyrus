<?php
/* Copyright (C) 2026 Cedric MARCOUX
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Regression tests of the Peppol XML generation.
 *
 * Command line only, run inside a Dolibarr installation where the module is deployed:
 *     php custom/peppolpeppyrus/tests/run.php [filter]
 *
 * The tests build invoices in memory (no database write, no fetch of real documents),
 * and write the generated files into a temporary directory that is removed at the end.
 * Exit code: 0 when every test passes, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit;
}

define('NOSESSION', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');
define('NOREQUIREAJAX', '1');
define('NOLOGIN', '1');

$dolibarrRoot = getenv('DOLIBARR_HTDOCS') ?: dirname(__DIR__, 3);
if (!is_file($dolibarrRoot . '/master.inc.php')) {
	fwrite(STDERR, "Dolibarr not found in $dolibarrRoot (set DOLIBARR_HTDOCS)\n");
	exit(2);
}
require_once $dolibarrRoot . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/modules_facture.php';
dol_include_once('/peppolpeppyrus/class/peppol.class.php');
dol_include_once('/peppolpeppyrus/class/actions_peppolpeppyrus.class.php');
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

$_SESSION = array();
$langs->setDefaultLang('en_US');
$langs->loadLangs(array('peppol@peppolpeppyrus', 'main', 'bills'));

// ---------------------------------------------------------------------------------------------
// Test doubles: in-memory invoice and third party, no database access
// ---------------------------------------------------------------------------------------------

class PeppolTestThirdparty extends Societe
{
	public function fetch_optionals($rowid = null, $optionsArray = null)
	{
		return 1;
	}
}

class PeppolTestInvoice extends Facture
{
	public function fetch_thirdparty($force_thirdparty_id = 0)
	{
		return 1;
	}

	public function getIdBillingContact()
	{
		return array();
	}

	public function getIdShippingContact()
	{
		return array();
	}

	public function getLastMainDocLink($modulepart, $initsharekey = 0, $relativelink = 0)
	{
		return '';
	}

	public function getRetainedWarrantyAmount($rounding = -1)
	{
		return 0;
	}
}

class PeppolTestPdfHandler extends ModelePDFFactures
{
	public function __construct()
	{
	}
}

/**
 * Build an invoice in memory.
 *
 * @param string $ref    Reference
 * @param int    $type   Facture::TYPE_*
 * @param int    $status Facture::STATUS_*
 * @param array  $lines  List of [qty, unit price as entered by the user (>0), vat rate, discount %]
 * @return PeppolTestInvoice
 */
function peppolTestInvoice($ref, $type, $status, array $lines)
{
	global $db;

	$soc = new PeppolTestThirdparty($db);
	$soc->id = 999001;
	$soc->name = 'Test Customer';
	$soc->address = 'Rue de Test 1';
	$soc->zip = '1000';
	$soc->town = 'Bruxelles';
	$soc->country_code = 'BE';
	$soc->state_code = '';
	$soc->tva_intra = 'BE0000000196';
	$soc->tva_assuj = 1;
	$soc->typent_code = 'TE_SMALL';
	$soc->array_options = array('options_peppol_id' => '0208:0000000196');

	$inv = new PeppolTestInvoice($db);
	$inv->id = 999001;
	$inv->ref = $ref;
	$inv->type = $type;
	$inv->status = $status;
	$inv->statut = $status;
	$inv->socid = $soc->id;
	$inv->thirdparty = $soc;
	$inv->date = dol_mktime(12, 0, 0, 11, 5, 2026);
	$inv->date_lim_reglement = dol_mktime(12, 0, 0, 12, 5, 2026);
	$inv->fk_account = 0;
	$inv->multicurrency_code = 'EUR';
	$inv->note_public = '';
	$inv->ref_client = 'PO-TEST';
	$inv->lines = array();

	// Same sign rules as Facture::addline(): a credit note has qty > 0, price < 0, totals < 0
	$isCredit = ($type == Facture::TYPE_CREDIT_NOTE);
	$total_ht = $total_tva = 0;
	foreach ($lines as $i => $l) {
		list($qty, $pu, $vat, $discount) = $l;
		$line = new FactureLigne($db);
		$line->libelle = 'Item ' . ($i + 1);
		$line->desc = 'Test item ' . ($i + 1);
		$line->qty = $isCredit ? abs($qty) : $qty;
		$line->subprice = $isCredit ? -abs($pu) : $pu;
		$line->tva_tx = $vat;
		$line->remise_percent = $discount;
		$ht = round($qty * $pu * (1 - $discount / 100), 2);
		$tva = round($ht * $vat / 100, 2);
		$line->total_ht = ($isCredit || $qty < 0) ? -abs($ht) : $ht;
		$line->total_tva = ($isCredit || $qty < 0) ? -abs($tva) : $tva;
		$line->total_ttc = $line->total_ht + $line->total_tva;
		$line->product_type = 0;
		$inv->lines[] = $line;
		$total_ht += $line->total_ht;
		$total_tva += $line->total_tva;
	}
	$inv->total_ht = $total_ht;
	$inv->total_tva = $total_tva;
	$inv->total_ttc = $total_ht + $total_tva;

	return $inv;
}

/**
 * Prepare an output directory and a fake PDF for an invoice.
 *
 * @param string $ref Reference
 * @return string     Path of the fake PDF
 */
function peppolTestPdf($ref)
{
	global $conf;
	$dir = $conf->facture->dir_output . '/' . $ref;
	dol_mkdir($dir);
	$pdf = $dir . '/' . $ref . '.pdf';
	file_put_contents($pdf, "%PDF-1.4\n%test\n");
	return $pdf;
}

/**
 * Generate the Peppol XML of an invoice and parse it.
 *
 * @param PeppolTestInvoice $inv  Invoice
 * @param array             $warn Receives the PHP warnings raised by the module
 * @return SimpleXMLElement|null  Parsed XML, null when no file was written
 */
function peppolTestMakeXml($inv, &$warn = array())
{
	global $db, $conf;
	$pdf = peppolTestPdf($inv->ref);
	$xmlFile = $conf->facture->dir_output . '/' . $inv->ref . '/' . $inv->ref . '_peppol.xml';
	@unlink($xmlFile);

	$warn = array();
	set_error_handler(function ($no, $str, $file, $line) use (&$warn) {
		if (strpos($file, 'peppolpeppyrus') !== false && strpos($file, '/vendor/') === false) {
			$warn[] = basename($file) . ':' . $line . ' ' . $str;
		}
		return true;
	});
	$p = new \custom\peppolpeppyrus\Peppol($db, $inv);
	$p->makePDF($pdf);
	restore_error_handler();

	if (!empty($p->errors)) {
		$warn[] = 'makePDF errors: ' . strip_tags(implode(' | ', $p->errors));
	}
	if (!is_file($xmlFile)) {
		return null;
	}
	$xml = simplexml_load_file($xmlFile);
	$xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
	$xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
	return $xml;
}

/**
 * First value of an XPath query, as float.
 */
function peppolTestValue(SimpleXMLElement $xml, $xpath)
{
	$r = $xml->xpath($xpath);
	return ($r === false || !isset($r[0])) ? null : (float) $r[0];
}

// ---------------------------------------------------------------------------------------------
// Minimal runner
// ---------------------------------------------------------------------------------------------

$peppolTests = array();
function peppolTest($name, callable $fn)
{
	global $peppolTests;
	$peppolTests[$name] = $fn;
}

function check($cond, $message)
{
	if (!$cond) {
		throw new Exception($message);
	}
}

function checkEquals($expected, $actual, $label)
{
	// Numbers are compared with a tolerance, anything else (text, arrays) must be identical
	if (is_numeric($expected) && is_numeric($actual)) {
		$differs = abs((float) $expected - (float) $actual) > 0.001;
	} else {
		$differs = ($expected !== $actual);
	}
	if ($expected === null || $actual === null || $differs) {
		throw new Exception("$label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

// ---------------------------------------------------------------------------------------------
// BUG-011 credit-note-amounts (#11)
// ---------------------------------------------------------------------------------------------

peppolTest('BUG_011_credit_note_has_positive_amounts', function () {
	$inv = peppolTestInvoice('PEPPOLTEST-CN1', Facture::TYPE_CREDIT_NOTE, Facture::STATUS_VALIDATED, array(
		array(2, 55, 21, 0),
	));
	$xml = peppolTestMakeXml($inv, $warn);
	check($xml !== null, 'no XML written for the credit note (' . implode(' ; ', $warn) . ')');
	check($xml->getName() === 'CreditNote', 'root element is ' . $xml->getName() . ', expected CreditNote');
	checkEquals(381, peppolTestValue($xml, '/*/cbc:CreditNoteTypeCode'), 'CreditNoteTypeCode');
	checkEquals(2, peppolTestValue($xml, '//cac:CreditNoteLine/cbc:CreditedQuantity'), 'CreditedQuantity');
	checkEquals(55, peppolTestValue($xml, '//cac:CreditNoteLine/cac:Price/cbc:PriceAmount'), 'PriceAmount');
	checkEquals(110, peppolTestValue($xml, '//cac:CreditNoteLine/cbc:LineExtensionAmount'), 'line LineExtensionAmount');
	checkEquals(110, peppolTestValue($xml, '/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'), 'TaxExclusiveAmount');
	checkEquals(23.1, peppolTestValue($xml, '/*/cac:TaxTotal/cbc:TaxAmount'), 'TaxAmount');
	checkEquals(133.1, peppolTestValue($xml, '/*/cac:LegalMonetaryTotal/cbc:PayableAmount'), 'PayableAmount');
});

peppolTest('BUG_011_credit_note_line_discount_matches_dolibarr_total', function () {
	// 1 x 200 with 10 % discount: Dolibarr line total = -180
	$inv = peppolTestInvoice('PEPPOLTEST-CN2', Facture::TYPE_CREDIT_NOTE, Facture::STATUS_VALIDATED, array(
		array(1, 200, 21, 10),
		array(3, 10, 6, 0),
	));
	$xml = peppolTestMakeXml($inv, $warn);
	check($xml !== null, 'no XML written for the credit note (' . implode(' ; ', $warn) . ')');
	checkEquals(180, peppolTestValue($xml, '//cac:CreditNoteLine[1]/cbc:LineExtensionAmount'), 'line 1 LineExtensionAmount');
	checkEquals(20, peppolTestValue($xml, '//cac:CreditNoteLine[1]/cac:AllowanceCharge/cbc:Amount'), 'line 1 allowance');
	checkEquals(30, peppolTestValue($xml, '//cac:CreditNoteLine[2]/cbc:LineExtensionAmount'), 'line 2 LineExtensionAmount');
	checkEquals(210, peppolTestValue($xml, '/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'), 'TaxExclusiveAmount');
	checkEquals(210 + 37.8 + 1.8, peppolTestValue($xml, '/*/cac:LegalMonetaryTotal/cbc:PayableAmount'), 'PayableAmount');
});

peppolTest('BUG_011_standard_invoice_unchanged', function () {
	$inv = peppolTestInvoice('PEPPOLTEST-FA1', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(
		array(2, 55, 21, 0),
		array(1, 200, 21, 10),
	));
	$xml = peppolTestMakeXml($inv, $warn);
	check($xml !== null, 'no XML written for the invoice (' . implode(' ; ', $warn) . ')');
	check($xml->getName() === 'Invoice', 'root element is ' . $xml->getName() . ', expected Invoice');
	checkEquals(2, peppolTestValue($xml, '//cac:InvoiceLine[1]/cbc:InvoicedQuantity'), 'InvoicedQuantity');
	checkEquals(110, peppolTestValue($xml, '//cac:InvoiceLine[1]/cbc:LineExtensionAmount'), 'line 1 LineExtensionAmount');
	checkEquals(180, peppolTestValue($xml, '//cac:InvoiceLine[2]/cbc:LineExtensionAmount'), 'line 2 LineExtensionAmount');
	checkEquals(290 * 1.21, peppolTestValue($xml, '/*/cac:LegalMonetaryTotal/cbc:PayableAmount'), 'PayableAmount');
});

peppolTest('BUG_011_no_php_warning_on_zero_vat_line', function () {
	$inv = peppolTestInvoice('PEPPOLTEST-FA2', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(
		array(1, 100, 0, 0),
	));
	$xml = peppolTestMakeXml($inv, $warn);
	$phpWarnings = array_filter($warn, function ($w) {
		return strpos($w, 'makePDF errors') !== 0;
	});
	check(empty($phpWarnings), 'PHP warnings: ' . implode(' ; ', $phpWarnings));
	check($xml !== null, 'no XML written (' . implode(' ; ', $warn) . ')');
});

// ---------------------------------------------------------------------------------------------
// BUG-012 paid-invoice-not-sendable (#12) and BUG-004 draft-invoice-hook-error (#4)
// ---------------------------------------------------------------------------------------------

/**
 * Run the afterPDFCreation hook on an in-memory invoice.
 *
 * @return array [hook return code, XML file written (bool)]
 */
function peppolTestRunHook($inv)
{
	global $db, $conf;
	$pdf = peppolTestPdf($inv->ref);
	$xmlFile = $conf->facture->dir_output . '/' . $inv->ref . '/' . $inv->ref . '_peppol.xml';
	@unlink($xmlFile);
	$actions = new ActionsPeppolpeppyrus($db);
	$handler = new PeppolTestPdfHandler();
	$action = 'builddoc';
	$parameters = array('object' => $inv, 'file' => $pdf);
	$_SERVER['REQUEST_URI'] = '/compta/facture/card.php';
	set_error_handler(function () {
		return true;
	});
	$ret = $actions->afterPDFCreation($parameters, $handler, $action);
	restore_error_handler();
	return array($ret, is_file($xmlFile));
}

peppolTest('BUG_012_paid_invoice_gets_xml', function () {
	$inv = peppolTestInvoice('PEPPOLTEST-FA3', Facture::TYPE_STANDARD, Facture::STATUS_CLOSED, array(array(1, 100, 21, 0)));
	list($ret, $written) = peppolTestRunHook($inv);
	check($ret >= 0, "hook returned $ret");
	check($written, 'no XML written for a paid invoice');
});

peppolTest('BUG_012_converted_credit_note_gets_xml', function () {
	$inv = peppolTestInvoice('PEPPOLTEST-CN3', Facture::TYPE_CREDIT_NOTE, Facture::STATUS_CLOSED, array(array(1, 100, 21, 0)));
	list($ret, $written) = peppolTestRunHook($inv);
	check($ret >= 0, "hook returned $ret");
	check($written, 'no XML written for a credit note converted into a discount');
});

peppolTest('BUG_012_status_eligibility', function () {
	check(function_exists('peppolIsInvoiceStatusEligible'), 'peppolIsInvoiceStatusEligible() is missing');
	$expected = array(
		Facture::STATUS_DRAFT => false,
		Facture::STATUS_VALIDATED => true,
		Facture::STATUS_CLOSED => true,
		Facture::STATUS_ABANDONED => false,
	);
	foreach ($expected as $status => $eligible) {
		$inv = peppolTestInvoice('PEPPOLTEST-ST' . $status, Facture::TYPE_STANDARD, $status, array(array(1, 1, 21, 0)));
		check(peppolIsInvoiceStatusEligible($inv) === $eligible, "status $status: expected " . var_export($eligible, true));
	}
});

peppolTest('BUG_004_draft_invoice_does_not_break_pdf_generation', function () {
	$inv = peppolTestInvoice('PEPPOLTEST-DR1', Facture::TYPE_STANDARD, Facture::STATUS_DRAFT, array(array(1, 100, 21, 0)));
	list($ret, $written) = peppolTestRunHook($inv);
	check($ret === 0, "hook returned $ret for a draft invoice, expected 0 (a negative value makes Dolibarr abort the PDF generation)");
	check(!$written, 'an XML was written for a draft invoice');
});

peppolTest('BUG_004_xml_not_possible_does_not_break_pdf_generation', function () {
	global $conf;
	// Private customer, XML not forced: makePDF() refuses (-3); the PDF generation must not fail
	$conf->global->PEPPOL_FORCE_XML_WITH_VATNULL = 0;
	$inv = peppolTestInvoice('PEPPOLTEST-PR1', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(array(1, 100, 21, 0)));
	$inv->thirdparty->typent_code = 'TE_PRIVATE';
	list($ret, $written) = peppolTestRunHook($inv);
	check($ret === 0, "hook returned $ret for a private customer, expected 0");
	check(!$written, 'an XML was written for a private customer');
});

peppolTest('BUG_004_odt_hook_skips_other_objects', function () {
	global $db;
	$actions = new ActionsPeppolpeppyrus($db);
	$handler = new PeppolTestPdfHandler();
	$action = 'builddoc';
	$propal = new stdClass();
	$propal->element = 'propal';
	$propal->status = 1;
	$ret = $actions->afterODTCreation(array('object' => $propal, 'file' => '/nonexistent.odt'), $handler, $action);
	check($ret === 0, "afterODTCreation returned $ret for a proposal, expected 0");
});

// ---------------------------------------------------------------------------------------------
// BUG-013 send-action-checks (#13) and BUG-005 CSRF token on the action links (#5)
// ---------------------------------------------------------------------------------------------

/**
 * In-memory user with the given rights.
 *
 * @param array $rights e.g. array('peppolpeppyrus' => array('write' => 1))
 * @return User
 */
function peppolTestUser(array $rights)
{
	global $db;
	$u = new User($db);
	$u->id = 999001;
	$u->admin = 0;
	$u->rights = json_decode(json_encode($rights));
	if (!is_object($u->rights)) {
		$u->rights = new stdClass();
	}
	return $u;
}

peppolTest('BUG_013_send_requires_confirmation_rights_and_status', function () {
	$writer = peppolTestUser(array('peppolpeppyrus' => array('write' => 1)));
	$invoiceCreator = peppolTestUser(array('facture' => array('creer' => 1)));
	$reader = peppolTestUser(array('facture' => array('lire' => 1), 'peppolpeppyrus' => array('read' => 1)));
	$validated = peppolTestInvoice('PEPPOLTEST-SD1', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(array(1, 1, 21, 0)));
	$draft = peppolTestInvoice('PEPPOLTEST-SD2', Facture::TYPE_STANDARD, Facture::STATUS_DRAFT, array(array(1, 1, 21, 0)));

	check(peppolSendRefusalReason($writer, $validated, 'yes') === '', 'module writer on a validated invoice must be allowed');
	check(peppolSendRefusalReason($invoiceCreator, $validated, 'yes') === '', 'invoice creator must be allowed');
	check(peppolSendRefusalReason($writer, $validated, 'no') === 'PeppolSendNotConfirmed', 'answer "no" must not send');
	check(peppolSendRefusalReason($writer, $validated, '') === 'PeppolSendNotConfirmed', 'missing answer must not send');
	check(peppolSendRefusalReason($reader, $validated, 'yes') === 'NotEnoughPermissions', 'read-only user must be refused');
	check(peppolSendRefusalReason($writer, $draft, 'yes') === 'PeppolErrorStatusNotEligible', 'draft must be refused');
});

peppolTest('BUG_005_action_links_carry_csrf_token', function () {
	global $db, $user;
	$_SESSION['newtoken'] = 'peppoltesttoken';
	$_SERVER['PHP_SELF'] = '/compta/facture/card.php';
	$savedUser = $user;
	$user = peppolTestUser(array('peppolpeppyrus' => array('write' => 1)));
	$inv = peppolTestInvoice('PEPPOLTEST-TK1', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(array(1, 1, 21, 0)));
	$actions = new ActionsPeppolpeppyrus($db);
	$action = '';
	ob_start();
	$actions->addMoreActionsButtons(array('context' => 'invoicecard'), $inv, $action, null);
	$html = ob_get_clean();
	$user = $savedUser;
	check(strpos($html, 'action=peppolSendFile&token=') !== false, 'send link has no CSRF token: ' . $html);
	check(strpos($html, 'action=peppolCheck&token=') !== false, 'check link has no CSRF token');
});

peppolTest('BUG_013_send_button_hidden_without_rights', function () {
	global $db, $user;
	$_SERVER['PHP_SELF'] = '/compta/facture/card.php';
	$savedUser = $user;
	$user = peppolTestUser(array('facture' => array('lire' => 1)));
	$inv = peppolTestInvoice('PEPPOLTEST-TK2', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(array(1, 1, 21, 0)));
	$actions = new ActionsPeppolpeppyrus($db);
	$action = '';
	ob_start();
	$actions->addMoreActionsButtons(array('context' => 'invoicecard'), $inv, $action, null);
	$html = ob_get_clean();
	$user = $savedUser;
	check(strpos($html, 'peppolSendFile') === false, 'send button shown to a user without the right to send');
});

// ---------------------------------------------------------------------------------------------
// SPEC-014 TASK-006: earlier fixes without a test until now
// ---------------------------------------------------------------------------------------------

peppolTest('BR_CL_10_seller_and_buyer_identifiers_use_valid_schemes', function () {
	global $conf;
	$conf->global->PEPPOL_AP_SENDER_ID = '0208:0000000097';
	$inv = peppolTestInvoice('PEPPOLTEST-ID1', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(array(1, 100, 21, 0)));
	$xml = peppolTestMakeXml($inv, $warn);
	check($xml !== null, 'no XML written (' . implode(' ; ', $warn) . ')');

	// Seller electronic address = the configured Peppol ID (scheme 0208), not the VAT-based 9925
	$sellerEndpoint = $xml->xpath('/*/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID');
	check(!empty($sellerEndpoint), 'seller EndpointID missing');
	check((string) $sellerEndpoint[0]['schemeID'] === '0208', 'seller EndpointID schemeID is ' . $sellerEndpoint[0]['schemeID'] . ', expected 0208');
	check((string) $sellerEndpoint[0] === '0000000097', 'seller EndpointID value is ' . $sellerEndpoint[0]);

	// BR-CL-10: a PartyIdentification scheme must be an ICD code; VAT schemes (99xx) and empty schemes are invalid
	foreach (array('AccountingSupplierParty', 'AccountingCustomerParty') as $party) {
		foreach ($xml->xpath('/*/cac:' . $party . '/cac:Party/cac:PartyIdentification/cbc:ID') as $id) {
			$scheme = (string) $id['schemeID'];
			check($scheme === '' ? false : strpos($scheme, '99') !== 0, "$party PartyIdentification has invalid schemeID '$scheme'");
		}
	}

	// Buyer electronic address = the Peppol ID stored on the third party (extrafield), scheme kept
	$buyerEndpoint = $xml->xpath('/*/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID');
	check(!empty($buyerEndpoint), 'buyer EndpointID missing');
	check((string) $buyerEndpoint[0]['schemeID'] === '0208', 'buyer EndpointID schemeID is ' . $buyerEndpoint[0]['schemeID'] . ', expected 0208');
	check((string) $buyerEndpoint[0] === '0000000196', 'buyer EndpointID value is ' . $buyerEndpoint[0]);

	// BR-CO-09: VAT identifiers start with an uppercase ISO country code
	$sellerVat = $xml->xpath('/*/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID');
	check(!empty($sellerVat) && preg_match('/^[A-Z]{2}/', (string) $sellerVat[0]), 'seller VAT identifier must start with an uppercase country code');
	$conf->global->PEPPOL_AP_SENDER_ID = '';
});

peppolTest('R120_line_amount_matches_quantity_price_and_discount', function () {
	// PEPPOL-EN16931-R120: line net amount = quantity x (price / base quantity) + charges - allowances (slack 0.02)
	// and it must equal the Dolibarr line total, whatever the rounding of price and discount
	$cases = array(
		array(3, 33.33, 21, 7.5),
		array(7, 1.15, 6, 12),
		array(1, 99.99, 21, 33),
		array(13, 0.37, 21, 3),
		array(2.5, 19.99, 12, 10),
		// unit prices with more than 2 decimals: price entered VAT included, converted by Dolibarr
		array(5, 55 / 1.21, 21, 5),
		array(8, 18 / 1.21, 21, 5),
		array(2, 119 / 1.21, 21, 5),
		array(3, 10 / 1.06, 6, 0),
		array(7, 3 / 1.21, 21, 0),
	);
	$inv = peppolTestInvoice('PEPPOLTEST-R120', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, $cases);
	$xml = peppolTestMakeXml($inv, $warn);
	check($xml !== null, 'no XML written (' . implode(' ; ', $warn) . ')');
	$lines = $xml->xpath('//cac:InvoiceLine');
	check(count($lines) === count($cases), count($lines) . ' lines in the XML, expected ' . count($cases));
	$sum = 0;
	foreach ($lines as $i => $line) {
		$line->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
		$line->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
		$qty = (float) $line->xpath('cbc:InvoicedQuantity')[0];
		$price = (float) $line->xpath('cac:Price/cbc:PriceAmount')[0];
		$baseQty = $line->xpath('cac:Price/cbc:BaseQuantity');
		$baseQty = empty($baseQty) ? 1.0 : (float) $baseQty[0];
		$allowances = 0.0;
		foreach ($line->xpath('cac:AllowanceCharge[cbc:ChargeIndicator="false"]/cbc:Amount') as $a) {
			$allowances += (float) $a;
		}
		$charges = 0.0;
		foreach ($line->xpath('cac:AllowanceCharge[cbc:ChargeIndicator="true"]/cbc:Amount') as $c) {
			$charges += (float) $c;
		}
		$lineAmount = (float) $line->xpath('cbc:LineExtensionAmount')[0];
		$expected = $qty * ($price / $baseQty) + $charges - $allowances;
		check(abs($lineAmount - $expected) <= 0.02, 'line ' . ($i + 1) . " R120: LineExtensionAmount $lineAmount, quantity x price - allowances = " . round($expected, 4));
		$sum += $lineAmount;
	}
	checkEquals(round($sum, 2), peppolTestValue($xml, '/*/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'), 'document LineExtensionAmount = sum of lines');
});

peppolTest('BUG_015_xml_amounts_equal_dolibarr_totals', function () {
	// Unit prices with more than 2 decimals (price entered VAT included): the XML line amounts and the
	// document totals must be the amounts of the Dolibarr invoice (the PDF), to the cent
	$cases = array(
		array(3, 10 / 1.06, 6, 0),
		array(7, 3 / 1.21, 21, 0),
		array(5, 55 / 1.21, 21, 5),
		array(8, 18 / 1.21, 21, 5),
		array(11, 4.99 / 1.21, 21, 0),
	);
	foreach (array(Facture::TYPE_STANDARD => 'InvoiceLine', Facture::TYPE_CREDIT_NOTE => 'CreditNoteLine') as $type => $lineTag) {
		$inv = peppolTestInvoice('PEPPOLTEST-TOT' . $type, $type, Facture::STATUS_VALIDATED, $cases);
		$xml = peppolTestMakeXml($inv, $warn);
		check($xml !== null, 'no XML written (' . implode(' ; ', $warn) . ')');
		foreach ($xml->xpath('//cac:' . $lineTag) as $i => $line) {
			$line->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
			checkEquals(abs($inv->lines[$i]->total_ht), (float) $line->xpath('cbc:LineExtensionAmount')[0], "$lineTag " . ($i + 1) . ' LineExtensionAmount vs Dolibarr total_ht');
		}
		checkEquals(abs($inv->total_ht), peppolTestValue($xml, '/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'), "$lineTag document TaxExclusiveAmount vs Dolibarr total_ht");
		// VAT: EN 16931 BR-CO-17 computes it per rate on the taxable amount (Dolibarr sums the VAT of each
		// line by default, so the VAT may differ by at most 1 cent per rate: imposed by the standard)
		$taxable = array();
		foreach ($inv->lines as $l) {
			$rate = (string) (float) $l->tva_tx;
			$taxable[$rate] = ($taxable[$rate] ?? 0) + abs($l->total_ht);
		}
		$vat = 0;
		foreach ($taxable as $rate => $base) {
			$vat += round($base * (float) $rate / 100, 2);
		}
		checkEquals(round($vat, 2), peppolTestValue($xml, '/*/cac:TaxTotal/cbc:TaxAmount'), "$lineTag VAT per rate (BR-CO-17)");
		check(abs(abs($inv->total_tva) - $vat) <= 0.01 * count($taxable) + 0.0001, "$lineTag VAT differs from Dolibarr by more than 1 cent per rate");
		checkEquals(abs($inv->total_ht) + round($vat, 2), peppolTestValue($xml, '/*/cac:LegalMonetaryTotal/cbc:PayableAmount'), "$lineTag PayableAmount");
	}
});

// ---------------------------------------------------------------------------------------------
// BUG-016 peppolfinder-same-window (#16)
// ---------------------------------------------------------------------------------------------

peppolTest('BUG_016_thirdparty_button_opens_search_in_same_window', function () {
	global $db, $user;
	$savedUser = $user;
	$user = peppolTestUser(array('societe' => array('lire' => 1, 'creer' => 1)));
	$soc = new PeppolTestThirdparty($db);
	$soc->id = 999001;
	$actions = new ActionsPeppolpeppyrus($db);
	$action = '';
	ob_start();
	$actions->addMoreActionsButtons(array('context' => 'thirdpartycard'), $soc, $action, null);
	$html = ob_get_clean();
	$user = $savedUser;
	check(strpos($html, '/peppolpeppyrus/search.php?socid=999001') !== false, 'PeppolFinder button missing: ' . $html);
	check(strpos($html, '_blank') === false, 'PeppolFinder still opens a new window');
	check(strpos($html, 'classfortooltip') === false, 'PeppolFinder still has the redundant tooltip');
});

peppolTest('BUG_016_storing_peppol_id_requires_token_and_right', function () {
	$editor = peppolTestUser(array('societe' => array('lire' => 1, 'creer' => 1)));
	$reader = peppolTestUser(array('societe' => array('lire' => 1)));
	check(peppolSetThirdpartyIdRefusalReason($editor, 'abc', 'abc') === '', 'editor with the right token must be allowed');
	check(peppolSetThirdpartyIdRefusalReason($editor, '', 'abc') !== '', 'missing token must be refused');
	check(peppolSetThirdpartyIdRefusalReason($editor, 'xyz', 'abc') !== '', 'wrong token must be refused');
	check(peppolSetThirdpartyIdRefusalReason($reader, 'abc', 'abc') === 'NotEnoughPermissions', 'user without the right to modify third parties must be refused');
});

peppolTest('BUG_016_peppol_id_built_from_directory_result', function () {
	check(peppolBuildThirdpartyPeppolId('iso6523-actorid-upis::0208', '0000000097') === '0208:0000000097', 'scheme code + participant');
	check(peppolBuildThirdpartyPeppolId('', '0208:0000000097') === '0208:0000000097', 'already prefixed id kept as is');
	check(peppolBuildThirdpartyPeppolId('iso6523-actorid-upis::9925', ' BE0000000097 ') === '9925:BE0000000097', 'participant trimmed');
});

// ---------------------------------------------------------------------------------------------
// Security hardening (SEC_*) and #18 #19 #20 #21
// ---------------------------------------------------------------------------------------------

peppolTest('SEC_001_unprotected_directory_page_removed', function () {
	check(!file_exists(dirname(__DIR__) . '/peppol_search_directory.php'), 'peppol_search_directory.php must not be shipped (search.php replaces it)');
});

peppolTest('SEC_002_incoming_documents_need_import_right_and_token', function () {
	check(function_exists('peppolUserCanImport') && function_exists('peppolTokenIsValid'), 'access helpers missing');
	$importer = peppolTestUser(array('peppolpeppyrus' => array('import' => 1)));
	$reader = peppolTestUser(array('peppolpeppyrus' => array('read' => 1), 'societe' => array('lire' => 1, 'creer' => 1)));
	$external = peppolTestUser(array('peppolpeppyrus' => array('import' => 1)));
	$external->socid = 12;
	check(peppolUserCanImport($importer), 'user with the import right must be allowed');
	check(!peppolUserCanImport($reader), 'user without the import right must be refused');
	check(!peppolUserCanImport($external), 'external user must be refused');
	check(peppolTokenIsValid('abc', 'abc') && !peppolTokenIsValid('', 'abc') && !peppolTokenIsValid('abc', '') && !peppolTokenIsValid('abd', 'abc'), 'token check');
	$src = file_get_contents(dirname(__DIR__) . '/peppolimport_list.php');
	check(strpos($src, "action=refresh', 1)") === false, 'refresh link without token');
	check(preg_match('/\$action == "refresh"\) \{\s*if \(!\$permissiontoadd \|\| !peppolTokenIsValid/', $src) === 1, 'refresh action must check the write right and the token');
});

peppolTest('SEC_003_webhook_refuses_unauthenticated_requests', function () {
	check(function_exists('peppolWebhookAuthRefusal'), 'peppolWebhookAuthRefusal() missing');
	$body = '{"test":1}';
	check(peppolWebhookAuthRefusal('', $body, '') !== '', 'no key configured: must refuse');
	check(peppolWebhookAuthRefusal('secret', $body, '') !== '', 'no signature: must refuse');
	check(peppolWebhookAuthRefusal('secret', $body, str_repeat('0', 64)) !== '', 'wrong signature: must refuse');
	check(peppolWebhookAuthRefusal('secret', $body, hash_hmac('sha256', $body, 'secret')) === '', 'valid signature must be accepted');
	foreach (array('', 'no signature', 'invalid signature') as $unused) {
		check(strpos(peppolWebhookAuthRefusal('secret', $body, 'x'), 'secret') === false, 'refusal reason must not contain the key');
	}
	// Real HTTP request on the local web server, without signature (curl: allow_url_fopen is often disabled)
	$url = getenv('PEPPOL_TEST_BASE_URL') ?: 'http://127.0.0.1';
	if (function_exists('curl_init')) {
		$ch = curl_init($url . '/custom/peppolpeppyrus/public/hook.php');
		curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
			CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Scrada-Topic: peppolInboundDocument/new')));
		curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($code > 0) {
			check($code === 403, "webhook over HTTP answered $code instead of 403");
		} else {
			echo "  note: webhook HTTP check not run ($url not reachable, set PEPPOL_TEST_BASE_URL)\n";
		}
	} else {
		echo "  note: webhook HTTP check not run (no curl extension)\n";
	}
});

peppolTest('SEC_004_incoming_document_entity_checked', function () {
	global $conf;
	check(function_exists('peppolObjectIsInUserEntities'), 'peppolObjectIsInUserEntities() missing');
	$doc = new stdClass();
	$doc->element = 'peppolimport';
	$doc->entity = $conf->entity;
	check(peppolObjectIsInUserEntities($doc), 'document of the current entity must be allowed');
	$doc->entity = 987654;
	check(!peppolObjectIsInUserEntities($doc), 'document of another entity must be refused');
	$src = file_get_contents(dirname(__DIR__) . '/peppolimport_card.php');
	check(strpos($src, 'peppolObjectIsInUserEntities($object)') !== false, 'card must check the entity');
	check(preg_match('/\$action == \'analyze\'\) \{\s*(\/\/[^\n]*\n\s*)?if \(!\$permissiontoadd \|\| !peppolTokenIsValid/', $src) === 1, 'card analyze action must check the write right and the token');
	check(strpos($src, "&action=analyze&token=") !== false, 'card analyze button without token');
	$list = file_get_contents(dirname(__DIR__) . '/peppolimport_list.php');
	check(strpos($list, '!peppolObjectIsInUserEntities($object)') !== false, 'analyze mass action must ignore documents of other entities');
});

peppolTest('SEC_005_zip_export_checks_each_invoice', function () {
	global $db;
	check(function_exists('peppolUserCanReadInvoice'), 'peppolUserCanReadInvoice() missing');
	$res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture ORDER BY rowid LIMIT 1");
	$row = $res ? $db->fetch_object($res) : null;
	$inv = new Facture($db);
	$inv->id = $row ? (int) $row->rowid : 1;
	$noRight = peppolTestUser(array('societe' => array('lire' => 1)));
	check(!peppolUserCanReadInvoice($noRight, $inv), 'user without the invoice read right must be refused');
	$salesRep = peppolTestUser(array('facture' => array('lire' => 1), 'societe' => array('lire' => 1)));
	if ($row) {
		ob_start();
		$refused = !peppolUserCanReadInvoice($salesRep, $inv);
		$printed = ob_get_clean();
		check($refused, 'sales representative not linked to the customer must be refused');
		check(trim(strip_tags($printed)) === '', 'access check printed an error: ' . trim(strip_tags($printed)));
		$seeAll = peppolTestUser(array('facture' => array('lire' => 1), 'societe' => array('lire' => 1, 'client' => array('voir' => 1))));
		check(peppolUserCanReadInvoice($seeAll, $inv), 'user who sees all customers must be allowed');
	}
	$src = file_get_contents(dirname(__DIR__) . '/class/actions_peppolpeppyrus.class.php');
	check(strpos($src, 'peppolUserCanReadInvoice($user, $obj)') !== false, 'zip mass action must check each invoice');
});

peppolTest('BUG_018_send_log_is_written', function () {
	global $db, $user;
	$db->begin();
	$ret = peppolAddLog('peppyrus', 999001, 1, '200', "Message with 'quote' and <b>tag</b>", '{"a":1}');
	$res = $db->query("SELECT status, message, ap_name FROM " . MAIN_DB_PREFIX . "peppol WHERE fk_object = 999001 AND object_typeid = 1");
	$row = $res ? $db->fetch_object($res) : null;
	$db->rollback();
	check($ret > 0 && $row, 'peppolAddLog() did not write the row (' . $db->lasterror() . ')');
	check($row->message === "Message with 'quote' and <b>tag</b>" && $row->status === '200' && $row->ap_name === 'peppyrus', 'stored values differ');
});

peppolTest('SPEC_019_access_point_data_escaped', function () {
	global $db;
	check(function_exists('peppolSanitizeIdentifier'), 'peppolSanitizeIdentifier() missing');
	check(peppolSanitizeIdentifier('0208:0000000097') === '0208:0000000097', 'valid identifier changed');
	check(peppolSanitizeIdentifier('<script>alert(1)</script>0208:1') === 'scriptalert1script0208:1', 'markup must be removed from identifiers');
	dol_include_once('/peppolpeppyrus/class/ap-peppyrus.class.php');
	$ap = new \custom\peppolpeppyrus\Peppyrus($db);
	$m = new ReflectionMethod($ap, 'parseValidationError');
	$m->setAccessible(true);
	$out = $m->invoke($ap, json_encode(array('message' => '<img src=x onerror=alert(1)>', 'errors' => array('<b>x</b>'))));
	check(strpos($out, '<img') === false && strpos($out, '<b>') === false, 'validation error from the access point not escaped: ' . $out);
	$src = file_get_contents(dirname(__DIR__) . '/peppol_tab.php');
	check(strpos($src, '$obj->message . "') === false && strpos($src, 'dol_escape_htmltag($obj->message)') !== false, 'Peppol tab prints the log message without escaping');
	$src = file_get_contents(dirname(__DIR__) . '/class/ap-peppyrus.class.php');
	check(strpos($src, '"message/" . $pi->peppolid') === false, 'document id not encoded in the API URL');
});

peppolTest('SPEC_020_api_keys_not_sent_back_to_the_browser', function () {
	$src = file_get_contents(dirname(__DIR__) . '/admin/setup.php');
	check(strpos($src, "value=\"' . getDolGlobalString('PEPPOL_AP_API_KEY") === false, 'setup page prints an API key in the form');
	check(substr_count($src, 'type="password"') >= 2, 'API key fields must be password fields');
	$hook = file_get_contents(dirname(__DIR__) . '/public/hook.php');
	check(strpos($hook, "'Expected: '") === false, 'webhook logs the expected signature');
});

peppolTest('BUG_021_send_only_invoice_peppol_xml', function () {
	global $db;
	$writer = peppolTestUser(array('peppolpeppyrus' => array('write' => 1)));
	$soc = new PeppolTestThirdparty($db);
	$soc->element = 'societe';
	$soc->status = Facture::STATUS_VALIDATED;
	check(peppolSendRefusalReason($writer, $soc, 'yes') !== '', 'send action must be refused for an object that is not a customer invoice');
	$inv = peppolTestInvoice('PEPPOLTEST-XML1', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(array(1, 1, 21, 0)));
	$_GET['selectFilename'] = 'other-document.xml';
	$ret = peppolFindFileToUse($inv, '');
	unset($_GET['selectFilename']);
	check($ret === -1, 'an XML file that is not the Peppol XML of the invoice was accepted');
});

// ---------------------------------------------------------------------------------------------
// SPEC-022: update from signed GitHub releases
// ---------------------------------------------------------------------------------------------

dol_include_once('/peppolpeppyrus/lib/peppolupdate.lib.php');

/**
 * Release as returned by the GitHub API.
 */
function peppolTestRelease($tag, $pre, $signed = true, $draft = false, $repo = PEPPOL_UPDATE_REPO)
{
	$zip = 'module_peppolpeppyrus-' . preg_replace('/^v|-beta\.\d+$/', '', $tag) . '.zip';
	$base = 'https://github.com/' . $repo . '/releases/download/' . $tag . '/';
	$assets = array(array('name' => $zip, 'browser_download_url' => $base . $zip));
	if ($signed) {
		$assets[] = array('name' => $zip . '.sig', 'browser_download_url' => $base . $zip . '.sig');
	}
	return array('tag_name' => $tag, 'prerelease' => $pre, 'draft' => $draft, 'assets' => $assets);
}

/**
 * Build a ZIP in the test directory.
 *
 * @param array $entries  name => content (null for a directory)
 * @param array $symlinks names stored as symbolic links
 */
function peppolTestZip(array $entries, array $symlinks = array())
{
	global $tmpRoot;
	$path = $tmpRoot . '/zip-' . bin2hex(random_bytes(6)) . '.zip';
	$zip = new ZipArchive();
	$zip->open($path, ZipArchive::CREATE);
	foreach ($entries as $name => $content) {
		if ($content === null) {
			$zip->addEmptyDir($name);
		} else {
			$zip->addFromString($name, $content);
		}
	}
	foreach ($symlinks as $name) {
		$zip->addFromString($name, '/etc/passwd');
		$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
	}
	$zip->close();
	return $path;
}

function peppolTestModuleEntries($version, array $extra = array())
{
	return array_merge(array(
		'peppolpeppyrus/' => null,
		'peppolpeppyrus/core/modules/modPeppolpeppyrus.class.php' => "<?php\nclass modPeppolpeppyrusTest {\n\tfunction __construct() {\n\t\t\$this->version = '" . $version . "';\n\t}\n}\n",
		'peppolpeppyrus/lib/new.php' => "<?php\n// new\n",
	), $extra);
}

peppolTest('BUG_023_developer_scripts_command_line_only', function () {
	foreach (array('json2csv.php', 'validate.php') as $script) {
		$src = file_get_contents(dirname(__DIR__) . '/scripts/' . $script);
		check(strpos($src, "if (PHP_SAPI !== 'cli') {") !== false && strpos($src, "if (PHP_SAPI !== 'cli') {") < 80, $script . ' must refuse to run from the web');
		if (function_exists('curl_init')) {
			$ch = curl_init('http://127.0.0.1/custom/peppolpeppyrus/scripts/' . $script);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);
			if ($code !== 0) {
				checkEquals(403, $code, $script . ' over HTTP');
			}
		}
	}
});

/**
 * Set or remove the disclaimer acceptance of an entity (in the test folder, PEPPOL_DISCLAIMER_DIR).
 */
function peppolTestSetDisclaimer($value, $entity = null)
{
	global $conf;
	$entity = ($entity === null) ? (int) $conf->entity : (int) $entity;
	$records = peppolDisclaimerRecords();
	if ($value === null) {
		unset($records[(string) $entity]);
	} else {
		$records[(string) $entity] = json_decode($value, true);
	}
	if (!is_dir(PEPPOL_DISCLAIMER_DIR)) {
		mkdir(PEPPOL_DISCLAIMER_DIR, 0700, true);
	}
	file_put_contents(PEPPOL_DISCLAIMER_DIR . '/disclaimer.json', json_encode($records));
}

peppolTest('SPEC_024_disclaimer_required_before_sending', function () {
	global $conf, $langs;
	$accepted = json_encode(array('version' => PEPPOL_DISCLAIMER_VERSION, 'user_id' => 1, 'login' => 'a', 'date' => 1));
	$writer = peppolTestUser(array('peppolpeppyrus' => array('write' => 1)));
	$inv = peppolTestInvoice('PEPPOLTEST-DISC', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(array(1, 10, 21, 0)));
	try {
		peppolTestSetDisclaimer(null);
		check(peppolDisclaimerAcceptance() === null, 'disclaimer seen as accepted without acceptance');
		checkEquals('PeppolDisclaimerNotAccepted', peppolSendRefusalReason($writer, $inv, 'yes'), 'send refused before acceptance');
		// A record of another entity does not count, nor a Dolibarr constant (forged through the constants page)
		peppolTestSetDisclaimer($accepted, 0);
		check(peppolDisclaimerAcceptance() === null, 'acceptance of entity 0 counted for the current entity');
		peppolTestSetDisclaimer(null, 0);
		$GLOBALS['db']->query("INSERT INTO " . MAIN_DB_PREFIX . "const (name, entity, value, type, visible) VALUES ('PEPPOL_DISCLAIMER_ACCEPTED', " . ((int) $conf->entity) . ", '" . $GLOBALS['db']->escape($accepted) . "', 'chaine', 0)");
		$conf->global->PEPPOL_DISCLAIMER_ACCEPTED = $accepted;
		check(peppolDisclaimerAcceptance() === null, 'acceptance forged in the Dolibarr constants counted');
		unset($conf->global->PEPPOL_DISCLAIMER_ACCEPTED);
		peppolTestSetDisclaimer(json_encode(array('version' => PEPPOL_DISCLAIMER_VERSION - 1, 'user_id' => 1, 'login' => 'a', 'date' => 1)));
		check(peppolDisclaimerAcceptance() === null, 'acceptance of an older text accepted');
		// Send button shown disabled with the reason
		$hooks = new ActionsPeppolpeppyrus($GLOBALS['db']);
		$inv->id = 999002;
		$action = '';
		$GLOBALS['user'] = $writer;
		ob_start();
		$hooks->addMoreActionsButtons(array('context' => 'invoicecard'), $inv, $action, null);
		$html = ob_get_clean();
		check(strpos($html, 'action=peppolSendFile') === false && strpos($html, 'butActionRefused') !== false, 'send link shown before acceptance');
		// Non administrators cannot accept
		check(!peppolDisclaimerAccept($GLOBALS['db'], $writer), 'non administrator accepted the disclaimer');
		// An administrator accepts: current record and history written, private files
		$admin = peppolTestUser(array());
		$admin->admin = 1;
		$admin->id = 4242;
		$admin->login = 'testadmin';
		check(peppolDisclaimerAccept($GLOBALS['db'], $admin), 'administrator could not accept');
		checkEquals(4242, peppolDisclaimerAcceptance()['user_id'] ?? null, 'accepting user recorded');
		check(strpos((string) file_get_contents(PEPPOL_DISCLAIMER_DIR . '/disclaimer.log'), '"user_id":4242') !== false, 'acceptance missing from the history');
		checkEquals('600', substr(sprintf('%o', fileperms(PEPPOL_DISCLAIMER_DIR . '/disclaimer.json')), -3), 'record file permissions');
		checkEquals('600', substr(sprintf('%o', fileperms(PEPPOL_DISCLAIMER_DIR . '/disclaimer.log')), -3), 'history file permissions');
		checkEquals('', peppolSendRefusalReason($writer, $inv, 'yes'), 'send allowed after acceptance');
		// A corrupt record file is never overwritten (the other entities would lose their acceptance)
		$good = file_get_contents(PEPPOL_DISCLAIMER_DIR . '/disclaimer.json');
		file_put_contents(PEPPOL_DISCLAIMER_DIR . '/disclaimer.json', '{corrupt');
		check(!peppolDisclaimerAccept($GLOBALS['db'], $admin), 'corrupt record file overwritten');
		checkEquals('{corrupt', file_get_contents(PEPPOL_DISCLAIMER_DIR . '/disclaimer.json'), 'corrupt record file changed');
		file_put_contents(PEPPOL_DISCLAIMER_DIR . '/disclaimer.json', $good);
	} finally {
		peppolTestSetDisclaimer($accepted);
	}
	$src = file_get_contents(dirname(__DIR__) . '/admin/setup.php');
	check(strpos($src, "if (\$action == 'acceptdisclaimer') {\n\tif (!peppolTokenIsValid(") !== false, 'acceptance must check the CSRF token');
	foreach (array('en_US', 'fr_FR', 'nl_BE', 'de_DE') as $lang) {
		$txt = file_get_contents(dirname(__DIR__) . '/langs/' . $lang . '/peppol.lang');
		foreach (array('Title', 'Intro', 'Item1', 'Item2', 'Item3', 'Item4', 'Liability', 'Law', 'AcceptLabel', 'NotAccepted') as $k) {
			check(preg_match('/^PeppolDisclaimer' . $k . ' = \S/m', $txt) === 1, 'PeppolDisclaimer' . $k . ' missing in ' . $lang);
		}
	}
	$lib = file_get_contents(dirname(__DIR__) . '/lib/peppol.lib.php');
	check(preg_match('/function peppolSendToAccessPoint\([^)]*\)\s*\{[^}]*?if \(!peppolDisclaimerAcceptance\(\)\)/s', $lib) === 1, 'the access point call itself must check the acceptance');
});

peppolTest('BUG_025_disclaimer_notice_visible', function () {
	global $langs, $conf;
	$accepted = json_encode(array('version' => PEPPOL_DISCLAIMER_VERSION, 'user_id' => 1, 'login' => 'a', 'date' => 1));
	$writer = peppolTestUser(array('peppolpeppyrus' => array('write' => 1)));
	$admin = peppolTestUser(array());
	$admin->admin = 1;
	// Notice: explanation for everybody, link to the setup only for administrators, escaped
	$n = peppolDisclaimerNotice($writer, $langs);
	check($n !== '' && strpos($n, 'admin/setup.php') === false, 'notice for a non administrator');
	check(strpos(peppolDisclaimerNotice($admin, $langs), 'peppolpeppyrus/admin/setup.php') !== false, 'setup link missing for administrators');
	try {
		peppolTestSetDisclaimer(null);
		$inv = peppolTestInvoice('PEPPOLTEST-NOTICE', Facture::TYPE_STANDARD, Facture::STATUS_VALIDATED, array(array(1, 10, 21, 0)));
		$inv->id = 999003;
		$GLOBALS['user'] = $writer;
		$_SESSION['dol_events'] = array();
		$hooks = new ActionsPeppolpeppyrus($GLOBALS['db']);
		$action = '';
		ob_start();
		$hooks->addMoreActionsButtons(array('context' => 'invoicecard'), $inv, $action, null);
		ob_end_clean();
		$warnings = isset($_SESSION['dol_events']['warnings']) ? implode(' ', $_SESSION['dol_events']['warnings']) : '';
		check(strpos($warnings, dol_escape_htmltag($langs->transnoentitiesnoconv('PeppolDisclaimerNotAcceptedNotice'))) !== false, 'no visible warning on the invoice while the disclaimer is not accepted');
		$_SESSION['dol_events'] = array();
		peppolTestSetDisclaimer($accepted);
		ob_start();
		$hooks->addMoreActionsButtons(array('context' => 'invoicecard'), $inv, $action, null);
		ob_end_clean();
		check(empty($_SESSION['dol_events']['warnings']), 'warning shown although the disclaimer is accepted');
	} finally {
		peppolTestSetDisclaimer($accepted);
		$_SESSION['dol_events'] = array();
	}
	$src = file_get_contents(dirname(__DIR__) . '/admin/update.php');
	check(preg_match("/PeppolUpdateDone.*?if \(!peppolDisclaimerAcceptance\(\)\) \{\s*setEventMessages\(peppolDisclaimerNotice/s", $src) === 1, 'no warning after an update that requires a new acceptance');
	check(strpos($src, "info_admin(peppolDisclaimerNotice(\$user, \$langs), 0, 0, 'warning')") !== false, 'no warning in the Updates tab');
	foreach (array('en_US', 'fr_FR', 'nl_BE', 'de_DE') as $lang) {
		$txt = file_get_contents(dirname(__DIR__) . '/langs/' . $lang . '/peppol.lang');
		check(preg_match('/^PeppolDisclaimerNotAcceptedNotice = \S/m', $txt) && preg_match('/^PeppolDisclaimerGoToSetup = \S/m', $txt), 'notice translation missing in ' . $lang);
	}
});

peppolTest('SPEC_022_release_choice_per_channel', function () {
	$list = array(
		peppolTestRelease('v2.3.0', false),
		peppolTestRelease('v2.4.0-beta.1', true),
		peppolTestRelease('v2.5.0', false, true, true),                        // draft
		peppolTestRelease('v9.0.0', false, false),                             // no signature
		peppolTestRelease('v8.0.0', false, true, false, 'someone/forked-repo'), // other repository
		peppolTestRelease('latest', false),                                    // not a version tag
		peppolTestRelease('v7.0.0-beta.1', false),                             // beta tag published as stable
	);
	$stable = peppolUpdatePickRelease($list, false);
	checkEquals('2.3.0', $stable['version'] ?? null, 'stable channel');
	checkEquals('https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/v2.3.0/module_peppolpeppyrus-2.3.0.zip.sig', $stable['sig_url'], 'signature URL');
	$beta = peppolUpdatePickRelease($list, true);
	checkEquals('7.0.0-beta.1', $beta['version'] ?? null, 'beta channel takes the newest signed beta');
	check(peppolUpdatePickRelease(array(peppolTestRelease('v9.0.0', false, false)), true) === null, 'an unsigned release was offered');
	check(peppolUpdatePickRelease('not a list', true) === null, 'invalid API answer accepted');
});

peppolTest('SPEC_022_only_newer_versions', function () {
	check(peppolUpdateIsNewer('2.3.0', '2.3.0-beta.1'), 'final after beta');
	check(peppolUpdateIsNewer('2.3.0-beta.2', '2.3.0-beta.1'), 'beta.2 after beta.1');
	check(peppolUpdateIsNewer('2.3.0-beta.10', '2.3.0-beta.9'), 'beta.10 after beta.9');
	check(!peppolUpdateIsNewer('2.3.0-beta.1', '2.3.0'), 'downgrade to a beta accepted');
	check(!peppolUpdateIsNewer('2.3.0', '2.3.0'), 'same version accepted');
	check(!peppolUpdateIsNewer('2.2.9', '2.3.0-beta.1'), 'older version accepted');
	check(!peppolUpdateIsNewer('9.0.0; rm', '1.0.0'), 'malformed version accepted');
});

peppolTest('SPEC_022_signature_verification', function () {
	$pk = base64_decode(PEPPOL_UPDATE_PUBLIC_KEY, true);
	check($pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'embedded public key is not a valid Ed25519 key');
	$kp = sodium_crypto_sign_keypair();
	$pub = base64_encode(sodium_crypto_sign_publickey($kp));
	$hash = hash('sha256', 'zip content');
	$sig = base64_encode(sodium_crypto_sign_detached(peppolUpdateSignedMessage('v2.4.0', $hash), sodium_crypto_sign_secretkey($kp)));
	check(peppolUpdateVerifySignature('v2.4.0', $hash, $sig . "\n", $pub), 'valid signature refused');
	check(!peppolUpdateVerifySignature('v9.9.9', $hash, $sig, $pub), 'signature accepted for another tag');
	check(!peppolUpdateVerifySignature('v2.4.0', hash('sha256', 'other'), $sig, $pub), 'signature accepted for another ZIP');
	check(!peppolUpdateVerifySignature('v2.4.0', $hash, $sig), 'signature from another key accepted');
	check(!peppolUpdateVerifySignature('v2.4.0', $hash, substr($sig, 0, 40), $pub), 'truncated signature accepted');
	check(!peppolUpdateVerifySignature('v2.4.0', $hash, '!!not base64!!', $pub), 'garbage signature accepted');
});

peppolTest('SPEC_022_zip_content_checked', function () {
	$err = '';
	check(peppolUpdateCheckZip(peppolTestZip(peppolTestModuleEntries('2.4.0')), '2.4.0', $err), 'valid ZIP refused: ' . $err);
	check(!peppolUpdateCheckZip(peppolTestZip(peppolTestModuleEntries('2.4.0')), '2.5.0', $err), 'ZIP of another version accepted');
	check(!peppolUpdateCheckZip(peppolTestZip(peppolTestModuleEntries('2.4.0', array('peppolpeppyrus/../evil.php' => 'x'))), '2.4.0', $err), 'path traversal accepted');
	check(!peppolUpdateCheckZip(peppolTestZip(peppolTestModuleEntries('2.4.0', array('othermodule/evil.php' => 'x'))), '2.4.0', $err), 'file outside the module folder accepted');
	check(!peppolUpdateCheckZip(peppolTestZip(peppolTestModuleEntries('2.4.0', array('peppolpeppyrus/.git/config' => 'x'))), '2.4.0', $err), '.git content accepted');
	check(!peppolUpdateCheckZip(peppolTestZip(peppolTestModuleEntries('2.4.0', array('peppolpeppyrus\\evil.php' => 'x'))), '2.4.0', $err), 'backslash path accepted');
	check(!peppolUpdateCheckZip(peppolTestZip(peppolTestModuleEntries('2.4.0'), array('peppolpeppyrus/link.php')), '2.4.0', $err), 'symbolic link accepted');
	check(!peppolUpdateCheckZip(peppolTestZip(array('peppolpeppyrus/readme.txt' => 'x')), '2.4.0', $err), 'ZIP without module descriptor accepted');
	check(!peppolUpdateEntryNameValid('/peppolpeppyrus/x') && !peppolUpdateEntryNameValid('peppolpeppyrus//x') && !peppolUpdateEntryNameValid("peppolpeppyrus/a\0b"), 'unsafe entry names accepted');
});

peppolTest('SPEC_022_install_guards', function () {
	global $tmpRoot;
	$root = $tmpRoot . '/guards-' . bin2hex(random_bytes(4));
	mkdir($root . '/data', 0700, true);
	mkdir($root . '/custom/peppolpeppyrus', 0775, true);
	$dir = $root . '/custom/peppolpeppyrus';
	$admin = peppolTestUser(array());
	$admin->admin = 1;
	checkEquals('', peppolUpdateInstallRefusal($admin, $dir, $root . '/data'), 'allowed case');
	checkEquals('NotEnoughPermissions', peppolUpdateInstallRefusal(peppolTestUser(array('peppolpeppyrus' => array('write' => 1))), $dir, $root . '/data'), 'non administrator');
	$external = peppolTestUser(array());
	$external->admin = 1;
	$external->socid = 12;
	checkEquals('NotEnoughPermissions', peppolUpdateInstallRefusal($external, $dir, $root . '/data'), 'external user');
	touch($root . '/data/installmodules.lock');
	checkEquals('PeppolUpdateLocked', peppolUpdateInstallRefusal($admin, $dir, $root . '/data'), 'installmodules.lock');
	unlink($root . '/data/installmodules.lock');
	mkdir($dir . '/.git');
	checkEquals('PeppolUpdateGitCheckout', peppolUpdateInstallRefusal($admin, $dir, $root . '/data'), 'git checkout');
	rmdir($dir . '/.git');
	mkdir($dir . '/sub/locked', 0775, true);
	chmod($dir . '/sub/locked', 0555);
	$ret = peppolUpdateInstallRefusal($admin, $dir, $root . '/data');
	chmod($dir . '/sub/locked', 0775);
	checkEquals('PeppolUpdateFolderNotWritable', $ret, 'folder that could not be removed after the update');
	// The real module folder of this installation is a git checkout on development servers
	if (file_exists(dirname(__DIR__) . '/.git')) {
		check(peppolUpdateInstallRefusal($admin, dirname(__DIR__), DOL_DATA_ROOT) !== '', 'development checkout protected');
	}
});

peppolTest('SPEC_022_install_swap_backup_and_rollback', function () {
	global $tmpRoot;
	$root = $tmpRoot . '/install-' . bin2hex(random_bytes(4));
	$dir = $root . '/custom/peppolpeppyrus';
	$work = $root . '/data/peppolpeppyrus_update';
	mkdir($dir . '/core/modules', 0775, true);
	file_put_contents($dir . '/core/modules/modPeppolpeppyrus.class.php', "<?php\n\$this->version = '2.3.0';\n");
	file_put_contents($dir . '/old-only.php', "<?php\n");
	$kp = sodium_crypto_sign_keypair();
	$pub = base64_encode(sodium_crypto_sign_publickey($kp));
	$sign = function ($tag, $data) use ($kp) {
		return base64_encode(sodium_crypto_sign_detached(peppolUpdateSignedMessage($tag, hash('sha256', $data)), sodium_crypto_sign_secretkey($kp)));
	};
	$err = '';

	// Wrong signature: nothing changes
	$good = file_get_contents(peppolTestZip(peppolTestModuleEntries('2.4.0')));
	check(!peppolUpdateInstallData('v2.4.0', $good, $sign('v2.4.1', $good), '2.3.0', $dir, $work, $err, $pub), 'bad signature installed');
	check(is_file($dir . '/old-only.php'), 'module changed after a refused update');

	// Signed but unsafe ZIP: refused, no staging folder left
	$evil = file_get_contents(peppolTestZip(peppolTestModuleEntries('2.4.0', array('peppolpeppyrus/../../evil.php' => 'x'))));
	check(!peppolUpdateInstallData('v2.4.0', $evil, $sign('v2.4.0', $evil), '2.3.0', $dir, $work, $err, $pub), 'unsafe ZIP installed');
	check(!file_exists($root . '/evil.php') && !file_exists($root . '/custom/evil.php'), 'file written outside the module');

	// Downgrade refused even when signed
	$old = file_get_contents(peppolTestZip(peppolTestModuleEntries('2.2.0')));
	check(!peppolUpdateInstallData('v2.2.0', $old, $sign('v2.2.0', $old), '2.3.0', $dir, $work, $err, $pub), 'downgrade installed');

	// Valid update
	check(peppolUpdateInstallData('v2.4.0', $good, $sign('v2.4.0', $good), '2.3.0', $dir, $work, $err, $pub), 'valid update refused: ' . $err);
	checkEquals('', $err, 'warning after a valid update');
	check(is_file($dir . '/lib/new.php') && !is_file($dir . '/old-only.php'), 'module folder not replaced');
	check(strpos(file_get_contents($dir . '/core/modules/modPeppolpeppyrus.class.php'), "'2.4.0'") !== false, 'new descriptor missing');
	$backups = glob($work . '/backup-2.3.0-*.zip');
	check(count($backups) === 1, 'backup of the previous version missing');
	$z = new ZipArchive();
	$z->open($backups[0]);
	check($z->locateName('peppolpeppyrus/old-only.php') !== false, 'backup does not contain the previous files');
	$z->close();
	checkEquals(array('peppolpeppyrus'), array_values(array_diff(scandir($root . '/custom'), array('.', '..'))), 'folders left next to the module');
	check(!glob($work . '/download-*'), 'download left in the work folder');

	// Installed version changed meanwhile (another administrator): refused
	$next = file_get_contents(peppolTestZip(peppolTestModuleEntries('2.5.0')));
	check(!peppolUpdateInstallData('v2.5.0', $next, $sign('v2.5.0', $next), '2.3.0', $dir, $work, $err, $pub), 'update installed over a version changed meanwhile');
	checkEquals('2.4.0', peppolUpdateDescriptorVersion($dir . '/core/modules/modPeppolpeppyrus.class.php'), 'descriptor version read from disk');

	// Leftovers of an interrupted update are removed, other folders are kept
	mkdir($root . '/custom/.peppolpeppyrus-old-' . str_repeat('a', 24) . '/admin', 0775, true);
	mkdir($root . '/custom/.peppolpeppyrus-new-' . str_repeat('b', 24), 0775, true);
	mkdir($root . '/custom/.peppolpeppyrus-old-keepme', 0775, true);
	check(peppolUpdateCleanLeftovers($root . '/custom'), 'leftovers not removed');
	checkEquals(array('.peppolpeppyrus-old-keepme', 'peppolpeppyrus'), array_values(array_diff(scandir($root . '/custom'), array('.', '..'))), 'folders after cleaning');
	rmdir($root . '/custom/.peppolpeppyrus-old-keepme');

	// Only the newest backups are kept
	for ($i = 1; $i <= 5; $i++) {
		touch($work . '/backup-old' . $i . '.zip', time() - 1000 * $i);
	}
	peppolUpdatePruneBackups($work, 3);
	checkEquals(3, count(glob($work . '/backup-*.zip')), 'number of backups kept');
	check(count(glob($work . '/backup-2.3.0-*.zip')) === 1, 'newest backup removed');

	// A leftover made unreadable is still removed by the cleaning
	$left = $root . '/custom/.peppolpeppyrus-old-' . str_repeat('c', 24);
	mkdir($left . '/admin', 0775, true);
	file_put_contents($left . '/admin/x.php', 'x');
	chmod($left, 0);
	check(peppolUpdateCleanLeftovers($root . '/custom') && !file_exists($left), 'unreadable leftover not removed');

	// Swap failure restores the installed folder
	check(!peppolUpdateSwap($root . '/custom/missing', $dir, $err), 'swap with a missing folder succeeded');
	check(is_file($dir . '/lib/new.php'), 'module lost after a failed swap');
});

/**
 * Sign a manifest with a test key.
 */
function peppolTestManifest($kp, array $fields)
{
	$base = array('module' => 'peppolpeppyrus', 'issued' => 1000000, 'expires' => 1000000 + 7 * 86400, 'stable' => '', 'stable_sha256' => '', 'beta' => '', 'beta_sha256' => '');
	foreach (array('stable', 'beta') as $k) {
		if (!empty($fields[$k]) && !isset($fields[$k . '_sha256'])) {
			$fields[$k . '_sha256'] = hash('sha256', $fields[$k]);
		}
	}
	$json = json_encode(array_merge($base, $fields));
	return array($json, base64_encode(sodium_crypto_sign_detached(peppolUpdateManifestMessage($json), sodium_crypto_sign_secretkey($kp))));
}

peppolTest('SPEC_022_signed_manifest_blocks_hidden_or_old_releases', function () {
	$kp = sodium_crypto_sign_keypair();
	$pub = base64_encode(sodium_crypto_sign_publickey($kp));
	$now = 1000000 + 86400;
	$err = '';
	list($json, $sig) = peppolTestManifest($kp, array('stable' => '2.4.1', 'beta' => '2.5.0-beta.1'));
	$m = peppolUpdateParseManifest($json, $sig, $now, $err, $pub);
	check(is_array($m), 'valid manifest refused: ' . $err);
	check(peppolUpdateParseManifest($json, $sig, $now, $err) === null, 'manifest signed with another key accepted');
	check(peppolUpdateParseManifest(str_replace('2.4.1', '2.4.0', $json), $sig, $now, $err, $pub) === null, 'modified manifest accepted');
	check(peppolUpdateParseManifest($json, $sig, 1000000 + 8 * 86400, $err, $pub) === null && $err === 'Update manifest expired', 'expired manifest accepted');
	list($j2, $s2) = peppolTestManifest($kp, array('stable' => '2.4.1', 'expires' => 1000000 + 400 * 86400));
	check(peppolUpdateParseManifest($j2, $s2, $now, $err, $pub) === null, 'manifest valid for more than a month accepted');
	list($j3, $s3) = peppolTestManifest($kp, array('stable' => '2.4.1; x'));
	check(peppolUpdateParseManifest($j3, $s3, $now, $err, $pub) === null, 'malformed version accepted');
	list($j5, $s5) = peppolTestManifest($kp, array());
	check(peppolUpdateParseManifest($j5, $s5, $now, $err, $pub) === null, 'manifest naming no version accepted');
	list($j4, $s4) = peppolTestManifest($kp, array('stable' => '2.4.1', 'stable_sha256' => ''));
	check(peppolUpdateParseManifest($j4, $s4, $now, $err, $pub) === null, 'version without ZIP hash accepted');

	// The newest release v2.4.1 is hidden on GitHub: the older signed v2.4.0 must not be offered
	$hidden = array(peppolTestRelease('v2.4.0', false), peppolTestRelease('v2.3.0', false));
	check(peppolUpdatePickRelease($hidden, false, $m) === null, 'older release offered while the manifest names a newer one');
	checkEquals('2.4.0', peppolUpdatePickRelease($hidden, false)['version'], 'without manifest the newest visible release is taken');
	$all = array(peppolTestRelease('v2.5.0-beta.1', true), peppolTestRelease('v2.4.1', false), peppolTestRelease('v2.4.0', false), peppolTestRelease('v2.6.0', false));
	checkEquals('2.4.1', peppolUpdatePickRelease($all, false, $m)['version'], 'stable channel follows the manifest');
	checkEquals(hash('sha256', '2.4.1'), peppolUpdatePickRelease($all, false, $m)['sha256'], 'ZIP hash pinned by the manifest');
	checkEquals('2.5.0-beta.1', peppolUpdatePickRelease($all, true, $m)['version'], 'beta channel follows the manifest');
});

peppolTest('SPEC_022_fetch_uses_newest_valid_manifest_and_refuses_replay', function () {
	$kp = sodium_crypto_sign_keypair();
	$pub = base64_encode(sodium_crypto_sign_publickey($kp));
	// The production key is embedded: test the whole fetch with a manifest signed by a test key
	// through a fake transport, then check that it is refused with the embedded key
	list($json, $sig) = peppolTestManifest($kp, array('issued' => 2000000, 'expires' => 2000000 + 7 * 86400, 'stable' => '2.4.1'));
	$rel = peppolTestRelease('v2.4.1', false);
	$base = 'https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/v2.4.1/';
	$rel['assets'][] = array('name' => 'update-manifest.json', 'browser_download_url' => $base . 'update-manifest.json');
	$rel['assets'][] = array('name' => 'update-manifest.json.sig', 'browser_download_url' => $base . 'update-manifest.json.sig');
	$files = array(
		'https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases' => json_encode(array($rel, peppolTestRelease('v2.4.0', false))),
		$base . 'update-manifest.json' => $json,
		$base . 'update-manifest.json.sig' => $sig,
	);
	$GLOBALS['peppolTestFiles'] = $files;
	$fetch = function ($url, $max, $accept, &$error) {
		$error = '';
		return $GLOBALS['peppolTestFiles'][$url] ?? false;
	};
	$err = '';
	$issued = 0;
	$r = peppolUpdateFetchLatest(false, $err, 0, $issued, $fetch, 2000000 + 3600);
	check($r === null && $err !== '', 'manifest signed with a key other than the embedded one accepted');
	// Same flow with the key that signed the manifest
	$r = peppolUpdateFetchLatest(false, $err, 0, $issued, $fetch, 2000000 + 3600, $pub);
	checkEquals('2.4.1', $r['version'] ?? null, 'release named by the manifest: ' . $err);
	checkEquals(2000000, $issued, 'manifest issue time returned');
	// Replay: a valid manifest older than the last one seen is refused
	$r = peppolUpdateFetchLatest(false, $err, 3000000, $issued, $fetch, 2000000 + 3600, $pub);
	check($r === null && $err === 'Update manifest older than the last one seen', 'replayed manifest accepted');
	// Newest release hidden: manifest names 2.4.1 but only 2.4.0 is listed
	$GLOBALS['peppolTestFiles']['https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases'] = json_encode(array(
		array_merge(peppolTestRelease('v2.4.0', false), array('assets' => array_merge(peppolTestRelease('v2.4.0', false)['assets'], array(
			array('name' => 'update-manifest.json', 'browser_download_url' => 'https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/v2.4.0/update-manifest.json'),
			array('name' => 'update-manifest.json.sig', 'browser_download_url' => 'https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/v2.4.0/update-manifest.json.sig'),
		)))),
	));
	$GLOBALS['peppolTestFiles']['https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/v2.4.0/update-manifest.json'] = $json;
	$GLOBALS['peppolTestFiles']['https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/v2.4.0/update-manifest.json.sig'] = $sig;
	$r = peppolUpdateFetchLatest(false, $err, 0, $issued, $fetch, 2000000 + 3600, $pub);
	check($r === null && strpos($err, 'is not available') !== false, 'older release offered while the newest is hidden');
	// Several manifests (older copies added to hide the real one): refused
	$two = json_decode($GLOBALS['peppolTestFiles']['https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases'], true);
	$copy = $two[0];
	$copy['tag_name'] = 'v2.3.9';
	foreach ($copy['assets'] as &$a) {
		$a['browser_download_url'] = str_replace('/v2.4.0/', '/v2.3.9/', $a['browser_download_url']);
	}
	unset($a);
	$saved = $GLOBALS['peppolTestFiles']['https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases'];
	$GLOBALS['peppolTestFiles']['https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases'] = json_encode(array($two[0], $copy));
	$r = peppolUpdateFetchLatest(false, $err, 0, $issued, $fetch, 2000000 + 3600, $pub);
	check($r === null && $err === 'Several update manifests found', 'several manifests accepted');
	$GLOBALS['peppolTestFiles']['https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases'] = $saved;
	// Expired manifest (signing stopped or old copy re-published): nothing offered
	$r = peppolUpdateFetchLatest(false, $err, 0, $issued, $fetch, 2000000 + 20 * 86400, $pub);
	check($r === null && $err === 'Update manifest expired', 'expired manifest accepted');
	// No manifest at all (removed or hidden): visible error, nothing offered
	$GLOBALS['peppolTestFiles']['https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases'] = json_encode(array(peppolTestRelease('v2.4.0', false)));
	$r = peppolUpdateFetchLatest(false, $err, 0, $issued, $fetch, 2000000 + 3600);
	check($r === null && $err === 'No update manifest found', 'missing manifest not reported');
	unset($GLOBALS['peppolTestFiles']);
});

peppolTest('SPEC_022_end_to_end_check_and_install', function () {
	global $tmpRoot;
	$kp = sodium_crypto_sign_keypair();
	$pub = base64_encode(sodium_crypto_sign_publickey($kp));
	$root = $tmpRoot . '/e2e-' . bin2hex(random_bytes(4));
	$dir = $root . '/custom/peppolpeppyrus';
	mkdir($dir . '/core/modules', 0775, true);
	file_put_contents($dir . '/core/modules/modPeppolpeppyrus.class.php', "<?php\n\$this->version = '2.3.0';\n");
	$zip = file_get_contents(peppolTestZip(peppolTestModuleEntries('2.4.1')));
	$tag = 'v2.4.1';
	$base = 'https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/' . $tag . '/';
	list($json, $msig) = peppolTestManifest($kp, array('issued' => 5000000, 'expires' => 5000000 + 7 * 86400, 'stable' => '2.4.1', 'stable_sha256' => hash('sha256', $zip)));
	$rel = peppolTestRelease($tag, false);
	$rel['assets'][] = array('name' => 'update-manifest.json', 'browser_download_url' => $base . 'update-manifest.json');
	$rel['assets'][] = array('name' => 'update-manifest.json.sig', 'browser_download_url' => $base . 'update-manifest.json.sig');
	$GLOBALS['peppolTestFiles'] = array(
		'https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases' => json_encode(array($rel)),
		$base . 'update-manifest.json' => $json,
		$base . 'update-manifest.json.sig' => $msig,
		$base . 'module_peppolpeppyrus-2.4.1.zip' => $zip,
		$base . 'module_peppolpeppyrus-2.4.1.zip.sig' => base64_encode(sodium_crypto_sign_detached(peppolUpdateSignedMessage($tag, hash('sha256', $zip)), sodium_crypto_sign_secretkey($kp))),
	);
	$fetch = function ($url, $max, $accept, &$error, $timeout = 15) {
		$error = '';
		$body = $GLOBALS['peppolTestFiles'][$url] ?? false;
		return ($body !== false && strlen($body) > $max) ? false : $body;
	};
	$err = '';
	$issued = 0;
	$release = peppolUpdateFetchLatest(false, $err, 0, $issued, $fetch, 5000000 + 60, $pub);
	checkEquals('2.4.1', $release['version'] ?? null, 'release found: ' . $err);
	// A ZIP rebuilt and signed again for the same tag, but not the one pinned by the manifest
	$other = file_get_contents(peppolTestZip(peppolTestModuleEntries('2.4.1', array('peppolpeppyrus/extra.php' => 'x'))));
	$saved = $GLOBALS['peppolTestFiles'];
	$GLOBALS['peppolTestFiles'][$base . 'module_peppolpeppyrus-2.4.1.zip'] = $other;
	$GLOBALS['peppolTestFiles'][$base . 'module_peppolpeppyrus-2.4.1.zip.sig'] = base64_encode(sodium_crypto_sign_detached(peppolUpdateSignedMessage($tag, hash('sha256', $other)), sodium_crypto_sign_secretkey($kp)));
	check(!peppolUpdateInstall($release, '2.3.0', $dir, $root . '/work', $err, $fetch, $pub), 'ZIP not pinned by the manifest installed');
	$GLOBALS['peppolTestFiles'] = $saved;
	check(peppolUpdateInstall($release, '2.3.0', $dir, $root . '/work', $err, $fetch, $pub), 'end-to-end install failed: ' . $err);
	checkEquals('2.4.1', peppolUpdateDescriptorVersion($dir . '/core/modules/modPeppolpeppyrus.class.php'), 'installed version');
	check(!peppolUpdateInstall($release, '2.4.1', $dir, $root . '/work', $err, $fetch, $pub), 'same version installed twice');
	unset($GLOBALS['peppolTestFiles']);
});

peppolTest('SPEC_022_settings_kept_out_of_dolibarr_constants', function () {
	global $tmpRoot;
	$src = file_get_contents(dirname(__DIR__) . '/admin/update.php');
	check(!preg_match("/(getDolGlobal(String|Int)|dolibarr_set_const\(\$db), ?'PEPPOL_UPDATE_/", $src), 'updater setting stored in Dolibarr constants (writable by any entity administrator)');
	$work = $tmpRoot . '/state-' . bin2hex(random_bytes(4));
	$st = peppolUpdateLoadState($work);
	check($st['beta'] === 0 && $st['manifest_issued'] === 0 && $st['cache'] === null, 'default state');
	check(peppolUpdateSaveState($work, array('beta' => 1, 'cache' => array('time' => 5), 'manifest_issued' => 42)), 'state not saved');
	checkEquals(array('beta' => 1, 'cache' => array('time' => 5), 'manifest_issued' => 42), peppolUpdateLoadState($work), 'state read back');
	checkEquals('600', substr(sprintf('%o', fileperms($work . '/state.json')), -3), 'state file permissions');
	checkEquals('700', substr(sprintf('%o', fileperms($work)), -3), 'work folder permissions');
	// The newest manifest seen never goes back, even if a concurrent check saves an older value
	check(peppolUpdateChangeState($work, function ($st) {
		$st['manifest_issued'] = 10;
		return $st;
	}), 'state change failed');
	checkEquals(42, peppolUpdateLoadState($work)['manifest_issued'], 'replay counter lowered');
});

peppolTest('SPEC_022_downloads_restricted_to_github_https', function () {
	check(peppolUpdateUrlAllowed('https://api.github.com/repos/x/y/releases'), 'GitHub API refused');
	check(peppolUpdateUrlAllowed('https://objects.githubusercontent.com/a/b'), 'GitHub asset host refused');
	foreach (array(
		'http://github.com/a',
		'https://github.com.evil.example/a',
		'https://evil.example/github.com',
		'https://user:pass@github.com/a',
		'https://github.com:8443/a',
		'file:///etc/passwd',
		'https://127.0.0.1/a',
		'https://github.com\\@evil.example/a',
	) as $url) {
		check(!peppolUpdateUrlAllowed($url), 'URL accepted: ' . $url);
	}
});

peppolTest('SPEC_022_version_file_and_update_page', function () {
	global $db;
	dol_include_once('/peppolpeppyrus/core/modules/modPeppolpeppyrus.class.php');
	$mod = new modPeppolpeppyrus($db);
	checkEquals($mod->version, trim(file_get_contents(dirname(__DIR__) . '/VERSION')), 'VERSION file');
	checkEquals('https://raw.githubusercontent.com/' . PEPPOL_UPDATE_REPO . '/main/VERSION', $mod->url_last_version, 'url_last_version');
	$pem = file_get_contents(dirname(__DIR__) . '/release-signing-key.pub');
	$der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem), true);
	checkEquals(PEPPOL_UPDATE_PUBLIC_KEY, base64_encode(substr($der, -32)), 'published public key matches the embedded key');
	$src = file_get_contents(dirname(__DIR__) . '/admin/update.php');
	check(strpos($src, "array('setchannel', 'check', 'confirm_install', 'finalize'), true) && !\$tokenOk") !== false, 'update actions must check the CSRF token');
	check(strpos($src, "if (empty(\$user->admin) || !empty(\$user->socid)") !== false, 'update page must be restricted to administrators');
	check(substr_count($src, "peppolUpdateFetchLatest(\$includeBeta, \$error, \$state['manifest_issued'], \$issued);") === 2, 'check and installation must use the signed manifest and refuse an older one');
	check(strpos($src, "\$refusal = 'PeppolDisclaimerNotAccepted';") !== false, 'installation must require the accepted disclaimer');
});

// ---------------------------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------------------------

$filter = $argv[1] ?? '';
// Unpredictable private directory, created by us (mkdir fails if the path already exists,
// so a planted directory or link is refused)
$tmpRoot = sys_get_temp_dir() . '/peppolpeppyrus-tests-' . bin2hex(random_bytes(8));
if (!@mkdir($tmpRoot, 0700) || is_link($tmpRoot)) {
	fwrite(STDERR, "Cannot create a private temporary directory\n");
	exit(2);
}
$tmpRoot = realpath($tmpRoot);
$realOutput = $conf->facture->dir_output;
$conf->facture->dir_output = $tmpRoot;
$conf->global->FACTURE_RIB_NUMBER = 0;

// Everything written to the database by the tests is rolled back at the end (also if the run dies:
// the transaction is never committed)
$db->begin();
// Disclaimer records of the tests are kept in the private test folder (#24)
define('PEPPOL_DISCLAIMER_DIR', $tmpRoot . '/disclaimer');
// The other tests describe the behaviour once the disclaimer is accepted (#24)
peppolTestSetDisclaimer(json_encode(array('version' => PEPPOL_DISCLAIMER_VERSION, 'user_id' => 1, 'login' => 'test', 'date' => 1)));

$passed = $failed = 0;
foreach ($peppolTests as $name => $fn) {
	if ($filter !== '' && strpos($name, $filter) === false) {
		continue;
	}
	try {
		$fn();
		$passed++;
		echo "PASS $name\n";
	} catch (Throwable $e) {
		$failed++;
		echo "FAIL $name: " . $e->getMessage() . "\n";
	}
}

$db->rollback();
$conf->facture->dir_output = $realOutput;
if ($tmpRoot && strpos($tmpRoot, realpath(sys_get_temp_dir()) . '/peppolpeppyrus-tests-') === 0 && !is_link($tmpRoot)) {
	dol_delete_dir_recursive($tmpRoot);
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
