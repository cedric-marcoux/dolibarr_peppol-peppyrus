<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    peppolpeppyrus/class/peppolbooking.class.php
 * \ingroup peppol
 * \brief   Book an incoming e-invoice (UBL, Peppol BIS Billing 3.0) as supplier invoice
 *
 * Reads the XML of an incoming document, finds the supplier (Peppol ID, VAT number, registration number) or
 * creates it, creates the supplier invoice with its lines, checks the total against the total in the XML,
 * validates the invoice and attaches the PDF and the XML. Nothing happens without a user action: this is
 * called from the button "Book" only, never from a scheduled job.
 *
 * Log lines hold ids and counts only: no names, no VAT or registration numbers, no amounts.
 */

dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
if (!class_exists('Einvoicing\Readers\UblReader', true)) {
	require_once __DIR__ . '/../vendor/scoper-autoload.php';
}

/**
 * Booking of incoming documents as supplier invoices
 */
class PeppolBooking
{
	/** Difference allowed between the total of the invoice and the total in the XML (rounding per line) */
	const TOTAL_TOLERANCE = 0.02;

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] Messages for the user (the invoice was made, but look at this) */
	public $warnings = array();

	/** @var int Id of the supplier invoice made or found */
	public $invoiceId = 0;

	/** @var bool The supplier was created from the XML */
	public $supplierCreated = false;

	/** @var bool The invoice was validated */
	public $validated = false;

	/** @var string[] Messages that something succeeded, such as the sent approval (since 2.13.0) */
	public $notices = array();

	/** @var callable Function that returns the access point (since 2.13.0; replaceable in tests) */
	public $apFactory = 'peppolGetAPObject';

	/**
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Read an e-invoice: the data needed for a supplier invoice
	 *
	 * @param	string	$xml	UBL invoice or credit note
	 * @return	array<string,mixed>|null	Data, or null when it is not an e-invoice that can be read
	 */
	public static function parse($xml)
	{
		$xml = (string) $xml;
		if ($xml === '' || strpos($xml, '<!DOCTYPE') !== false) {
			return null; // no document type declarations (entities) in an invoice
		}
		$dom = new DOMDocument();
		if (!@$dom->loadXML($xml, LIBXML_NONET)) {
			return null;
		}
		$rootName = $dom->documentElement ? $dom->documentElement->localName : '';
		if ($rootName !== 'Invoice' && $rootName !== 'CreditNote') {
			return null;
		}
		$xp = new DOMXPath($dom);
		$xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
		$xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
		$text = function ($query) use ($xp) {
			$n = $xp->query($query);
			return ($n !== false && $n->length) ? trim((string) $n->item(0)->textContent) : '';
		};

		$typeCode = $text('/*/cbc:InvoiceTypeCode | /*/cbc:CreditNoteTypeCode');
		$data = array(
			'credit' => ($rootName === 'CreditNote' || $typeCode === '381'),
			'number' => $text('/*/cbc:ID'),
			'date' => self::date($text('/*/cbc:IssueDate')),
			'due' => self::date($text('/*/cbc:DueDate | /*/cac:PaymentMeans/cbc:PaymentDueDate')),
			'currency' => strtoupper($text('/*/cbc:DocumentCurrencyCode')),
			'total_ttc' => self::amount($text('/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount')),
			'iban' => strtoupper(preg_replace('/\s+/', '', $text('/*/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'))),
			'supplier' => array(),
			'lines' => array(),
			'pdf' => null,
			// Since 2.13.0: for a credit note the number of the invoice that is credited
			'source' => $text('/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'),
		);

		// Supplier
		$p = '/*/cac:AccountingSupplierParty/cac:Party';
		$endpointScheme = $text($p . '/cbc:EndpointID/@schemeID');
		$endpoint = $text($p . '/cbc:EndpointID');
		$kvk = '';
		foreach (array($p . '/cbc:EndpointID', $p . '/cac:PartyLegalEntity/cbc:CompanyID', $p . '/cac:PartyIdentification/cbc:ID') as $q) {
			$nodes = $xp->query($q);
			foreach (($nodes ? $nodes : array()) as $node) {
				if ($node instanceof DOMElement && $node->getAttribute('schemeID') === '0106') {
					$digits = preg_replace('/\D/', '', (string) $node->textContent);
					if (strlen($digits) === 8) {
						$kvk = $digits;
						break 2;
					}
				}
			}
		}
		$data['supplier'] = array(
			'name' => $text($p . '/cac:PartyLegalEntity/cbc:RegistrationName') !== '' ? $text($p . '/cac:PartyLegalEntity/cbc:RegistrationName') : $text($p . '/cac:PartyName/cbc:Name'),
			'vat' => self::normalizeVat($text($p . '/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT"]/cbc:CompanyID') !== '' ? $text($p . '/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID="VAT"]/cbc:CompanyID') : $text($p . '/cac:PartyTaxScheme/cbc:CompanyID')),
			'kvk' => $kvk,
			'peppolid' => ($endpointScheme !== '' && $endpoint !== '') ? $endpointScheme . ':' . $endpoint : '',
			'address' => trim($text($p . '/cac:PostalAddress/cbc:StreetName') . ' ' . $text($p . '/cac:PostalAddress/cbc:AdditionalStreetName')),
			'zip' => $text($p . '/cac:PostalAddress/cbc:PostalZone'),
			'town' => $text($p . '/cac:PostalAddress/cbc:CityName'),
			'country' => strtoupper($text($p . '/cac:PostalAddress/cac:Country/cbc:IdentificationCode')),
			'email' => $text($p . '/cac:Contact/cbc:ElectronicMail'),
			'phone' => $text($p . '/cac:Contact/cbc:Telephone'),
		);

		// Lines and document allowances and charges, through the einvoicing reader (also computes line amounts). Since
		// 2.13.0 also for a credit note: amounts positive, Dolibarr makes the prices of a credit note negative itself.
		try {
			$reader = new \Einvoicing\Readers\UblReader();
			$invoice = $reader->import($xml);
			foreach ($invoice->getLines() as $line) {
				$qty = (float) $line->getQuantity();
				$net = (float) $line->getNetAmount();
				if ($qty == 0) {
					$qty = 1;
				}
				$desc = trim((string) $line->getName());
				$more = trim((string) $line->getDescription());
				if ($more !== '' && $more !== $desc) {
					$desc .= ($desc !== '' ? "\n" : '') . $more;
				}
				$data['lines'][] = array('desc' => $desc, 'qty' => $qty, 'pu_ht' => $net / $qty, 'vat_rate' => (float) $line->getVatRate());
			}
			foreach (array(-1 => $invoice->getAllowances(), 1 => $invoice->getCharges()) as $sign => $list) {
				foreach ($list as $ac) {
					$amount = $ac->isPercentage() ? $ac->getEffectiveAmount((float) $invoice->getTotals()->netAmount) : $ac->getAmount();
					$data['lines'][] = array('desc' => trim((string) $ac->getReason()), 'qty' => 1, 'pu_ht' => $sign * (float) $amount, 'vat_rate' => (float) $ac->getVatRate());
				}
			}
		} catch (\Throwable $e) {
			dol_syslog('PeppolBooking::parse lines could not be read: ' . get_class($e), LOG_WARNING);
			$data['lines'] = array();
		}

		// The PDF of the invoice
		$objects = $xp->query('/*/cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject');
		foreach (($objects ? $objects : array()) as $obj) {
			if (!$obj instanceof DOMElement) {
				continue;
			}
			$name = (string) $obj->getAttribute('filename');
			if (strtolower((string) $obj->getAttribute('mimeCode')) !== 'application/pdf' && !preg_match('/\.pdf$/i', $name)) {
				continue;
			}
			$content = base64_decode(preg_replace('/\s+/', '', (string) $obj->textContent), true);
			if ($content !== false && strncmp($content, '%PDF', 4) === 0) {
				$name = dol_sanitizeFileName($name !== '' ? basename($name) : 'invoice.pdf');
				if (!preg_match('/\.pdf$/i', $name)) {
					$name .= '.pdf';
				}
				$data['pdf'] = array('name' => $name, 'content' => $content);
				break;
			}
		}

		return $data;
	}

	/**
	 * VAT number without spaces, dots and dashes, in capitals
	 *
	 * @param	string	$vat	VAT number
	 * @return	string
	 */
	public static function normalizeVat($vat)
	{
		return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $vat));
	}

	/**
	 * Date YYYY-MM-DD as timestamp (noon, so that time zones do not move the day), or null
	 *
	 * @param	string	$s	Date
	 * @return	int|null
	 */
	private static function date($s)
	{
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $s, $m)) {
			return null;
		}
		return dol_mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
	}

	/**
	 * Amount from the XML, or null
	 *
	 * @param	string	$s	Amount
	 * @return	float|null
	 */
	private static function amount($s)
	{
		return is_numeric($s) ? (float) $s : null;
	}

	/**
	 * Find the supplier of the invoice: Peppol ID, then VAT number, then registration number (KvK).
	 * Only an unambiguous match counts.
	 *
	 * @param	array<string,string>	$supplier	Supplier data of parse()
	 * @return	int		Id of the third party, 0 when not found, -1 when several third parties match
	 */
	public function findSupplier($supplier)
	{
		$tries = array();
		if (!empty($supplier['peppolid'])) {
			$tries[] = "LOWER(se.peppol_id) = '" . $this->db->escape(strtolower($supplier['peppolid'])) . "'";
		}
		if (!empty($supplier['vat'])) {
			$tries[] = "UPPER(REPLACE(REPLACE(REPLACE(s.tva_intra, ' ', ''), '.', ''), '-', '')) = '" . $this->db->escape($supplier['vat']) . "'";
		}
		if (!empty($supplier['kvk'])) {
			$tries[] = "REPLACE(REPLACE(s.siren, ' ', ''), '.', '') = '" . $this->db->escape($supplier['kvk']) . "'";
		}
		foreach ($tries as $where) {
			$sql = "SELECT s.rowid, s.fournisseur FROM " . MAIN_DB_PREFIX . "societe as s";
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe_extrafields as se ON se.fk_object = s.rowid";
			$sql .= " WHERE s.entity IN (" . getEntity('societe') . ") AND s.status = 1 AND " . $where;
			$sql .= " ORDER BY s.fournisseur DESC, s.rowid";
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$all = array();
			$suppliers = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$all[] = (int) $obj->rowid;
				if ((int) $obj->fournisseur === 1) {
					$suppliers[] = (int) $obj->rowid;
				}
			}
			$this->db->free($resql);
			if (count($suppliers) === 1) {
				return $suppliers[0];
			}
			if (count($suppliers) === 0 && count($all) === 1) {
				return $all[0];
			}
			if (count($all) > 1) {
				return -1;
			}
		}
		return 0;
	}

	/**
	 * Create the supplier from the data of the XML
	 *
	 * @param	array<string,string>	$supplier	Supplier data of parse()
	 * @param	User					$user		User
	 * @return	int		Id of the new third party, <0 on error
	 */
	public function createSupplier($supplier, $user)
	{
		global $langs;

		if (empty($supplier['name'])) {
			$this->error = $langs->trans('peppolBookNoSupplierName');
			return -1;
		}
		$soc = new Societe($this->db);
		$soc->name = $supplier['name'];
		$soc->fournisseur = 1;
		$soc->client = 0;
		$soc->code_fournisseur = 'auto'; // automatic code when the numbering module makes one
		$soc->address = $supplier['address'];
		$soc->zip = $supplier['zip'];
		$soc->town = $supplier['town'];
		$soc->email = $supplier['email'];
		$soc->phone = $supplier['phone'];
		$soc->tva_intra = $supplier['vat'];
		$soc->tva_assuj = 1;
		if (!empty($supplier['country'])) {
			$soc->country_id = (int) dol_getIdFromCode($this->db, $supplier['country'], 'c_country', 'code', 'rowid');
			$soc->country_code = $supplier['country'];
		}
		if (!empty($supplier['kvk']) && ($supplier['country'] === 'NL' || $supplier['country'] === '')) {
			$soc->idprof1 = $supplier['kvk'];
		}
		if (!empty($supplier['peppolid'])) {
			$soc->array_options['options_peppol_id'] = $supplier['peppolid'];
		}
		$id = $soc->create($user);
		if ($id <= 0) {
			$this->error = $soc->error ? $soc->error : implode(', ', $soc->errors);
			return -1;
		}
		$this->supplierCreated = true;
		return (int) $id;
	}

	/**
	 * Book an incoming document: supplier invoice with lines, validated when the total matches, PDF and XML attached
	 *
	 * @param	object	$pi		Peppolimport (fetched)
	 * @param	User	$user	User (needs the rights to import Peppol documents and to create supplier invoices)
	 * @return	int		Id of the supplier invoice, <0 on error ($this->error)
	 */
	public function book($pi, $user)
	{
		global $conf, $langs;

		$langs->loadLangs(array('peppol@peppolpeppyrus', 'bills', 'companies'));
		$this->warnings = array();
		$this->notices = array();
		$this->invoiceId = 0;
		$this->supplierCreated = false;
		$this->validated = false;

		if (!$user->hasRight('peppolpeppyrus', 'import') || !$user->hasRight('fournisseur', 'facture', 'creer')) {
			$this->error = $langs->trans('peppolBookNoRight');
			return -1;
		}
		if (!empty($pi->fk_invoice)) {
			$this->invoiceId = (int) $pi->fk_invoice;
			$this->error = $langs->trans('peppolBookAlreadyBooked');
			return -2;
		}
		// Since 2.13.0: rejected to the supplier, then no longer booked
		if ((string) ($pi->response_code ?? '') === 'RE') {
			$this->error = $langs->trans('peppolBookRejected');
			return -14;
		}
		$file = $pi->fullFilename();
		if ($file === '' || !dol_is_file($file)) {
			$this->error = $langs->trans('peppolBookNoFile');
			return -3;
		}
		$data = self::parse((string) file_get_contents($file));
		if ($data === null) {
			$this->error = $langs->trans('peppolBookNotAnInvoice');
			return -4;
		}
		if (!empty($data['currency']) && $data['currency'] !== $conf->currency) {
			$this->error = $langs->trans('peppolBookOtherCurrency', $data['currency']);
			return -6;
		}
		if ($data['number'] === '' || $data['date'] === null || !count($data['lines'])) {
			$this->error = $langs->trans('peppolBookIncomplete');
			return -7;
		}

		// Supplier
		$socid = $this->findSupplier($data['supplier']);
		if ($socid < 0) {
			$this->error = $this->error !== '' ? $this->error : $langs->trans('peppolBookSeveralSuppliers');
			return -8;
		}
		if ($socid === 0) {
			// Setup: create unknown suppliers from the e-invoice (default) or not
			if (!getDolGlobalInt('PEPPOL_BOOK_CREATE_SUPPLIER', 1)) {
				$this->error = $langs->trans('peppolBookSupplierUnknownNoCreate');
				return -9;
			}
			if (!$user->hasRight('societe', 'creer')) {
				$this->error = $langs->trans('peppolBookSupplierUnknown');
				return -9;
			}
			$socid = $this->createSupplier($data['supplier'], $user);
			if ($socid < 0) {
				return -10;
			}
		}
		$soc = new Societe($this->db);
		$soc->fetch($socid);
		if ((int) $soc->fournisseur !== 1 || (empty($soc->array_options['options_peppol_id']) && $data['supplier']['peppolid'] !== '')) {
			// Known third party: make it a supplier and remember its Peppol ID, with the right to change third parties
			// and when the setup allows changing third parties (default)
			if ($user->hasRight('societe', 'creer') && getDolGlobalInt('PEPPOL_BOOK_UPDATE_SUPPLIER', 1)) {
				if ((int) $soc->fournisseur !== 1) {
					$soc->fournisseur = 1;
					$soc->code_fournisseur = 'auto';
					$soc->update($soc->id, $user, 1, 0, 1);
				}
				if (empty($soc->array_options['options_peppol_id']) && $data['supplier']['peppolid'] !== '') {
					$soc->array_options['options_peppol_id'] = $data['supplier']['peppolid'];
					$soc->updateExtraField('peppol_id', null, $user);
				}
			} elseif ((int) $soc->fournisseur !== 1) {
				$this->error = $langs->trans('peppolBookNotASupplier');
				return -11;
			}
		}

		// Since 2.13.0: a credit note belongs to the supplier invoice that is credited (same supplier, number of the
		// credit note). Without that invoice only when Dolibarr allows standalone credit notes.
		$sourceId = 0;
		if ($data['credit']) {
			$sourceId = $data['source'] !== '' ? $this->findInvoice($socid, $data['source']) : 0;
			if ($sourceId <= 0 && !getDolGlobalString('INVOICE_CREDIT_NOTE_STANDALONE')) {
				$this->error = $langs->trans($data['source'] !== '' ? 'peppolBookCreditNoSource' : 'peppolBookCreditNoReference', $data['source']);
				return -5;
			}
		}

		// Already booked by hand (same supplier, same invoice number): link instead of a second invoice
		$existing = $this->findInvoice($socid, $data['number']);
		if ($existing > 0) {
			$this->invoiceId = $existing;
			$this->linkDocument($pi, $user, $existing, $socid);
			$this->warnings[] = $langs->trans('peppolBookLinkedExisting');
			$this->sendApproval($pi, $user, $data);
			return $existing;
		}

		// Supplier invoice with lines
		$this->db->begin();
		$inv = new FactureFournisseur($this->db);
		$inv->socid = $socid;
		$inv->ref_supplier = $data['number'];
		$inv->type = $data['credit'] ? FactureFournisseur::TYPE_CREDIT_NOTE : FactureFournisseur::TYPE_STANDARD;
		if ($sourceId > 0) {
			$inv->fk_facture_source = $sourceId;
		}
		$inv->date = $data['date'];
		if ($data['due'] !== null) {
			$inv->date_echeance = $data['due'];
		}
		$inv->note_private = $langs->trans('peppolBookNote', $pi->ref) . ($data['iban'] !== '' ? "\n" . 'IBAN: ' . $data['iban'] : '');
		$id = $inv->create($user);
		if ($id <= 0) {
			$this->db->rollback();
			$this->error = $inv->error ? $inv->error : implode(', ', $inv->errors);
			return -12;
		}
		foreach ($data['lines'] as $line) {
			$res = $inv->addline($line['desc'], price2num($line['pu_ht'], 'MU'), $line['vat_rate'], 0, 0, $line['qty'], 0, 0, 0, 0, 0, 0, 'HT');
			if ($res < 0) {
				$this->db->rollback();
				$this->error = $inv->error ? $inv->error : implode(', ', $inv->errors);
				return -13;
			}
		}
		$this->db->commit();
		$this->invoiceId = (int) $id;
		$inv->fetch($id);

		// Validate when the setup says so (default), the total is the total of the XML, and the user may validate
		$canValidate = getDolGlobalString('MAIN_USE_ADVANCED_PERMS') ? $user->hasRight('fournisseur', 'supplier_invoice_advance', 'validate') : $user->hasRight('fournisseur', 'facture', 'creer');
		if (getDolGlobalString('PEPPOL_BOOK_MODE', 'validate') === 'draft') {
			dol_syslog('PeppolBooking::book kept as draft by the setup');
		} elseif ($data['total_ttc'] !== null && abs(abs((float) $inv->total_ttc) - abs($data['total_ttc'])) > self::TOTAL_TOLERANCE) {
			$this->warnings[] = $langs->trans('peppolBookTotalDiffers', price($inv->total_ttc), price($data['total_ttc']));
		} elseif (!$canValidate) {
			$this->warnings[] = $langs->trans('peppolBookNoValidateRight');
		} elseif ($inv->validate($user) < 0) {
			$this->warnings[] = $langs->trans('peppolBookValidateFailed', $inv->error ? $inv->error : implode(', ', $inv->errors));
		} else {
			$this->validated = true;
			$inv->fetch($id);
		}

		$this->attachFiles($inv, $pi, $data['pdf']);
		$this->linkDocument($pi, $user, (int) $id, $socid);
		$this->sendApproval($pi, $user, $data);
		dol_syslog('PeppolBooking::book document ' . ((int) $pi->id) . ' booked as supplier invoice ' . ((int) $id) . ', lines ' . count($data['lines']) . ($this->validated ? ', validated' : ', draft') . ($this->supplierCreated ? ', supplier created' : ''));
		return (int) $id;
	}

	/**
	 * Reject an incoming invoice, since 2.13.0: an Invoice Response RE with the reason to the supplier, then the
	 * document is canceled and can no longer be booked. When the supplier cannot receive such a message, the
	 * rejection is only stored here, with a message to inform the supplier yourself. On an error of Peppyrus nothing
	 * changes, so it can be tried again.
	 *
	 * @param	object	$pi			Peppolimport
	 * @param	User	$user		User
	 * @param	string	$reason		Code of peppolRejectReasons()
	 * @param	string	$text		Explanation (required with OTH)
	 * @return	int		1 sent, 2 only stored here, <0 error ($this->error)
	 */
	public function reject($pi, $user, $reason, $text = '')
	{
		global $langs;

		$langs->loadLangs(array('peppol@peppolpeppyrus'));
		$this->warnings = array();
		$this->notices = array();
		$this->error = '';
		$text = dol_trunc(trim((string) $text), 200, 'right', 'UTF-8', 1);
		if (!$user->hasRight('peppolpeppyrus', 'import') || !$user->hasRight('fournisseur', 'facture', 'creer')) {
			$this->error = $langs->trans('peppolBookNoRight');
			return -1;
		}
		if (!empty($pi->fk_invoice)) {
			$this->error = $langs->trans('peppolRejectAlreadyBooked');
			return -2;
		}
		if ((string) ($pi->response_code ?? '') === 'RE') {
			$this->error = $langs->trans('peppolRejectAlready');
			return -3;
		}
		if (!isset(peppolRejectReasons()[(string) $reason]) || ($reason === 'OTH' && $text === '')) {
			$this->error = $langs->trans('peppolRejectReasonMissing');
			return -4;
		}
		$note = $langs->transnoentitiesnoconv(peppolRejectReasons()[(string) $reason]) . ($text !== '' ? ': ' . $text : '');

		$file = method_exists($pi, 'fullFilename') ? $pi->fullFilename() : '';
		$data = ($file !== '' && dol_is_file($file)) ? self::parse((string) file_get_contents($file)) : null;
		$res = -5;
		$ap = null;
		if ($data !== null && $data['number'] !== '' && $data['date'] !== null) {
			$ap = call_user_func($this->apFactory);
			if (is_object($ap) && method_exists($ap, 'sendInvoiceResponse')) {
				$res = $ap->sendInvoiceResponse($pi, array(
					'code' => 'RE',
					'reason' => (string) $reason,
					'reasonText' => $text,
					'invoiceId' => (string) $data['number'],
					'invoiceDate' => dol_print_date((int) $data['date'], '%Y-%m-%d'),
					'typeCode' => $data['credit'] ? '381' : '380',
					'receiverName' => (string) ($data['supplier']['name'] ?? ''),
				));
			}
		}
		if ($res <= 0 && $res !== -5) {
			$this->error = $langs->trans('peppolRejectSendFailed', is_object($ap) ? (string) $ap->error : '');
			return -6;
		}
		if ($pi->saveResponse($user, 'RE', $note) < 0) {
			$this->error = (string) $pi->error;
			return -7;
		}
		if ($res > 0) {
			$this->notices[] = $langs->trans('peppolRejectSent');
			return 1;
		}
		$this->warnings[] = $langs->trans('peppolRejectLocalOnly');
		return 2;
	}

	/**
	 * Approval (Invoice Response AP) to the supplier after booking, since 2.13.0. Only when the setting is on
	 * (default on) and the supplier can receive such a message. When it fails, only a message: the invoice is
	 * booked anyway.
	 *
	 * @param	object	$pi		Peppolimport
	 * @param	User	$user	User
	 * @param	array<string,mixed>	$data	Result of parse()
	 * @return	void
	 */
	private function sendApproval($pi, $user, $data)
	{
		global $langs;

		if (!getDolGlobalInt('PEPPOL_IR_SEND_APPROVAL', 1) || (string) ($pi->response_code ?? '') === 'AP' || empty($pi->senderID) || $data['date'] === null) {
			return;
		}
		$ap = call_user_func($this->apFactory);
		if (!is_object($ap) || !method_exists($ap, 'sendInvoiceResponse')) {
			return;
		}
		$res = $ap->sendInvoiceResponse($pi, array(
			'code' => 'AP',
			'invoiceId' => (string) $data['number'],
			'invoiceDate' => dol_print_date((int) $data['date'], '%Y-%m-%d'),
			'typeCode' => $data['credit'] ? '381' : '380',
			'receiverName' => (string) ($data['supplier']['name'] ?? ''),
		));
		if ($res > 0) {
			if (method_exists($pi, 'saveResponse')) {
				$pi->saveResponse($user, 'AP');
			}
			$this->notices[] = $langs->trans('peppolApprovalSent');
		} else {
			$this->warnings[] = $langs->trans('peppolApprovalNotSent', (string) $ap->error);
		}
	}

	/**
	 * Supplier invoice of this supplier with this invoice number
	 *
	 * @param	int		$socid	Supplier
	 * @param	string	$number	Invoice number of the supplier
	 * @return	int		Id, or 0
	 */
	private function findInvoice($socid, $number)
	{
		$sql = "SELECT f.rowid FROM " . MAIN_DB_PREFIX . "facture_fourn as f";
		$sql .= " WHERE f.entity IN (" . getEntity('supplier_invoice') . ") AND f.fk_soc = " . ((int) $socid);
		$sql .= " AND f.ref_supplier = '" . $this->db->escape($number) . "'";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * Copy the PDF of the XML and the XML itself to the documents of the supplier invoice
	 *
	 * @param	FactureFournisseur	$inv	Supplier invoice (fetched, final ref)
	 * @param	object				$pi		Peppolimport
	 * @param	array{name:string,content:string}|null	$pdf	PDF of parse()
	 * @return	void
	 */
	private function attachFiles($inv, $pi, $pdf)
	{
		global $conf, $langs;

		$base = !empty($conf->fournisseur->facture->multidir_output[$inv->entity]) ? $conf->fournisseur->facture->multidir_output[$inv->entity] : $conf->fournisseur->facture->dir_output;
		$dir = $base . '/' . get_exdir($inv->id, 2, 0, 0, $inv, 'invoice_supplier') . dol_sanitizeFileName($inv->ref);
		if (dol_mkdir($dir) < 0) {
			$this->warnings[] = $langs->trans('peppolBookFilesNotAttached');
			return;
		}
		$files = array();
		if ($pdf !== null) {
			$files[$pdf['name']] = $pdf['content'];
		} elseif (!empty($pi->peppolid)) {
			// Since 2.13.0: no PDF in the message, then Peppyrus makes one of the invoice itself
			$ap = call_user_func($this->apFactory);
			$rendered = (is_object($ap) && method_exists($ap, 'renderMessage')) ? (string) $ap->renderMessage((string) $pi->peppolid) : '';
			if ($rendered !== '') {
				$files[dol_sanitizeFileName('peppol-' . $inv->ref . '.pdf')] = $rendered;
			}
		}
		$xml = $pi->fullFilename();
		$files[dol_sanitizeFileName(basename($xml))] = (string) file_get_contents($xml);
		$failed = 0;
		foreach ($files as $name => $content) {
			if (file_put_contents($dir . '/' . $name, $content) === false) {
				$failed++;
			}
		}
		if ($failed > 0) {
			$this->warnings[] = $langs->trans('peppolBookFilesNotAttached');
		}
	}

	/**
	 * Link the incoming document to the supplier invoice, status "Success"
	 *
	 * @param	object	$pi			Peppolimport
	 * @param	User	$user		User
	 * @param	int		$invoiceId	Supplier invoice
	 * @param	int		$socid		Supplier
	 * @return	void
	 */
	private function linkDocument($pi, $user, $invoiceId, $socid)
	{
		$pi->fk_invoice = (int) $invoiceId;
		$pi->fk_supplier = (int) $socid;
		$pi->status = $pi::STATUS_CLOSED;
		if ($pi->update($user) <= 0) {
			dol_syslog('PeppolBooking::linkDocument document ' . ((int) $pi->id) . ' could not be updated', LOG_WARNING);
		}
	}
}
