<?php

/**
 * \file    peppol/class/peppolap.class.php
 * \ingroup peppol
 * \brief   abstract class for all peppol accesspoints
 *
 */

namespace custom\peppolpeppyrus;

/**
 * Class PeppolAP
 */
abstract class PeppolAP
{
	public $error = '';
	public $errors = [];
	public $db;
	public $descripton;  //short description of the peppol accesspoint
	public $operatorurl; //url to the operator
	public $status;      //0 draft ... 10 = ready for production
	public $setupNeeds = []; //list of setup keys needed for that module setup

	/**
	 * Constructor
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Validate API configuration
	 *
	 * @return  int <= 0 if configuration invalid, > 0 if valid
	 */
	abstract protected function validateConfiguration();


	/**
	 * get api url (test / prod endpoint code factoring)
	 *
	 * @return  string  uri like https://....
	 */
	abstract protected function getApiUrl();

	/**
	 * check your access point
	 *
	 * @param   [type]  $object  [$object description]
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	abstract public function checkAccessPoint($object);

	/**
	 * send document (invoice or credit note) to peppol access point
	 *
 	 * @param   string  $filename  path to the XML file
 	 * @param   object  $object    object (Facture)
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	abstract public function sendToAccessPoint($filename, $object);

	/**
	 * check thirdpart identify thanks to peppol id
	 *
	 * @param   Societe  $thirdpart  dolibarr thirdpart
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	abstract public function checkThirdparty(\Societe $thirdpart);


	/**
	 * get all invoices waiting into your peppol AP
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	abstract public function getSupplierInvoicesList();

	/**
	 * get invoice $pi waiting into your peppol AP
	 *
	 * @param   Peppolimport  $pi  peppolimport object to import
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	abstract public function getSupplierInvoice(Peppolimport $pi);

	/**
	 * get PDF file of invoice $pi waiting into your peppol AP
	 *
	 * @param   Peppolimport  $pi  peppolimport object to import
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	abstract public function getSupplierInvoicePdf(Peppolimport $pi);

	/**
	 * confirm reception of supplier invoice
	 *
	 * @param   Peppolimport  $pi  peppolimport object to import
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	abstract public function confirmSupplierInvoice(Peppolimport $pi);

	/**
	 * reject supplier invoice (in case of trouble / error / other)
	 *
	 * @param   Peppolimport  $pi  peppolimport object to import
	 * @param   string        $reason  reason for rejection
	 *
	 * @return  int <= 0 in case of error, > 0 on success
	 */
	abstract public function rejectSupplierInvoice(Peppolimport $pi, $reason);


}
