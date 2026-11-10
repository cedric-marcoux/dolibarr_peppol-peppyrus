<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Tests of class/peppolbooking.class.php: reading an e-invoice (made-up data only, no database).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/class/peppolbooking.class.php';

/**
 * Stand-in for the user (rights as User::hasRight())
 */
class PeppolBookingFakeUser
{
	/** @var bool */
	public $allowed = true;

	/**
	 * @param string $module Module
	 * @param string $perm   Permission
	 * @param string $sub    Sub permission
	 * @return bool
	 */
	public function hasRight($module, $perm, $sub = '')
	{
		return $this->allowed;
	}
}

/**
 * Stand-in for an incoming document (Peppolimport)
 */
class PeppolBookingFakeImport
{
	/** @var int */
	public $id = 7;
	/** @var string */
	public $ref = 'PI-7';
	/** @var string */
	public $senderID = '0106:12345678';
	/** @var int */
	public $fk_invoice = 0;
	/** @var int */
	public $status = 1;
	/** @var string */
	public $response_code = '';
	/** @var string */
	public $response_note = '';
	/** @var string */
	public $file = '';
	/** @var string */
	public $error = '';

	/**
	 * @return string
	 */
	public function fullFilename()
	{
		return $this->file;
	}

	/**
	 * @param object $user User
	 * @param string $code Code
	 * @param string $note Note
	 * @return int
	 */
	public function saveResponse($user, $code, $note = '')
	{
		$this->response_code = $code;
		$this->response_note = $note;
		return 1;
	}
}

/**
 * Stand-in for the access point
 */
class PeppolBookingFakeAP
{
	/** @var int */
	public static $ret = 1;
	/** @var array<int,array<string,string>> */
	public static $sent = array();
	/** @var string */
	public $error = 'made-up error';

	/**
	 * @return PeppolBookingFakeAP
	 */
	public static function make()
	{
		return new self();
	}

	/**
	 * @param object               $pi   Document
	 * @param array<string,string> $resp Response
	 * @return int
	 */
	public function sendInvoiceResponse($pi, array $resp)
	{
		self::$sent[] = $resp;
		return self::$ret;
	}
}

/**
 * Reading of incoming e-invoices for booking
 */
class PeppolBookingTest extends TestCase
{
	/**
	 * A made-up UBL invoice (Peppol BIS Billing 3.0)
	 *
	 * @param string $type  Invoice type code
	 * @param string $extra Extra XML before the lines
	 * @return string
	 */
	private function invoice($type = '380', $extra = '')
	{
		$pdf = base64_encode("%PDF-1.4\n% made-up\n");
		return '<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
 <cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0</cbc:CustomizationID>
 <cbc:ID>INV-001</cbc:ID>
 <cbc:IssueDate>2026-10-31</cbc:IssueDate>
 <cbc:DueDate>2026-11-30</cbc:DueDate>
 <cbc:InvoiceTypeCode>'.$type.'</cbc:InvoiceTypeCode>
 <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
 <cac:AdditionalDocumentReference><cbc:ID>INV-001</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="application/pdf" filename="INV-001.pdf">'.$pdf.'</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>
 <cac:AccountingSupplierParty><cac:Party>
  <cbc:EndpointID schemeID="0106">12345678</cbc:EndpointID>
  <cac:PartyName><cbc:Name>Example Supplier</cbc:Name></cac:PartyName>
  <cac:PostalAddress><cbc:StreetName>Examplestreet 1</cbc:StreetName><cbc:CityName>Utrecht</cbc:CityName><cbc:PostalZone>1234 AB</cbc:PostalZone><cac:Country><cbc:IdentificationCode>NL</cbc:IdentificationCode></cac:Country></cac:PostalAddress>
  <cac:PartyTaxScheme><cbc:CompanyID>NL 0012.34.567.B01</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>
  <cac:PartyLegalEntity><cbc:RegistrationName>Example Supplier B.V.</cbc:RegistrationName><cbc:CompanyID schemeID="0106">12345678</cbc:CompanyID></cac:PartyLegalEntity>
 </cac:Party></cac:AccountingSupplierParty>
 <cac:AccountingCustomerParty><cac:Party><cbc:EndpointID schemeID="0106">87654321</cbc:EndpointID><cac:PartyLegalEntity><cbc:RegistrationName>Example Customer</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty>
 <cac:PaymentMeans><cbc:PaymentMeansCode>30</cbc:PaymentMeansCode><cac:PayeeFinancialAccount><cbc:ID>NL00 TEST 0000 0000 00</cbc:ID></cac:PayeeFinancialAccount></cac:PaymentMeans>
 '.$extra.'
 <cac:TaxTotal><cbc:TaxAmount currencyID="EUR">23.10</cbc:TaxAmount></cac:TaxTotal>
 <cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="EUR">110.00</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="EUR">110.00</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="EUR">133.10</cbc:TaxInclusiveAmount><cbc:PayableAmount currencyID="EUR">133.10</cbc:PayableAmount></cac:LegalMonetaryTotal>
 <cac:InvoiceLine><cbc:ID>1</cbc:ID><cbc:InvoicedQuantity unitCode="C62">4</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="EUR">100.00</cbc:LineExtensionAmount>
  <cac:Item><cbc:Name>Made-up service</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>21</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>
  <cac:Price><cbc:PriceAmount currencyID="EUR">25.00</cbc:PriceAmount></cac:Price></cac:InvoiceLine>
 <cac:InvoiceLine><cbc:ID>2</cbc:ID><cbc:InvoicedQuantity unitCode="C62">1</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="EUR">10.00</cbc:LineExtensionAmount>
  <cac:Item><cbc:Name>Made-up item</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>21</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>
  <cac:Price><cbc:PriceAmount currencyID="EUR">10.00</cbc:PriceAmount></cac:Price></cac:InvoiceLine>
</Invoice>';
	}

	/**
	 * Supplier, numbers, dates, lines, total, IBAN and PDF are read from the XML
	 *
	 * @return void
	 */
	public function testParseInvoice()
	{
		$d = PeppolBooking::parse($this->invoice());
		$this->assertIsArray($d);
		$this->assertFalse($d['credit']);
		$this->assertSame('INV-001', $d['number']);
		$this->assertSame('2026-10-31', date('Y-m-d', $d['date']));
		$this->assertSame('2026-11-30', date('Y-m-d', $d['due']));
		$this->assertSame('EUR', $d['currency']);
		$this->assertSame(133.10, $d['total_ttc']);
		$this->assertSame('NL00TEST0000000000', $d['iban']);

		$s = $d['supplier'];
		$this->assertSame('Example Supplier B.V.', $s['name']);
		$this->assertSame('NL001234567B01', $s['vat']);
		$this->assertSame('12345678', $s['kvk']);
		$this->assertSame('0106:12345678', $s['peppolid']);
		$this->assertSame('1234 AB', $s['zip']);
		$this->assertSame('NL', $s['country']);

		$this->assertCount(2, $d['lines']);
		$this->assertSame('Made-up service', $d['lines'][0]['desc']);
		$this->assertEquals(4, $d['lines'][0]['qty']);
		$this->assertEquals(25, $d['lines'][0]['pu_ht']);
		$this->assertEquals(21, $d['lines'][0]['vat_rate']);
		$total = 0;
		foreach ($d['lines'] as $l) {
			$total += $l['qty'] * $l['pu_ht'] * (1 + $l['vat_rate'] / 100);
		}
		$this->assertEqualsWithDelta($d['total_ttc'], $total, 0.001);

		$this->assertSame('INV-001.pdf', $d['pdf']['name']);
		$this->assertStringStartsWith('%PDF', $d['pdf']['content']);
	}

	/**
	 * A discount on the whole invoice becomes a negative line
	 *
	 * @return void
	 */
	public function testDocumentAllowance()
	{
		$allowance = '<cac:AllowanceCharge><cbc:ChargeIndicator>false</cbc:ChargeIndicator><cbc:AllowanceChargeReason>Discount</cbc:AllowanceChargeReason><cbc:Amount currencyID="EUR">10.00</cbc:Amount><cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>21</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:AllowanceCharge>';
		$d = PeppolBooking::parse($this->invoice('380', $allowance));
		$this->assertCount(3, $d['lines']);
		$this->assertSame('Discount', $d['lines'][2]['desc']);
		$this->assertEquals(-10, $d['lines'][2]['pu_ht']);
	}

	/**
	 * A credit note is recognised (and not booked as an invoice); other XML is refused
	 *
	 * @return void
	 */
	public function testCreditNoteAndOtherXml()
	{
		// Since 2.13.0: a credit note is read too, with the lines and the number of the credited invoice
		$d = PeppolBooking::parse($this->invoice('381', '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>INV-000</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>'));
		$this->assertTrue($d['credit']);
		$this->assertSame('INV-000', $d['source']);
		$this->assertCount(2, $d['lines']);
		$this->assertEqualsWithDelta(25.0, $d['lines'][0]['pu_ht'], 0.001);
		$this->assertSame('', PeppolBooking::parse($this->invoice())['source']);
		$cn = strtr($this->invoice('381'), array(
			'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"' => '<CreditNote xmlns="urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2"',
			'</Invoice>' => '</CreditNote>', 'InvoiceTypeCode' => 'CreditNoteTypeCode', 'InvoiceLine' => 'CreditNoteLine', 'InvoicedQuantity' => 'CreditedQuantity',
		));
		$d = PeppolBooking::parse($cn);
		$this->assertTrue($d['credit']);
		$this->assertCount(2, $d['lines']);
		$this->assertSame('INV-001', $d['number']);

		$this->assertNull(PeppolBooking::parse(''));
		$this->assertNull(PeppolBooking::parse('<Order/>'));
		$this->assertNull(PeppolBooking::parse('not xml'));
		$this->assertNull(PeppolBooking::parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "x">]><Invoice/>'));
	}

	/**
	 * VAT numbers are compared without spaces, dots and dashes
	 *
	 * @return void
	 */
	public function testNormalizeVat()
	{
		$this->assertSame('NL001234567B01', PeppolBooking::normalizeVat('nl 0012.34.567-B01'));
		$this->assertSame('', PeppolBooking::normalizeVat(''));
	}

	/**
	 * Booking with a stubbed access point, and an incoming document from a made-up XML file
	 *
	 * @param string $file XML file
	 * @return array{0:PeppolBooking,1:PeppolBookingFakeImport}
	 */
	private function rejectSetup(&$file)
	{
		global $langs;
		$langs->load('peppol@peppolpeppyrus');
		$file = (string) tempnam(sys_get_temp_dir(), 'peppoltest');
		file_put_contents($file, $this->invoice());
		$booking = new PeppolBooking(null);
		$booking->apFactory = array('PeppolBookingFakeAP', 'make');
		$pi = new PeppolBookingFakeImport();
		$pi->file = $file;
		PeppolBookingFakeAP::$ret = 1;
		PeppolBookingFakeAP::$sent = array();
		return array($booking, $pi);
	}

	/**
	 * Rejecting: rights, reason, already booked, and what happens with each answer of the access point (2.13.0)
	 *
	 * @return void
	 */
	public function testReject()
	{
		$file = '';
		list($booking, $pi) = $this->rejectSetup($file);
		$user = new PeppolBookingFakeUser();

		// Sent: the supplier gets RE with the reason and the invoice number and date of the XML
		$this->assertSame(1, $booking->reject($pi, $user, 'PRI', 'made-up remark'));
		$this->assertCount(1, PeppolBookingFakeAP::$sent);
		$this->assertSame('RE', PeppolBookingFakeAP::$sent[0]['code']);
		$this->assertSame('PRI', PeppolBookingFakeAP::$sent[0]['reason']);
		$this->assertSame('INV-001', PeppolBookingFakeAP::$sent[0]['invoiceId']);
		$this->assertSame('2026-10-31', PeppolBookingFakeAP::$sent[0]['invoiceDate']);
		$this->assertSame('380', PeppolBookingFakeAP::$sent[0]['typeCode']);
		$this->assertSame('RE', $pi->response_code);
		$this->assertStringContainsString('made-up remark', $pi->response_note);

		// Twice: refused
		$this->assertSame(-3, $booking->reject($pi, $user, 'PRI'));

		// Supplier cannot receive it: only stored here, with a warning
		$pi = new PeppolBookingFakeImport();
		$pi->file = $file;
		PeppolBookingFakeAP::$ret = -5;
		$this->assertSame(2, $booking->reject($pi, $user, 'QTY'));
		$this->assertSame('RE', $pi->response_code);
		$this->assertCount(1, $booking->warnings);

		// Error of the access point: nothing changes, can be tried again
		$pi = new PeppolBookingFakeImport();
		$pi->file = $file;
		PeppolBookingFakeAP::$ret = -100;
		$this->assertSame(-6, $booking->reject($pi, $user, 'QTY'));
		$this->assertSame('', $pi->response_code);

		// "Other reason" needs an explanation; unknown reason refused; no right; already booked
		PeppolBookingFakeAP::$ret = 1;
		$this->assertSame(-4, $booking->reject($pi, $user, 'OTH', ' '));
		$this->assertSame(-4, $booking->reject($pi, $user, 'XYZ', 'text'));
		$user->allowed = false;
		$this->assertSame(-1, $booking->reject($pi, $user, 'PRI'));
		$user->allowed = true;
		$pi->fk_invoice = 12;
		$this->assertSame(-2, $booking->reject($pi, $user, 'PRI'));
		unlink($file);
	}

	/**
	 * Approval after booking: only with the setting on, stored on success, a warning on failure (2.13.0)
	 *
	 * @return void
	 */
	public function testSendApproval()
	{
		global $conf;
		$file = '';
		list($booking, $pi) = $this->rejectSetup($file);
		$user = new PeppolBookingFakeUser();
		$data = PeppolBooking::parse($this->invoice());
		$send = new ReflectionMethod('PeppolBooking', 'sendApproval');
		$send->setAccessible(true);

		$conf->global->PEPPOL_IR_SEND_APPROVAL = '0';
		$send->invoke($booking, $pi, $user, $data);
		$this->assertCount(0, PeppolBookingFakeAP::$sent);

		$conf->global->PEPPOL_IR_SEND_APPROVAL = '1';
		$send->invoke($booking, $pi, $user, $data);
		$this->assertCount(1, PeppolBookingFakeAP::$sent);
		$this->assertSame('AP', PeppolBookingFakeAP::$sent[0]['code']);
		$this->assertSame('AP', $pi->response_code);
		$this->assertCount(1, $booking->notices);

		// Already approved: not again
		$send->invoke($booking, $pi, $user, $data);
		$this->assertCount(1, PeppolBookingFakeAP::$sent);

		// Failure: only a warning
		$pi = new PeppolBookingFakeImport();
		PeppolBookingFakeAP::$ret = -5;
		$send->invoke($booking, $pi, $user, $data);
		$this->assertSame('', $pi->response_code);
		$this->assertCount(1, $booking->warnings);
		unset($conf->global->PEPPOL_IR_SEND_APPROVAL);
		unlink($file);
	}
}
