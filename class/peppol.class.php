<?php

/**
 * \file    peppol/class/peppol.class.php
 * \ingroup peppol
 * \brief   main peppol stuff is here
 *
 * All peppol job
 */

namespace custom\peppol;

dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');
dol_include_once('/peppolpeppyrus/lib/backports.lib.php');
dol_include_once('/peppolpeppyrus/core/modules/modPeppolpeppyrus.class.php');
dol_include_once('/peppolpeppyrus/class/peppolvatexemption.class.php');
$matches  = preg_grep('/Restler\/AutoLoader.php/i', get_included_files());
// dol_syslog("Peppol matches test for restler is (3) " . json_encode($matches));
if (count($matches) == 0) {
	require_once __DIR__ . '/../vendor/scoper-autoload.php';
} else {
	//autoloader compatible with specific dolibarr restler settings...
	dol_syslog("Peppol with special spl_autoload");

	spl_autoload_register(function ($class) {
		$list = include __DIR__ . '/../vendor/composer/autoload_classmap.php';
		$fileToLoad = $list[$class] ?? '';
		// dol_syslog("Peppol spl_autoload for " . $class . " -> " . $fileToLoad);
		if ($fileToLoad != "" && file_exists($fileToLoad)) {
			require_once $fileToLoad;
		}
	}, true, true);
}

include_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Presets;
use Einvoicing\Writers\UblWriter;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Attachment;
use Einvoicing\Delivery;
use Einvoicing\Payments\Payment;
use Einvoicing\Payments\Transfer;
use Einvoicing\InvoiceReference;

/**
 * Class Peppol
 */
class Peppol
{
	public $error = '';
	public $errors = [];
	public $db;
	private $_invoice;


	const PEPPOL_CUSTOMER_INVOICE = 1;
	const PEPPOL_SUPPLIER_INVOICE = 2;

	/**
	 * Constructor
	 */
	public function __construct($db, \Facture $invoice)
	{
		$this->db = $db;
		if (!is_object($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}

		if (!is_array($invoice->thirdparty->array_options)) {
			$invoice->thirdparty->fetch_optionals();
		}

		$this->_invoice = $invoice;
	}

	/**
	 * build PDF with Peppol XML around
	 *
	 * @param   [type]  $orig_pdf  [$orig_pdf description]
	 *
	 * @return  [type]             [return description]
	 */
	public function makePDF($orig_pdf)
	{
		global $conf, $user, $langs, $mysoc;

		$enableVatForThatInvoice = true;
		$ret = 0;
		$buyerIdent = null;
		$objFacture = $this->_invoice; //migrating code from dolibar
		dol_syslog("peppol:: makePDF for invoice " . json_encode($objFacture));

		//check module version vs last init version in database
		if (isset($this->db)) {
			$tmpmodule = new \modPeppolpeppyrus($this->db);
			if ($tmpmodule->version != $conf->global->PEPPOL_MODULE_VERSION) {
				setEventMessages($langs->trans("ErrorPeppolModuleVersionDatabase"), null, 'errors');
				// return -100;
			}
		}


		if (!is_object($objFacture)) {
			dol_syslog("peppol:: makePDF _invoice is not an object, early return", LOG_WARNING);
			return -1;
		}
		if (empty($objFacture->ref)) {
			dol_syslog("peppol:: makePDF _invoice ref is not set, early return", LOG_WARNING);
			return -2;
		}

		//nes idea - externalize pappol vat rules
		// $vatHandler = new PeppolVatExemption($this->db);
		// $customer = new \Societe($this->db);
		// $customer->fetch($objFacture->socid);

		// // Get suggestion
		// $suggestedCode = $vatHandler->determineExemptionCode(
		// 	$objFacture,
		// 	$customer,
		// 	$objFacture->lines[0]
		// );


		if ($objFacture->thirdparty->tva_assuj != '1') {
			dol_syslog("peppol:: makePDF for a non VAT concerned thirdpart, tva_assuj=" . $objFacture->thirdparty->tva_assuj);
			$enableVatForThatInvoice = false;
		} else {
			dol_syslog("peppol:: makePDF thirdpart tva_assuj=" . $objFacture->thirdparty->tva_assuj);
		}

		if ($objFacture->thirdparty->typent_code == 'TE_PRIVATE') {
			dol_syslog("peppol:: makePDF for a private people : " . $objFacture->thirdparty->typent_code);
			$enableVatForThatInvoice = false;
			if (getDolGlobalString('PEPPOL_FORCE_XML_WITH_VATNULL')) {
				dol_syslog("peppol:: makePDF for a private people but PEPPOL_FORCE_XML_WITH_VATNULL is set, continue");
			} else {
				dol_syslog("peppol:: makePDF for a private people -> no xml output", LOG_INFO);
				return -3;
			}
		} else {
			dol_syslog("peppol:: makePDF for a typent_code=" . $objFacture->thirdparty->typent_code);
		}

		$baseErrors = [];
		$failError = 0;
		if (empty($mysoc->tva_intra)) {
			$baseErrors[] = $langs->trans("PeppolCheckErrorVATnumber");
		}
		if (empty($mysoc->address)) {
			$baseErrors[] = $langs->trans("PeppolCheckErrorAddress");
		}
		if (empty($mysoc->zip)) {
			$baseErrors[] = $langs->trans("PeppolCheckErrorZIP");
		}
		if (empty($mysoc->town)) {
			$baseErrors[] = $langs->trans("PeppolCheckErrorTown");
		}
		if (empty($mysoc->country_code)) {
			$baseErrors[] = $langs->trans("PeppolCheckErrorCountry");
		}

		$otherBaseErrorsToCheck = [
			'name' => ['value' => false, 			'message' => $langs->trans("PeppolCheckErrorCustomerName")],
			'address' => ['value' => false,		 	'message' => $langs->trans("PeppolCheckErrorCustomerAddress")],
			'zip' => ['value' => false, 			'message' => $langs->trans("PeppolCheckErrorCustomerZIP")],
			'town' => ['value' => false, 			'message' => $langs->trans("PeppolCheckErrorCustomerTown")],
			'country_code' => ['value' => false, 	'message' => $langs->trans("PeppolCheckErrorCustomerCountry")],
		];
		$this->_checkBaseErrors($otherBaseErrorsToCheck, $objFacture->thirdparty);

		//shipping address could be a linked contact
		$shippings = $objFacture->getIdShippingContact();
		$shipping = null;
		if (is_array($shippings)) {
			$shippingID = reset($shippings);
			if ($shippingID > 0) {
				$shipping = new \Contact($this->db);
				$res = $shipping->fetch($shippingID);
				if ($res <= 0) {
					dol_syslog("Peppol: invoice has a shipping contact but impossible to fetch it !");
					$shipping = null;
				} else {
					dol_syslog("Peppol: invoice has a shipping contact.");
					$this->_checkBaseErrors($otherBaseErrorsToCheck, $shipping);
				}
			}
		} else {
			dol_syslog("Peppol: getIdShippingContact " . json_encode($shippings));
		}
		dol_syslog("Peppol: shipping is " . json_encode($shipping));

		//billing address could be a linked contact
		$billings = $objFacture->getIdBillingContact();
		$billing = null;
		if (is_array($billings)) {
			$billingID = reset($billings);
			if ($billingID > 0) {
				$billing = new \Contact($this->db);
				$res = $billing->fetch($billingID);
				if ($res <= 0) {
					dol_syslog("Peppol: invoice has a billing contact but impossible to fetch it !");
					$billing = null;
				} else {
					dol_syslog("Peppol: invoice has a billing contact.");
					$this->_checkBaseErrors($otherBaseErrorsToCheck, $billing);
				}
			}
		} else {
			dol_syslog("Peppol: getIdBillingContact " . json_encode($billings));
		}
		dol_syslog("Peppol: billing is " . json_encode($billing));

		//add more messages
		foreach ($otherBaseErrorsToCheck as $key => $details) {
			if (isset($details['value']) && $details['value'] === false) {
				if (isset($details['message'])) {
					dol_syslog("Peppol: add message " . json_encode($details['message']));
					$baseErrors[] = $details['message'];
				}
			}
		}

		//id specifique ?
		dol_syslog("Peppol: fetch thirdparty linked to that invoice");
		$objFacture->fetch_thirdparty();
		if (!empty($objFacture->thirdparty->array_options['options_peppol_id'])) {
			dol_syslog("Peppol: specific peppol id " . json_encode($objFacture->thirdparty->array_options['options_peppol_id']));
			//9938:20225000264
			if (strpos($objFacture->thirdparty->array_options['options_peppol_id'], ':')) {
				$code = explode(':', $objFacture->thirdparty->array_options['options_peppol_id']);
				dol_syslog("Peppol: buyer Ident code is " . json_encode($code));
				if (!empty($code[0]) && !empty($code[1])) {
					$buyerIdent = new Identifier($code[1], $code[0]);
					dol_syslog("Peppol: buyer Ident is now " . json_encode($buyerIdent));
				} else {
					if (empty($code[0])) {
						$baseErrors[] = $langs->trans("PeppolCheckErrorCustomerPeppolIDpartA");
						$failError++;
					}
					if (empty($code[1])) {
						$baseErrors[] = $langs->trans("PeppolCheckErrorCustomerPeppolIDpartB");
						$failError++;
					}
				}
			} else {
				$baseErrors[] = $langs->trans("PeppolCheckErrorCustomerPeppolID");
				$failError++;
			}
		} else {
			dol_syslog("Peppol: note there is no specific peppol id");
		}

		//note : no alert is case of private people
		if (empty($objFacture->thirdparty->tva_intra) && empty($buyerIdent) && $enableVatForThatInvoice) {
			dol_syslog("Peppol: there is no vat id or buyerIdent");
			$baseErrors[] = $langs->trans("PeppolCheckErrorCustomerVAT");
		}

		// print "<pre>";
		// print json_encode($objFacture);
		// print "</pre>";
		// exit;
		// print json_encode($parameters);
		// exit;

		if ($failError > 0) {
			if (strpos($orig_pdf, 'SPECIMEN') > 0) {
				//no message
			} else {
				setEventMessages($langs->trans("PeppolCheckError"), $baseErrors, 'errors');
			}
			dol_syslog("Peppol: return due to errors numOfErrors=$failError, message=" . json_encode($baseErrors), LOG_ERR);
			return $ret;
		}
		dol_syslog("Peppol: there is no mail errors");

		// print(json_encode($objFacture));
		// exit;
		//print_r($object); echo "action: " . $action;
		// exit;
		// if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {
		// 	// do something only for the context 'somecontext1' or 'somecontext2'
		// }

		$note_pub = $objFacture->note_public ? $objFacture->note_public : "note";
		$ladate = new \DateTime(dol_print_date($objFacture->date, 'dayrfc'));
		$ladatepaiement = new \DateTime(dol_print_date($objFacture->date_lim_reglement, 'dayrfc'));
		$currency = $objFacture->multicurrency_code ?? '';

		// Delivery address
		$delivery = new Delivery();
		$delivery->setDate($ladate);
		if (!empty($shipping)) {
			$delivery->setName($shipping->lastname . ' ' . $shipping->firstname)
				->setCountry($shipping->country_code)
				->setAddress([$shipping->address])
				->setCity($shipping->town)
				->setSubdivision($shipping->country_code . '-' . $shipping->state_code);
			if (!empty($shipping->zip)) {
				$delivery->setPostalCode($shipping->zip);
			}
		} else {
			$delivery->setCountry($objFacture->thirdparty->country_code)
				->setAddress([$objFacture->thirdparty->address])
				->setCity($objFacture->thirdparty->town)
				->setSubdivision($objFacture->thirdparty->country_code . '-' . $objFacture->thirdparty->state_code);
			if (!empty($objFacture->thirdparty->zip)) {
				$delivery->setPostalCode($objFacture->thirdparty->zip);
			}
		}
		dol_syslog("Peppol: delivery is " . json_encode($delivery));

		// Create PEPPOL invoice instance
		$inv = new Invoice(Presets\Peppol::class);

		if ($objFacture->type == \Facture::TYPE_CREDIT_NOTE) {
			$inv->setType(Invoice::TYPE_CREDIT_NOTE);
			/*
				<cac:BillingReference>
					<cac:InvoiceDocumentReference>
						<cbc:ID>Snippet1</cbc:ID>
					</cac:InvoiceDocumentReference>
				</cac:BillingReference>
			*/
			if (!empty($objFacture->fk_facture_source)) {
				$doliR = new \Facture($this->db);
				$res = $doliR->fetch($objFacture->fk_facture_source);
				if ($res) {
					$ladateR = new \DateTime(dol_print_date($doliR->date, 'dayrfc'));
					$invR = new InvoiceReference($doliR->ref, $ladateR);
					$inv->addPrecedingInvoiceReference($invR);
				}
			}
		}

		$inv->setNumber($objFacture->ref)
			->setIssueDate($ladate)
			->setDelivery($delivery)
			->setDueDate($ladatepaiement)
			->addNote($note_pub);

		if (!empty($currency)) {
			$inv->setCurrency($currency);
		}

		// Set seller
		$seller = new Party();
		//
		$sellerIdentifyer = $sellerIdentifyerVAT = null;
		$sellerIdentifyerVAT = new Identifier($mysoc->tva_intra, peppolGetIdentifierSchemeFromVatNumber($mysoc->tva_intra));
		$mypeppolid = getDolGlobalString('PEPPOL_AP_SENDER_ID');
		if (!empty($mypeppolid)) {
			if (strpos($mypeppolid, ":")) {
				$mypeppolidarr = explode(":", $mypeppolid);
				$sellerIdentifyer = new Identifier($mypeppolidarr[1], $mypeppolidarr[0]);
				$seller->setElectronicAddress($sellerIdentifyer);
				$seller->addIdentifier($sellerIdentifyerVAT);
			} else {
				$seller->setElectronicAddress($sellerIdentifyerVAT);
			}
		} else {
			$seller->setElectronicAddress($sellerIdentifyerVAT);
		}
		$sellerIdentifyer = new Identifier($mysoc->tva_intra, peppolGetIdentifierSchemeFromVatNumber($mysoc->tva_intra));
		$seller
			// ->addIdentifier(new Identifier($mysoc->tva_intra, null))
			// ->setCompanyId(new Identifier($mysoc->tva_intra, peppolGetIdentifierSchemeFromVatNumber($mysoc->tva_intra)))
			// ->setTaxRegistrationId(new Identifier($mysoc->tva_intra, peppolGetIdentifierSchemeFromVatNumber($mysoc->tva_intra)))
			->setName($mysoc->name)
			->setTradingName($mysoc->name)
			->setVatNumber($mysoc->tva_intra)
			->setAddress([$mysoc->address])
			->setCity($mysoc->town)
			->setSubdivision($mysoc->country_code . '-' . $mysoc->state_code)
			->setCountry($mysoc->country_code);
		if (!empty($mysoc->zip)) {
			$seller->setPostalCode($mysoc->zip);
		}
		$inv->setSeller($seller);
		dol_syslog("Peppol: seller is " . json_encode($seller));

		// Si Bénéficiaire <=> vendeur ?
		//$inv->setPayee($seller);


		// --------------------------------------------------------------------------------------------------------------------------
		// Set buyer -- For the address it could be a billing contact
		$buyer = new Party();
		// var_dump($buyer);
		//note: il ne peut pas avoir un peppol id ET un numéro de tva intraco ? contre ordre mars 2024 luxembourg demande TVA et PEPPOL ...
		$buyerVAT = $objFacture->thirdparty->tva_intra ?? '';
		if (empty($buyerIdent)) {
			dol_syslog("Peppol: buyer Ident is empty");
			if (empty($buyerVAT)) {
				dol_syslog("Peppol: buyer VAT is empty too");

				//Voir la liste https://docs.peppol.eu/poacc/billing/3.0/codelist/UNCL5305/
				// et https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/
				//Asso et autres non assujetis à la TVA
				if ($enableVatForThatInvoice) {
					if (getDolGlobalString('PEPPOL_FORCE_XML_WITH_VATNULL')) {
						dol_syslog("PEPPOL_FORCE_XML_WITH_VATNULL is enabled", LOG_WARNING);
						if (empty($buyerIdent)) {
							$baseErrors[] = $langs->trans("BuyerDoesNotHaveVATDetails");
							$baseErrors[] = $langs->trans("BuyerDoesNotHaveVATDetailsForce") . "<br />";
						}
						$buyerVAT = null;
					} else {
						$baseErrors[] = $langs->trans("BuyerDoesNotHaveVATDetails");
						dol_syslog("Buyer does not have a VAT number, Peppol export disabled", LOG_WARNING);
						setEventMessages($langs->trans("PeppolError"), array($langs->trans("BuyerDoesNotHaveVATDetails")), 'warnings');
						return -1;
					}
				} else {
					dol_syslog("Buyer does not have a VAT number, but is a private people or non vat aware organisation", LOG_WARNING);
					$buyerVAT = null;
					$buyer->setElectronicAddress(new Identifier($objFacture->thirdparty->email, '0007'));
				}
				dol_syslog("peppol Identifier stay empty");
			}
		} else {
		}
		//->setElectronicAddress(new Identifier($objFacture->thirdparty->idprof1, '0208')) //TODO
		// ->setCompanyId(new Identifier($buyerVAT, peppolGetIdentifierSchemeFromVatNumber($buyerVAT))) //TODO ?

		//Note : could have a special billing address
		if (!empty($billing)) {
			dol_syslog('peppol idBuyer from billing contact');
			$buyer->setName($objFacture->thirdparty->name) //NAME IS CUSTOMER NAME
				->setContactName($billing->lastname . " " . $billing->firstname)
				->setContactEmail($billing->email)
				->setAddress([$billing->address])
				->setCity($billing->town)
				->setSubdivision($billing->country_code . '-' . $billing->state_code)
				->setCountry($billing->country_code);

			if (!empty($billing->zip)) {
				$buyer->setPostalCode($billing->zip);
			}
		} else {
			dol_syslog('peppol idBuyer from thirdparty');
			$buyer->setName($objFacture->thirdparty->name)
				->setTradingName($objFacture->thirdparty->name)
				->setAddress([$objFacture->thirdparty->address])
				->setCity($objFacture->thirdparty->town)
				->setSubdivision($objFacture->thirdparty->country_code . '-' . $objFacture->thirdparty->state_code)
				->setCountry($objFacture->thirdparty->country_code);
			// var_dump(json_encode($buyer));

			if (!empty($objFacture->thirdparty->zip)) {
				$buyer->setPostalCode($objFacture->thirdparty->zip);
			}
		}
		if (!empty($buyerVAT)) {
			$buyer->setVatNumber($buyerVAT);
			if (empty($buyerIdent)) {
				dol_syslog("peppol set ElectronicAddress because buyerIdent is empty");
				$buyer->setElectronicAddress(new Identifier($buyerVAT, peppolGetIdentifierSchemeFromVatNumber($buyerVAT)));
				//2024-09-10 voir TS2406-0081 luxembourg
				$buyer->setElectronicAddress(new Identifier($buyerVAT, null));
			} else {
				dol_syslog("peppol do not set ElectronicAddress, buyerIdent is not empty, addIdentifier with buyerIdent");
				$buyer->setElectronicAddress($buyerIdent);
			}
		}

		//note peppol 2025-05-23 "Buyer electronic address MUST be provided Test=cbc:EndpointID"
		if (empty($buyerIdent) && !empty($buyerVAT)) {
			dol_syslog("peppol set ElectronicAddress because buyerIdent is empty");
			$buyer->setElectronicAddress(new Identifier($buyerVAT, peppolGetIdentifierSchemeFromVatNumber($buyerVAT)));
		}

		//BT-24 roumanie
		if ($currency == "RON") {
			dol_syslog("Peppol special case for romania");
			$inv->setSpecification("urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1");
			//waiting for core fix - https://github.com/Dolibarr/dolibarr/issues/26961
			$stateCodeBuyer = $objFacture->thirdparty->state_code;
			$stateCodeSeller = $mysoc->state_code;
			$cityBuyer = $objFacture->thirdparty->town;
			$citySeller = $mysoc->town;
			if ($stateCodeBuyer == "BU") {
				$stateCodeBuyer = "B";
				if (preg_match('/(SECTOR.)/i', str_replace(" ", "", $cityBuyer), $matches)) {
					if (is_array($matches) && !empty($matches[0])) {
						$cityBuyer = strtoupper($matches[0]);
					} else {
						$baseErrors[] = $langs->trans("PeppolCheckErrorSellerBucarestNoSector");
					}
				}
			}
			if ($stateCodeSeller == "BU") {
				$stateCodeSeller = "B";
				if (preg_match('/(SECTOR.)/i', str_replace(" ", "", $citySeller), $matches)) {
					if (is_array($matches) && !empty($matches[0])) {
						$citySeller = strtoupper($matches[0]);
					} else {
						$baseErrors[] = $langs->trans("PeppolCheckErrorBuyerBucarestNoSector");
					}
				}
			}
			$delivery->setSubdivision($objFacture->thirdparty->country_code . '-' . $stateCodeBuyer)
				->setCity($cityBuyer);
			$buyer->setSubdivision($objFacture->thirdparty->country_code . '-' . $stateCodeBuyer)
				->setCity($cityBuyer);
			$seller->setSubdivision($mysoc->country_code . '-' . $stateCodeSeller)
				->setCity($citySeller);
		}

		//20220217 - add more informations about buyer if private person
		if ($objFacture->thirdparty->typent_code == 'TE_PRIVATE') {
			$buyer->setContactName($objFacture->thirdparty->name);
			$buyer->setContactPhone($objFacture->thirdparty->phone);
			if (!empty($objFacture->thirdparty->email)) {
				$buyer->setContactEmail($objFacture->thirdparty->email);
			}
		}
		$inv->setBuyer($buyer);
		dol_syslog("Peppol: buyer is " . json_encode($buyer));

		//Inversion Purchase / Sales - vu juin 2023
		$buyerReference = "no buyer ref.";
		if (!empty($objFacture->ref_client)) {
			$buyerReference = $objFacture->ref_client;
		}
		$inv->setBuyerReference($buyerReference);
		$inv->setPurchaseOrderReference($buyerReference);

		$purchaseOrderRef = "no order ref.";
		// dol_syslog(' ********************************** peppol recheche du bon de commande');
		if (isset($objFacture->linkedObjectsIds['commande'])) {
			$cmd = reset($objFacture->linkedObjects['commande']);
			$purchaseOrderRef = "ref cmd: " . $cmd->ref . " (" . dol_print_date($cmd->date_validation) . ")";
		}
		$inv->setSalesOrderReference($purchaseOrderRef);
		// print(json_encode($purchaseOrderRef));
		// exit;

		//retenue de garantie
		$retenue_garantie = peppolGetRetainedWarrantyAmount($objFacture, true);
		$paymentTerms = "";
		if ($retenue_garantie > 0) {
			$reste = $objFacture->total_ttc - peppolGetRetainedWarrantyAmount($objFacture, true);;
			$paymentTerms .= "TOTAL HT : " . price($objFacture->total_ht, 0, '', 1, -1, -1, 'auto') . "\n";
			$paymentTerms .= "TVA : " . price($objFacture->total_tva, 0, '', 1, -1, -1, 'auto') . "\n";
			$paymentTerms .= "TOTAL TTC : " . price($objFacture->total_ttc, 0, '', 1, -1, -1, 'auto') . "\n";
			$paymentTerms .= "- RG (" . $objFacture->retained_warranty . "%) " . $langs->transnoentitiesnoconv("toPayOn", dol_print_date($objFacture->retained_warranty_date_limit, 'day')) . " : " . price($retenue_garantie, 0, '', 1, -1, -1, 'auto') . "\n";
			$paymentTerms .= "TOTAL " . $langs->transnoentitiesnoconv("toPayOn", dol_print_date($objFacture->date_lim_reglement, 'day')) . ": " . price($reste, 0, '', 1, -1, -1, 'auto') . "\n";
			//toPayOn
			/* format demandé par luxembourg pour les RG:
			TOTAL HT xxx€
			- Prorata x% - xxx€
			TOTAL HT xxx€
			TVA xx% : xxx €
			TOTAL TTC: xxx€
			- RG 10%: - xxx€
			TOTAL a percevoir : xxx€
			*/
		} else {
			$paymentTerms = $objFacture->cond_reglement_code;
		}

		//default bank account in global dolibarr settings ?
		// Payment informations
		$bankid = (($objFacture->fk_account > 0) ? $objFacture->fk_account : $conf->global->FACTURE_RIB_NUMBER);
		if (!empty($bankid)) {
			$payment = new Payment();
			$transfert = new Transfer();
			$bank = new \Account($this->db);
			$bankres = $bank->fetch($bankid);
			if ($bankres) {
				dol_syslog("peppol bank : " . json_encode($bank));
				// mode_reglement
				if ($objFacture->mode_reglement_code == "VIR" && empty($bank->iban)) {
					$baseErrors[] = $langs->trans("BankAccountLinkedWithoutIBAN") . "<br />";
				} else {
					$transfert->setAccountId($bank->iban);
				}

				$meansCodeAndLabel = dolibarrToPeppolMeansCode($objFacture->mode_reglement_code);
				if (is_array($meansCodeAndLabel) && $meansCodeAndLabel['code'] != '' && $meansCodeAndLabel['label'] != "") {
					$payment->setId($objFacture->ref); //référence du virement
					$payment->setTerms($paymentTerms);
					$payment->setMeansCode($meansCodeAndLabel['code']); //dol_print_date($objFacture->date_lim_reglement, "dayrfc")
					$payment->setMeansText($meansCodeAndLabel['label']);
					if (!empty($bank->bic)) {
						$transfert->setProvider($bank->bic);
					}
					if (empty($bank->bank)) {
						$baseErrors[] = $langs->trans("BankAccountLinkedHasNoName") . "<br />";
					} else {
						$transfert->setAccountName($bank->bank);
					}
					$payment->addTransfer($transfert);
					$inv->setPayment($payment);
				}
			}
		} else {
			$baseErrors[] = $langs->trans("InvoiceDoesNotHaveBankAccountLinked") . "<br />";
		}

		/*
			$bank = new Account($this->db);
		$bankres = $bank->fetch('', 'SUMUP');
		if ($bankres) {
		$message = $langs->trans("SUMUP_BANK_EXISTS");
		dol_syslog("Sumup init : bank account SUMUP exists");
		//existe déjà
		} else {
		$bank->specimen        = 0;
		$bank->ref             = 'SUMUP';
		$bank->label           = 'Sumup';
		$bank->bank            = 'Sumup';
		$bank->courant         = Account::TYPE_CURRENT;
		$bank->clos            = Account::STATUS_OPEN;
		$bank->code_banque     = '';
		$bank->code_guichet    = '';
		$bank->number          = '';
		$bank->cle_rib         = '';
		$bank->bic             = '';
		$bank->iban            = '';
		$bank->proprio         = $mysoc->name;
		$bank->owner_address   = $mysoc->address;
		$bank->country_id      = $mysoc->country_id;
		$bank->date_solde	   = dol_now();
		$res = $bank->create($user);
		$message = $langs->trans("SUMUP_BANK_CREATED");
		dol_syslog("Sumup init : bank account SUMUP doest not exist, try to create it, return code is $res");
		}
		"date_lim_reglement": 1688335200,
		"cond_reglement_code": "60D",
		"mode_reglement_code": "VIR",
		"fk_bank": "1",
		*/

		//multi taux de tva ou pas il faut collecter les infos pour faire le recap global a la fin
		$tabTVA = [];

		// Add products lines
		$numligne = 1;
		foreach ($objFacture->lines as $line) {
			// print json_encode($line);exit;
			$libelle = $line->libelle ? $line->libelle : "libre";
			$qty = $line->qty ? $line->qty : 0;

			//Ligne a qty=0 prix=0 c'est du blablabla -> next
			if ($qty == 0 && $line->subprice == 0) {
				continue;
			}

			//chez peppol il faut donner le montant * quantité, la division est faite de l'autre côté
			$subprice = peppolAmountToFloat($line->subprice ? $line->subprice : 0.00) * $qty;

			//pour les remises (lignes négatives)
			//cas particulier si dolibarr a quantité négative et montant positif on inverse
			$remiseLigne = false;
			if ($objFacture->type != \Facture::TYPE_CREDIT_NOTE) {
				if ($qty <= 0) {
					$remiseLigne = true;
					$qty = abs($qty);
					$subprice = abs($subprice) * -1;
				} else {
					//quantité positive, juste pour le fun on vérifie si le prix est + ou -
					if ($subprice < 0) {
						$remiseLigne = true;
					}
				}
			} else {
				$subprice = abs($subprice);
				$qty = abs($qty);
			}

			// print "<p>Ajout d'une ligne : $qty, $subprice</p>";
			$tva_tx = $line->tva_tx ? $line->tva_tx : 0;
			$description = strip_tags(trim($line->desc ? $line->desc : ""));
			$lineref = trim($line->ref ? $line->ref : peppolNextRefLine());
			$lineproductref = strip_tags(trim($line->product_ref ? $line->product_ref : ""));
			$productAccountingNumber = '';

			if ($remiseLigne && empty($lineproductref)) {
				$lineproductref = "DISCOUNT";
			}

			dol_syslog("Peppol : line add qty=$qty, subprice=$subprice, ref=$lineref, productref=$lineproductref");

			//do not add empty lines in XML file (cleaner)
			//note: it remove subtotal lines from ATM plugin for example
			if (empty($description) && $subprice == 0) {
				dol_syslog("Peppol : empty line (" . json_encode($line) . "), next");
				continue;
			}

			if (!empty($line->fk_product)) {
				$p = new \Product($this->db);
				$res = $p->fetch($line->fk_product);
				if ($res) {
					// print json_encode($p);exit;
					$productAccountingNumber = $p->accountancy_code_sell;
				}
			}

			if (!is_array($tabTVA[$tva_tx])) {
				$tabTVA[$tva_tx] = [];
				$tabTVA[$tva_tx]['totalHT'] = 0;
				$tabTVA[$tva_tx]['totalTVA'] = 0;
			}

			$tabTVA[$tva_tx]['totalHT']  += $line->total_ht;
			$tabTVA[$tva_tx]['totalTVA'] += $line->total_tva;

			$numligne++;

			if ($remiseLigne) {
				//devient alors une remise globale sur la facture ...
				dol_syslog("Peppol : line add special remise -> remise globale peppol");
				$allowance = (new AllowanceOrCharge())->setReason('Discount')
					->setReasonCode(95)
					->markAsFixedAmount()
					->setAmount(abs($subprice))
					->setVatRate($tva_tx);
				$res = $inv->addAllowance($allowance);
				continue;
			} else {
				$peppolLine = (new InvoiceLine())
					->setName($libelle)
					->setId($lineref)
					//->setPrice($subprice, $qty) //return as prev situation (qty) - juin 2023
					->setPrice($line->subprice, 1) //20251120 - change get dolibarr line unit price
					->setQuantity($qty);
				if ($tva_tx > 0) {
					$peppolLine->setVatRate($tva_tx);
				}
				if (!empty($description)) {
					$peppolLine->setDescription($description);
				}
			}
			// print "<p>Ajout d'une ligne : qty=$qty, price=$subprice, tx_tva=$tva_tx</p>";

			if (!empty($productAccountingNumber)) {
				$peppolLine->setBuyerAccountingReference($productAccountingNumber);
			}
			if (!empty($lineproductref)) {
				$peppolLine->setSellerIdentifier($lineproductref);
			}
			if ($tva_tx == 0) {
				//Voir la liste https://docs.peppol.eu/poacc/billing/3.0/codelist/UNCL5305/
				// et https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/
				/*
					+------------+----------------------------------+-------------------+-------------+
					| Categorie  | Usage                            | N TVA autorises ? | Taux        |
					+------------+----------------------------------+-------------------+-------------+
					| O          | Hors champ TVA                   | NON               | Pas de taux |
					| E          | Exoneration de TVA               | OUI               | Pas de taux |
					| K          | Livraison intracommunautaire     | OUI               | 0%          |
					| Z          | Taux zero                        | OUI               | 0%          |
					| AE         | Autoliquidation (reverse charge) | OUI               | Pas de taux |
					| S          | Taux standard                    | OUI               | 20% (FR)    |
					| AA         | Taux reduit                      | OUI               | 5.5% ou 10% |
					+------------+----------------------------------+-------------------+-------------+

					Regles importantes :
					- Categorie O : interdit BT-31 (n TVA vendeur), BT-48 (n TVA acheteur), BT-63
					- Categorie O : interdit BT-152 (taux de TVA)
					- Categories E, AE : pas de taux mais motif d'exoneration requis (BT-120)
					- Categorie K : taux = 0% obligatoire + motif d'exoneration
				*/
				if ($enableVatReverseChargeForThatInvoice || $objFacture->thirdparty->vat_reverse_charge) {
					$peppolLine
						->setVatCategory('AE')
						->setVatExemptionReasonCode('VATEX-EU-AE')
						->setVatExemptionReason('Reverse Carge');
				} elseif ($enableVatForThatInvoice) {
					$peppolLine
						->setVatCategory('K')
						->setVatExemptionReasonCode('VATEX-EU-IC')
						->setVatExemptionReason('VAT exempt for EEA intra-community supply of goods and services');
				} else {
					//Tax Category O MUST be used when exemption reason code is VATEX-EU-O
					//note La catégorie "O" (Not subject to VAT) est très restrictive dans PEPPOL. Elle est réservée aux opérations totalement hors du champ d'application de la TVA, par exemple :
					// Activités médicales/paramédicales de certains professionnels
					// Enseignement
					// Certaines opérations financières/bancaires
					$peppolLine
						->setVatCategory('O')
						->setVatExemptionReasonCode('VATEX-EU-O')
						->setVatExemptionReason('Not subject to VAT');
					// delete all vat numbers
					$buyer->setVatNumber('');
					$seller->setVatNumber('');
				}
				$peppolLine->setVatRate(0);
			} else {
				//VAT normale
			}

			//Si on est déjà sur une ligne de remise on ne regarde pas le % de remise
			if (!$remiseLigne) {
				if (!empty($line->remise_percent)) {
					$remise_abs = $subprice - $line->total_ht;
					dol_syslog("  peppol line add remise (Allowance): " . $line->remise_percent . " absolute remise=" . $remise_abs);
					$allowance = (new AllowanceOrCharge())->setReason('Discount')
						->setReasonCode(95)
						->setAmount($remise_abs);

					/*
					error, with percentage -> voir #4
					Element/context: /:Invoice[1]/cac:InvoiceLine[2]
					XPath test: u:slack($lineExtensionAmount, ($quantity * ($priceAmount div $baseQuantity)) + $chargesTotal - $allowancesTotal, 0.02)
					Error message: Invoice line net amount MUST equal (Invoiced quantity * (Item net price/item price base quantity) + Sum of invoice line charge amount - sum of invoice line allowance amount
					// ->markAsPercentage()
					// ->setAmount($line->remise_percent);
					*/

					$res = $peppolLine->addAllowance($allowance);
					// print "<p>" . var_dump($res) . "</p>";
				}
			}
			$res = $inv->addLine($peppolLine);
			// dol_syslog("  peppol line add debug is : " . var_dump($peppolLine));
			// print("  add peppol line : <pre>" . json_encode(var_dump($peppolLine)) . "</pre>") ;
			// print "<p>json_error : " . json_last_error() . " message = " . json_last_error_msg() . "</p>";
		}

		// //Multi taux de tva eventuel ?
		// foreach ($tabTVA as $k => $v) {
		//     $code = "S";
		//     if ($k == 0) {
		//         $code = 'E';
		//     }
		//     $inv->setVatCategory($code);
		//     // $inv->addDocumentTax($code, "VAT", $v['totalHT'], $v['totalTVA'], $k);
		// }

		if (!file_exists($orig_pdf)) {
			dol_syslog("Peppol : MakePDF, orig_pdf ($orig_pdf) does not exists, try with object ref");
			$orig_pdf = $conf->facture->dir_output . '/' . $objFacture->ref . "/" . $objFacture->ref . '.pdf';
			if (!file_exists($orig_pdf)) {
				dol_syslog("Peppol : MakePDF, no more success with $orig_pdf");
			}
		}

		$embeddedAttachment = (new Attachment())
			->setId(new Identifier($objFacture->ref))
			->setFilename($objFacture->ref . '.pdf')
			->setMimeCode('application/pdf')
			->setContents(file_get_contents($orig_pdf));
		$inv->addAttachment($embeddedAttachment);

		if (!empty($objFacture->getLastMainDocLink('facture'))) {
			$externalAttachment = (new Attachment())
				->setId(new Identifier($objFacture->ref . '-online'))
				->setDescription('A link to PDF invoice')
				->setExternalUrl($objFacture->getLastMainDocLink('facture'));
			$inv->addAttachment($externalAttachment);
		}

		try {
			$inv->validate();
			//XML with PDF inside
			// if ($conf->global->PEPPOL_XMLINSIDE) {
			$dest = $conf->facture->dir_output . '/' . dol_sanitizeFileName($objFacture->ref) . "/" . dol_sanitizeFileName($objFacture->ref) . "_peppol.xml";
			dol_syslog("  save peppol XML to $dest");
			// Export invoice to a UBL document
			// header('Content-Type: text/xml');
			$writer = new UblWriter();
			try {
				$xml = $writer->export($inv);
				file_put_contents($dest, $xml);
			} catch (\Exception $e) {
				dol_syslog("Peppol exception occurs on writer export : " . $e->getMessage());
			}
			// print "<pre>". htmlentities($xml) . "</pre>";
			// }
		} catch (ValidationException $e) {
			dol_syslog(get_class($this) . '::executeHooks peppol error: ' . $e);
			$baseErrors[] = $langs->trans("PeppolWarningAutoValidate", $e);
			$this->errors[] = $langs->trans("PeppolWarningAutoValidate", $e);
		}

		if (implode('', $baseErrors) != '') {
			//SPECIMEN
			if (strpos($orig_pdf, 'SPECIMEN') > 0) {
				//no message
			} else {
				setEventMessages($langs->trans("PeppolCheckError"), $baseErrors, 'warnings');
			}
		}

		dol_syslog(' peppol end makePDF');

		return $ret;
	}


	/**
	 * return IEC_6523 code (https://docs.peppol.eu/poacc/billing/3.0/codelist/ICD/)
	 *
	 * @return [type]  [return description]
	 */
	private function IEC_6523_code()
	{
		global $mysoc;
		$retour = "";
		switch ($mysoc->country_code) {
			case 'BE':
				$retour = "0008";
				break;
			case 'DE':
				$retour = "0000";
				break;
			case 'FR':
				$retour = "0009";
				break;
			default:
		}
		return $retour;
	}

	private function idprof()
	{
		global $mysoc;
		$retour = "";
		switch ($mysoc->country_code) {
			case 'BE':
				$retour = $mysoc->idprof1;
				break;
			case 'DE':
			case 'FR':
				$retour = $mysoc->idprof2;
				break;
			default:
				$retour = $mysoc->idprof2;
		}
		return $this->remove_spaces($retour);
	}


	/************************************************
	 *    Check line type from external module ?
	 *
	 * @param  object $line       line we work on
	 * @param  string $element    line object element (for special case like shipping)
	 * @param  string $searchName module name we look for
	 * @return boolean                        true if the line is a special one and was created by the module we ask for
	 ************************************************/
	private function isLineFromExternalModule($line, $element, $searchName)
	{
		if ($element == 'shipping' || $element == 'delivery') {
			$fk_origin_line    = $line->fk_origin_line;
			$line            = new \OrderLine($this->db);
			$line->fetch($fk_origin_line);
		}
		if ($line->product_type == 9 && $line->special_code == $this->get_mod_number($searchName)) {
			return true;
		} else {
			return false;
		}
	}

	/************************************************
	 *    Find module number
	 *
	 * @param  string $searchName module name we look for
	 * @return integer                        -1 if KO, 0 not found or module number if Ok
	 ************************************************/
	private function get_mod_number($modName)
	{
		if (class_exists($modName)) {
			$objMod    = new $modName($this->db);
			return $objMod->numero;
		}
		return 0;
	}

	private function remove_spaces($str)
	{
		return preg_replace('/\s+/', '', $str);
	}

	/**
	 * do base checks againts object
	 *
	 */
	private function _checkBaseErrors(&$checklist, $obj)
	{
		global $langs;
		$baseErrors = [];
		dol_syslog("_checkBaseErrors for " . get_class($obj));
		if (get_class($obj) == "Societe") {
			if (! empty($obj->name)) {
				$checklist['name']['value'] = true;
			}
		}
		if (!empty($obj->address)) {
			$checklist['address']['value'] = true;
		}
		if (!empty($obj->zip)) {
			$checklist['zip']['value'] = true;
		}
		if (!empty($obj->town)) {
			$checklist['town']['value'] = true;
		}
		if (!empty($obj->country_code)) {
			$checklist['country_code']['value'] = true;
		}
	}

	/**
	 * Search PEPPOL Directory API
	 *
	 * @param string $query Search query (company name, VAT number, etc.)
	 * @return array|false Array of results or false on error
	 */
	static public function searchPeppolDirectory($query)
	{
		$apiUrl = 'https://directory.peppol.eu/search/1.0/json';

		// Build query parameters
		$params = array(
			'q' => $query
		);

		$url = $apiUrl . '?' . http_build_query($params);

		$headers = [
			'Accept' => 'application/json',
			'User-Agent' => 'Dolibarr-PEPPOL-Module'
		];

		// Use Dolibarr's getURLContent function
		$result = getURLContent($url, 'GET', '', 1, $headers, array('http', 'https'), 0);

		// Check for errors
		if (empty($result['content'])) {
			if (!empty($result['curl_error_msg'])) {
				return array('error' => 'Connection error: ' . $result['curl_error_msg']);
			}
			return array('error' => 'Empty response from PEPPOL Directory');
		}

		// Check HTTP code
		if (!empty($result['http_code']) && $result['http_code'] != 200) {
			return array('error' => 'HTTP error: ' . $result['http_code']);
		}

		// Decode JSON response
		$data = json_decode($result['content'], true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return array('error' => 'JSON decode error: ' . json_last_error_msg());
		}

		return $data;
	}
}
