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
