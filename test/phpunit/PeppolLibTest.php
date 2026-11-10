<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Tests of lib/peppol.lib.php (made-up data only).
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests of the search, matching and request helpers of the module
 */
class PeppolLibTest extends TestCase
{
	/**
	 * Document type of a Peppol BIS Billing 3.0 invoice, as the directory lists it
	 */
	const INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';

	/**
	 * A made-up third party
	 *
	 * @param string $vat     VAT number
	 * @param string $profid1 Professional ID 1 (KvK number in the Netherlands)
	 * @param string $name    Name
	 * @return stdClass
	 */
	private function thirdparty($vat, $profid1, $name)
	{
		$soc = new stdClass();
		$soc->tva_intra = $vat;
		$soc->idprof1 = $profid1;
		$soc->idprof2 = '';
		$soc->idprof3 = '';
		$soc->idprof4 = '';
		$soc->idprof5 = '';
		$soc->idprof6 = '';
		$soc->name = $name;
		$soc->country_code = 'NL';
		return $soc;
	}

	/**
	 * A directory match as the Peppol directory returns it
	 *
	 * @param string   $id       Participant ID, e.g. 0106:12345678
	 * @param string[] $doctypes Document types
	 * @return array<string,mixed>
	 */
	private function match($id, $doctypes)
	{
		$m = array(
			'participantID' => array('scheme' => 'iso6523-actorid-upis', 'value' => $id),
			'entities' => array(array('name' => array(array('name' => 'Example Company B.V.')), 'countryCode' => 'NL', 'geoInfo' => 'Utrecht', 'regDate' => '2024-05-01')),
		);
		if ($doctypes !== null) {
			$m['docTypes'] = array();
			foreach ($doctypes as $d) {
				$m['docTypes'][] = array('scheme' => 'busdox-docid-qns', 'value' => $d);
			}
		}
		return $m;
	}

	/**
	 * Search terms: VAT number, then professional ID 1, then name; normalised, without duplicates or short numbers
	 *
	 * @return void
	 */
	public function testDirectorySearchCandidates()
	{
		$this->assertSame(
			array('vat' => 'NL001234567B01', 'profid1' => '12345678', 'name' => 'Example Company B.V.'),
			peppolDirectorySearchCandidates($this->thirdparty('NL 0012.34.567.B01', '1234 5678', 'Example  Company B.V.'))
		);
		$this->assertSame(array('name' => 'Only A Name'), peppolDirectorySearchCandidates($this->thirdparty('', '', 'Only A Name')));
		$this->assertSame(array('name' => 'Short'), peppolDirectorySearchCandidates($this->thirdparty('', '12', 'Short')));
		$this->assertSame(array('vat' => 'BE0123456789', 'name' => 'Same'), peppolDirectorySearchCandidates($this->thirdparty('BE0123456789', 'BE0123456789', 'Same')));
	}

	/**
	 * A directory match: scheme and value, invoice support and the matching field of the third party
	 *
	 * @return void
	 */
	public function testDirectoryMatchInfo()
	{
		$soc = $this->thirdparty('NL001234567B01', '12345678', 'Example Company B.V.');

		$info = peppolDirectoryMatchInfo($this->match('0106:12345678', array(self::INVOICE)), $soc);
		$this->assertSame('0106:12345678', $info['participantId']);
		$this->assertSame('0106', $info['icd']);
		$this->assertSame('12345678', $info['value']);
		$this->assertTrue($info['acceptsInvoice']);
		$this->assertSame('idprof1', $info['matchedField']);
		$this->assertSame('Example Company B.V.', $info['name']);
		$this->assertSame('2024-05-01', $info['regDate']);

		$info = peppolDirectoryMatchInfo($this->match('9944:nl001234567b01', array('urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2::CreditNote##x')), $soc);
		$this->assertFalse($info['acceptsInvoice']);
		$this->assertSame('tva_intra', $info['matchedField']);

		$info = peppolDirectoryMatchInfo($this->match('0106:99999999', null), $soc);
		$this->assertNull($info['acceptsInvoice']);
		$this->assertSame('', $info['matchedField']);

		$this->assertSame('', peppolDirectoryMatchInfo('not an array')['participantId']);
	}

	/**
	 * The kind of number behind a scheme code
	 *
	 * @return void
	 */
	public function testSchemeLabel()
	{
		global $langs;
		$langs->load('peppol@peppolpeppyrus');
		$this->assertStringContainsString('KvK', peppolSchemeLabel('0106'));
		$this->assertStringContainsString('NL', peppolSchemeLabel('9944'));
		$this->assertSame('', peppolSchemeLabel('1234'));
		$this->assertSame('', peppolSchemeLabel('abc'));
	}

	/**
	 * The way back after the Peppol ID search is built from a fixed list, never from the request
	 *
	 * @return void
	 */
	public function testThirdpartyBackUrl()
	{
		$this->assertSame(DOL_URL_ROOT.'/societe/card.php?socid=12', peppolThirdpartyBackUrl(12, 'card'));
		$this->assertSame(DOL_URL_ROOT.'/comm/card.php?socid=12', peppolThirdpartyBackUrl('12', 'comm'));
		$this->assertSame(DOL_URL_ROOT.'/fourn/card.php?socid=12', peppolThirdpartyBackUrl(12, 'fourn'));
		$this->assertSame(DOL_URL_ROOT.'/societe/card.php?socid=12', peppolThirdpartyBackUrl(12, 'https://example.invalid/'));
		$this->assertSame('', peppolThirdpartyBackUrl(0, 'card'));
	}

	/**
	 * Requests only go over HTTPS to the Peppyrus API (all methods) or the Peppol directory (GET); nothing else is sent
	 *
	 * @return void
	 */
	public function testApiRequestRefusesOtherHosts()
	{
		foreach (array(
			array('https://example.invalid/v1/', 'GET'),
			array('http://api.peppyrus.be/v1/', 'GET'),
			array('https://api.peppyrus.be:8443/v1/', 'GET'),
			array('https://user@api.peppyrus.be/v1/', 'GET'),
			array('https://directory.peppol.eu/search/1.0/json', 'POST'),
			array('https://api.peppyrus.be/v1/', 'TRACE'),
		) as $case) {
			$result = peppolApiRequest($case[0], $case[1], '', array());
			$this->assertSame(-1, $result['curl_error_no'], $case[1].' '.$case[0]);
			$this->assertSame(0, $result['http_code']);
		}
	}

	/**
	 * The booking link is only there with the module enabled; without it (as in the tests) it is empty
	 *
	 * @return void
	 */
	public function testBookingUrlNeedsModule()
	{
		global $conf;
		$conf->modules = array();
		$doc = new stdClass();
		$doc->id = 5;
		$doc->fk_invoice = 0;
		$this->assertSame('', peppolBookingUrl($doc, null));
		$this->assertSame('', peppolBookingUrl(null, null));
	}

	/**
	 * The About page shows the most recent versions of the changelog, without the title of the file
	 *
	 * @return void
	 */
	public function testChangelogExcerpt()
	{
		$md = "# Changelog\n\n## 3.0\n\n- C\n\n## 2.0\n\n- B\n\n## 1.0\n\n- A\n";
		$out = peppolChangelogExcerpt($md, 2);
		$this->assertStringContainsString('## 3.0', $out);
		$this->assertStringContainsString('## 2.0', $out);
		$this->assertStringNotContainsString('## 1.0', $out);
		$this->assertStringNotContainsString('# Changelog', $out);
		$this->assertStringContainsString('## 1.0', peppolChangelogExcerpt($md, 10));
		$this->assertSame('', peppolChangelogExcerpt('', 5));
	}

	/**
	 * Dolibarr older than 22 gets a notice; 22 and newer not
	 *
	 * @return void
	 */
	public function testDolibarrBelowRecommended()
	{
		$this->assertTrue(peppolDolibarrBelowRecommended('18.0.4'));
		$this->assertTrue(peppolDolibarrBelowRecommended('21.0.3'));
		$this->assertFalse(peppolDolibarrBelowRecommended('22.0.0'));
		$this->assertFalse(peppolDolibarrBelowRecommended('24.0.2'));
	}

	/**
	 * Through Peppol: validated and paid documents (also a credit note that was used), no draft or abandoned
	 *
	 * @return void
	 */
	public function testInvoiceStatusEligible()
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$inv = new stdClass();
		foreach (array(Facture::STATUS_VALIDATED => true, Facture::STATUS_CLOSED => true, Facture::STATUS_DRAFT => false, Facture::STATUS_ABANDONED => false) as $status => $expected) {
			$inv->status = $status;
			$this->assertSame($expected, peppolIsInvoiceStatusEligible($inv), 'status '.$status);
		}
		$this->assertFalse(peppolIsInvoiceStatusEligible(null));
	}

	/**
	 * Identifiers from the access point: only letters, digits and . _ : @ -
	 *
	 * @return void
	 */
	public function testSanitizeIdentifier()
	{
		$this->assertSame('0106:12345678', peppolSanitizeIdentifier('0106:12345678'));
		$this->assertSame('abc-123_x.y@z', peppolSanitizeIdentifier('abc-123_x.y@z'));
		$this->assertSame('scriptalert1script', peppolSanitizeIdentifier('<script>alert(1)</script>'));
		$this->assertSame('', peppolSanitizeIdentifier(array('x')));
	}

	/**
	 * No access without user or invoice (the rest needs the database)
	 *
	 * @return void
	 */
	public function testUserCanReadInvoiceWithoutObjects()
	{
		$this->assertFalse(peppolUserCanReadInvoice(null, null));
		$inv = new stdClass();
		$inv->id = 0;
		$this->assertFalse(peppolUserCanReadInvoice(new stdClass(), $inv));
	}

	/**
	 * Peppyrus report, failed deliveries and the signal for the plan (since 2.12.0)
	 *
	 * @return void
	 */
	public function testReportFailedLicense()
	{
		$r = peppolReportRules(array('validationRules' => array(array('id' => 'BR-01', 'type' => 'fatal', 'location' => '/Invoice', 'message' => 'x'), array('id' => 'W1', 'type' => 'WARNING'), 'onzin'), 'transmissionRules' => 'AS4 ok'));
		$this->assertSame(1, $r['fatal']);
		$this->assertSame(1, $r['warnings']);
		$this->assertCount(2, $r['rules']);
		$this->assertSame('FATAL', $r['rules'][0]['type']);
		$this->assertSame('AS4 ok', $r['transport']);
		$this->assertSame(array('rules' => array(), 'transport' => '', 'fatal' => 0, 'warnings' => 0), peppolReportRules(-404));

		$failed = array(array('id' => 'a', 'created' => '2026-11-10'), array('id' => 'b', 'created' => ''), array('id' => 'c', 'created' => ''), array('id' => 'a2', 'created' => ''));
		$invoices = array('a' => 10, 'b' => 11, 'a2' => 10);
		$toReport = peppolFailedToReport($failed, $invoices, array(11 => true));
		// c is not an own invoice, b is already reported, invoice 10 only once
		$this->assertSame(array(array('invoice' => 10, 'id' => 'a', 'created' => '2026-11-10')), $toReport);

		$this->assertSame('', peppolLicenseSignal(array('messagesSent' => 500, 'messagesLimit' => null)));
		$this->assertSame('limit', peppolLicenseSignal(array('messagesSent' => 100, 'messagesLimit' => 100)));
		$this->assertSame('near', peppolLicenseSignal(array('messagesSent' => 90, 'messagesLimit' => 100)));
		$this->assertSame('', peppolLicenseSignal(array('messagesSent' => 10, 'messagesLimit' => 100)));
	}
}
