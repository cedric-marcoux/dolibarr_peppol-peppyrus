<?php

/**
 * \file    peppol-peppyrus/class/actions_peppolpeppyrus.class.php
 * \ingroup peppolpeppyrus
 * \brief   Hook overload for Peppol Peppyrus module.
 */
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');
dol_include_once('/peppolpeppyrus/lib/backports.lib.php');
include_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once __DIR__ . '/peppol.class.php';
$matches  = preg_grep('/Restler\/AutoLoader.php/i', get_included_files());
if (count($matches) == 0) {
	require_once __DIR__ . '/../vendor/scoper-autoload.php';
}

use custom\peppolpeppyrus\Peppol;

/**
 * Class ActionsPeppolpeppyrus
 */
class ActionsPeppolpeppyrus
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();

	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int Priority of hook (50 is used if value is not defined)
	 */
	public $priority;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->priority = 80;
	}

	/**
	 * Execute action
	 */
	public function getNomUrl($parameters, &$object, &$action)
	{
		global $db, $langs, $conf, $user;
		$this->resprints = '';
		return 0;
	}

	/**
	 * Overloading the doActions function
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0;

		switch ($action) {
			case "confirm_peppolSendFile":
				$filename = peppolFindFileToUse($object, $parameters['outputdir']);
				$object->fetch_optionals();
				peppolSendToAccessPoint($filename, $object);
				break;
		}

		if (!$error) {
			$this->results = array('myreturn' => 999);
			$this->resprints = 'A text to show';
			return 0;
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}

	/**
	 * Overloading the doMassActions function
	 */
	public function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs, $db;

		$error = 0;
		dol_syslog(get_class($this) . '::doMassActions peppolpeppyrus' . json_encode($parameters));

		$contextArray = explode(':', $parameters['context']);
		if (in_array('invoicelist', $contextArray)) {
			if ($parameters['massaction'] == "peppolZip") {
				$obj = new Facture($db);

				$destdir = stripslashes($parameters['diroutputmassaction']);
				if (!is_dir($destdir)) {
					dol_syslog(get_class($this) . '::doMassActions make directory ' . $destdir);
					mkdir($destdir, 0700, true);
				}
				$zipname = $destdir . '/archive-peppol.zip';
				$zip = new ZipArchive();
				if ($zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
					dol_syslog(get_class($this) . '::doMassActions zip destination is ' . $zipname);
					foreach ($parameters['toselect'] as $objectid) {
						if ($obj->fetch($objectid) > 0) {
							$fic = $conf->facture->dir_output . '/' . $obj->ref . "/" . $obj->ref . "_peppol.xml";
							if (file_exists($fic)) {
								$zip->addFile($fic, basename($fic));
							}
						}
					}
					$zip->close();

					header('Content-Type: application/zip');
					header('Content-disposition: attachment; filename=' . basename($zipname));
					header('Content-Length: ' . filesize($zipname));
					readfile($zipname);
					exit;
				}
			}
		}

		if (!$error) {
			$this->results = array('myreturn' => 999);
			$this->resprints = 'A text to show';
			return 0;
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}

	/**
	 * Overloading the addMoreMassActions function
	 */
	public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;
		$langs->load("peppol@peppolpeppyrus");
		dol_syslog(get_class($this) . '::addMoreMassActions peppolpeppyrus' . json_encode($parameters));

		$error = 0;
		$disabled = 0;

		$contextArray = explode(':', $parameters['context']);
		if (in_array('invoicelist', $contextArray)) {
			dol_syslog(get_class($this) . '::addMoreMassActions peppolpeppyrus 2');
			$this->resprints = '<option value="peppolZip"' . ($disabled ? ' disabled="disabled"' : '') . '>' . $langs->trans("PeppolMassActionZip") . '</option>';
		}

		if (!$error) {
			return 0;
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}

	/**
	 * Execute action beforePDFCreation
	 */
	public function beforePDFCreation($parameters, &$object, &$action)
	{
		global $conf, $user, $langs;
		global $hookmanager;
		$ret = 0;
		dol_syslog(get_class($this) . '::executeHooks action=' . $action);
		return $ret;
	}

	/**
	 * Execute action afterPDFCreation
	 */
	public function afterPDFCreation($parameters, &$pdfhandler, &$action)
	{
		if (!is_subclass_of($pdfhandler, 'ModelePDFFactures') && (get_class($pdfhandler) != 'ActionsDolipdf')) {
			dol_syslog(get_class($this) . '::executeHooks peppolpeppyrus pdfhandler class is ' . json_encode(get_class($pdfhandler)));
			return 0;
		}
		global $conf, $user, $langs, $db;
		global $hookmanager;
		global $mysoc;
		$ret = 0;
		$langs->loadLangs(array("peppol@peppolpeppyrus", "main", "bills"));

		dol_syslog(get_class($this) . '::executeHooks peppolpeppyrus action=' . $action);

		if ($parameters['object']->element != "facture") {
			dol_syslog(get_class($this) . '::executeHooks not a customer invoice but a ' . $parameters['object']->element . ', return');
			return -10;
		}

		if ($parameters['object']->status != Facture::STATUS_VALIDATED) {
			dol_syslog(get_class($this) . '::executeHooks not a validated invoice, status is ' . $parameters['object']->status . ', return');
			return -20;
		}

		$requestPath = $_SERVER['REQUEST_URI'];
		if (empty($action) && (strpos($requestPath, '/api/') > 0) && (strpos($requestPath, 'builddoc') > 0)) {
			dol_syslog(get_class($this) . '::executeHooks not a customer invoice but a ' . $parameters['object']->element . ', return');
			$action = 'builddoc';
		}

		$objFacture = $parameters['object'];
		$orig_pdf = $parameters['file'];

		dol_syslog("Peppolpeppyrus afterPDFCreation called with file name=" . $orig_pdf);

		if (isset($orig_pdf) && dol_is_file($orig_pdf)) {
			$inv = new Peppol($db, $objFacture);
			try {
				$ret = $inv->makePDF($orig_pdf);
			} catch (\Exception $e) {
				dol_syslog("Peppolpeppyrus exception occurs on makePDF : " . $e->getMessage(), LOG_ERR);
			}
		} else {
			dol_syslog("Peppolpeppyrus afterPDFCreation called with empty file name !", LOG_WARNING);
		}

		dol_syslog(get_class($this) . '::executeHooks peppolpeppyrus end action=' . $action);

		return $ret;
	}

	/**
	 * Execute action afterODTCreation
	 */
	public function afterODTCreation($parameters, &$pdfhandler, &$action)
	{
		global $conf, $user, $langs, $db;
		global $hookmanager;
		global $mysoc;
		$ret = 0;
		$langs->loadLangs(array("peppol@peppolpeppyrus", "main", "bills"));

		dol_syslog(get_class($this) . '::executeHooks peppolpeppyrus action=' . $action);

		if ($parameters['object']->element != "facture") {
			dol_syslog(get_class($this) . '::executeHooks not a customer invoice but a ' . $parameters['object']->element . ', return');
			return -10;
		}

		if ($parameters['object']->status != Facture::STATUS_VALIDATED) {
			dol_syslog(get_class($this) . '::executeHooks not a validated invoice, status is ' . $parameters['object']->status . ', return');
			return -20;
		}

		$objFacture = $parameters['object'];
		$orig_file = $parameters['file'];
		$pdf_file = str_replace('.odt', '.pdf', $parameters['file']);

		dol_syslog("Peppolpeppyrus afterODTCreation called with file name=" . $orig_file);

		if (isset($orig_file) && dol_is_file($orig_file)) {
			if (dol_is_file($pdf_file)) {
				$inv = new Peppol($db, $objFacture);
				try {
					$ret = $inv->makePDF($orig_file);
				} catch (Exception $e) {
					dol_syslog("Peppolpeppyrus exception occurs on makePDF : " . $e->getMessage(), LOG_ERR);
				}
			} else {
				dol_syslog(get_class($this) . '::executeHooks pdf file does not exists, peppolpeppyrus NEED a pdf file as input, return');
				setEventMessage($langs->trans("PeppolErrorNeedPDF"), 'warnings');
				return -30;
			}
		} else {
			dol_syslog("Peppolpeppyrus afterODTCreation called with empty file name !", LOG_WARNING);
		}

		dol_syslog(get_class($this) . '::executeHooks peppolpeppyrus end action=' . $action);

		return $ret;
	}

	/**
	 * Overloading the loadDataForCustomReports function
	 */
	public function loadDataForCustomReports($parameters, &$action, $hookmanager)
	{
		global $conf, $user, $langs;
		$langs->load("peppol@peppolpeppyrus");

		$this->results = array();

		$head = array();
		$h = 0;

		if ($parameters['tabfamily'] == 'peppolpeppyrus') {
			$head[$h][0] = dol_buildpath('/module/index.php', 1);
			$head[$h][1] = $langs->trans("Home");
			$head[$h][2] = 'home';
			$h++;

			$this->results['title'] = $langs->trans("Peppolpeppyrus");
			$this->results['picto'] = 'peppol@peppolpeppyrus';
		}

		$head[$h][0] = 'customreports.php?objecttype=' . $parameters['objecttype'] . (empty($parameters['tabfamily']) ? '' : '&tabfamily=' . $parameters['tabfamily']);
		$head[$h][1] = $langs->trans("CustomReports");
		$head[$h][2] = 'customreports';

		$this->results['head'] = $head;

		return 1;
	}

	/**
	 * Overloading the restrictedArea function
	 */
	public function restrictedArea($parameters, &$action, $hookmanager)
	{
		global $user;

		if ($parameters['features'] == 'myobject') {
			if ($user->rights->peppolpeppyrus->myobject->read) {
				$this->results['result'] = 1;
				return 1;
			} else {
				$this->results['result'] = 0;
				return 1;
			}
		}

		return 0;
	}

	/**
	 * getFormMail : auto join pdf and peppol files
	 */
	public function getFormMail($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $db;

		if ($object->param['models'] !== "facture_send") {
			return 0;
		}

		$id = str_replace('inv', '', $object->trackid);

		$obj = new Facture($db);
		$obj->fetch($id);

		$keytoavoidconflict = '';
		if (versioncompare(versiondolibarrarray(), array(4, 0, -3)) >= 0) {
			$keytoavoidconflict = empty($parameters['trackid']) ? '' : '-' . $parameters['trackid'];
		}

		if ((GETPOST('action', 'aZ09') == 'presend' && GETPOST('mode') == 'init') || (GETPOST('modelmailselected', 'int') && ! GETPOST('removedfile', 'alpha'))) {
			$listofpaths = (! empty($_SESSION["listofpaths" . $keytoavoidconflict])) ? explode(';', $_SESSION["listofpaths" . $keytoavoidconflict]) : array();
			$listofnames = (! empty($_SESSION["listofnames" . $keytoavoidconflict])) ? explode(';', $_SESSION["listofnames" . $keytoavoidconflict]) : array();
			$listofmimes = (! empty($_SESSION["listofmimes" . $keytoavoidconflict])) ? explode(';', $_SESSION["listofmimes" . $keytoavoidconflict]) : array();

			$path = $conf->facture->dir_output . '/' . $obj->ref . "/";
			$fileList = dol_dir_list($path, 'files', 0);
			foreach ($fileList as $fileParams) {
				$file = $fileParams['fullname'];
				$pdforxml = '/(xml|pdf)$/';
				if (! in_array($file, $listofpaths) && preg_match($pdforxml, $file, $matches) == 1) {
					if (preg_match('/SPECIMEN/', $file, $matches) == 1) {
						dol_syslog("exclude specimen file from file list");
					} else {
						$listofpaths[] = $file;
						$listofnames[] = basename($file);
						$listofmimes[] = dol_mimetype($file);
					}
				}
			}

			$_SESSION["listofpaths" . $keytoavoidconflict] = join(';', $listofpaths);
			$_SESSION["listofnames" . $keytoavoidconflict] = join(';', $listofnames);
			$_SESSION["listofmimes" . $keytoavoidconflict] = join(';', $listofmimes);
		}

		return 0;
	}

	/**
	 * Overloading the formObjectOptions function
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;
		$langs->load("peppol@peppolpeppyrus");

		$contextArray = explode(':', $parameters['context']);
		if (in_array('thirdpartycard', $contextArray)) {
			if (empty($action) || $action == 'view') {
				$id = $object->array_options['options_peppol_id'] ?? '';
				if ($id != '') {
					$object->array_options['options_peppol_id'] .= " <a href='https://directory.peppol.eu/public/?action=view&participant=iso6523-actorid-upis%3A%3A" . urlencode($id) . "' target='_blank'>" . $langs->trans("CheckPeppolIDonPublicDirectory") . "</a>";
				}
			}
		}
		return 0;
	}

	/**
	 * Overloading the addMoreActionsButtons function
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0;

		dol_syslog("PEPPOLPEPPYRUS HOOK addMoreActionsButtons called - context: " . $parameters['context']);

		if (empty($object->id)) {
			return -1;
		}

		$contextArray = explode(':', $parameters['context']);
		if (in_array('invoicecard', $contextArray)) {
			if ($object->status == Facture::STATUS_VALIDATED) {
				$classAction = "butAction";
				if (!empty($object->array_options['options_peppol_id'])) {
					$classAction = "butActionRefused";
				}
				print '<div class="inline-block divButAction"><a class="' . $classAction . ' classfortooltip" title="' . $langs->trans('peppolBtnSendTooltip') . '" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=peppolSendFile"><i class=\"fa fa-paper-plane\"></i>' . $langs->trans('peppolBtnSend') . '</a></div>';
				print '<div class="inline-block divButAction"><a class="butAction classfortooltip" title="' . $langs->trans('peppolBtnCheckTooltip') . '" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=peppolCheck"><i class=\"fa fa-paper-plane\"></i>' . $langs->trans('peppolBtnCheck') . '</a></div>';
			}
		}
		if (in_array('thirdpartycard', $contextArray)) {
			$langs->load("peppol@peppolpeppyrus");
			$urlto = dol_buildpath("/peppolpeppyrus/search.php", 1) . "?socid=" . $object->id;
			print '<div class="inline-block divButAction"><a class="butAction classfortooltip" title="' . $langs->trans("peppolFindPeppolIDPopupTitle") . '" href="' . $urlto . '" target="_blank">' . $langs->trans("peppolBtnFindPeppolID") . '</a></div>';
		}

		if (! $error) {
			return 0;
		} else {
			array_push($this->errors, 'Error message 002');
			return -1;
		}
	}

	/**
	 * Overloading the formConfirm function
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$ret = 0;
		$error = 0;
		$errors = array();
		$formconfirm = '';
		$form = new Form($this->db);

		if ($action == 'peppolCheck') {
			peppolCheckAccessPoint($object);
		}
		if ($action == 'peppolSendFile') {
			$filearray = peppolListOfFilesLinkedTo($object);
			if (count($filearray) == 0) {
				dol_syslog("peppolpeppyrus: error there is no file linked to that object", LOG_ERR);
				setEventMessages($langs->trans("peppolNoPdfFilesAssociated"), null, 'warnings');
				return -1;
			}

			$height = 210;
			$formquestion = array(
				array(
					'name' => 'selectFilename',
					'label' => $langs->trans('peppolChooseFilePopup'),
					'type' => 'other',
					'value' => $form->selectarray('selectFilename', $filearray, 'selectFilename', 0, 0, 0, '', 0, 0, 0, '', '', 0, '', 0, 0)
				)
			);

			$formconfirm = $form->formconfirm(
				$_SERVER["PHP_SELF"] . '?id=' . $object->id,
				$langs->trans('peppolBtnSend'),
				$langs->trans('ConfirmPeppol', $object->ref),
				'confirm_peppolSendFile',
				$formquestion,
				0,
				1,
				$height
			);
		}
		if (! $error) {
			$this->resprints = $formconfirm;
			return $ret;
		} else {
			$this->errors = $errors;
			return -1;
		}
	}
}
