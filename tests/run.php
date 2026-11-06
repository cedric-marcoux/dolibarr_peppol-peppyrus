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
	if ($expected === null || $actual === null || abs((float) $expected - (float) $actual) > 0.001) {
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
		checkEquals('PeppolUpdateGitCheckout', peppolUpdateInstallRefusal($admin, dirname(__DIR__), DOL_DATA_ROOT), 'development checkout protected');
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

	// Swap failure restores the installed folder
	check(!peppolUpdateSwap($root . '/custom/missing', $dir, $err), 'swap with a missing folder succeeded');
	check(is_file($dir . '/lib/new.php'), 'module lost after a failed swap');
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
	check(strpos($src, 'peppolUpdateFetchLatest($includeBeta, $error);') !== false, 'installation must not trust the cached release');
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

$conf->facture->dir_output = $realOutput;
if ($tmpRoot && strpos($tmpRoot, realpath(sys_get_temp_dir()) . '/peppolpeppyrus-tests-') === 0 && !is_link($tmpRoot)) {
	dol_delete_dir_recursive($tmpRoot);
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
