<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * 	\defgroup   peppolpeppyrus     Module Peppol Peppyrus
 *  \brief      Peppol Peppyrus module descriptor.
 *
 *  \file       htdocs/peppolpeppyrus/core/modules/modPeppolpeppyrus.class.php
 *  \ingroup    peppolpeppyrus
 *  \brief      Description and activation file for module Peppol Peppyrus
 */
include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module Peppol Peppyrus
 */
class modPeppolpeppyrus extends DolibarrModules
{
	public $url_last_version;
	public $tabs;
	public $dictionaries;

	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 436181; // Different ID from original peppol module

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'peppolpeppyrus';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "financial";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModulePeppolpeppyrusName' not found (Peppolpeppyrus is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModulePeppolpeppyrusDesc' not found (Peppolpeppyrus is name of module).
		$this->description = "ModulePeppolpeppyrusDesc";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "ModulePeppolpeppyrusDesc";

		// Author
		$this->editor_name = '';
		$this->editor_url = 'https://github.com/RealRidrick/dolibarr_peppol-peppyrus';

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated' or a version string like 'x.y.z'
		$this->version = '2.13.1';
		// File with the latest version number (VERSION in the public repository), read by checkForUpdate()
		$this->url_last_version = 'https://raw.githubusercontent.com/RealRidrick/dolibarr_peppol-peppyrus/main/VERSION';

		// Key used in llx_const table to save module status enabled/disabled (where PEPPOLPEPPYRUS is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'peppol@peppolpeppyrus';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 0,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				//    '/peppolpeppyrus/css/peppol.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/peppolpeppyrus/js/peppol.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			'hooks' => array(
				'pdfgeneration',
				'formmail',
				'invoicelist',
				'invoicecard',
				'doMassActions',
				'thirdpartycard',
				'thirdpartycomm',
				'thirdpartysupplier',
				'globalcard',
				'odtgeneration',
				//   'data' => array(
				//       'hookcontext1',
				//       'hookcontext2',
				//   ),
				//   'entity' => '0',
			),
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Data directory per entity: DOL_DATA_ROOT/peppol (entity 1) or DOL_DATA_ROOT/<entity>/peppol
			'dir' => array('output' => 'peppol'),
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/peppol/temp","/peppol/subdir");
		$this->dirs = array("/peppol/temp");

		// Config pages. Put here list of php page, stored into peppolpeppyrus/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@peppolpeppyrus");

		// Dependencies
		// A condition to hide module
		$this->hidden = false;
		// List of module class names as string that must be enabled if this module is enabled. Example: array('always1'=>'modModuleToEnable1','always2'=>'modModuleToEnable2', 'FR1'=>'modModuleToEnableFR'...)
		$this->depends = array('always1' => "modSociete", 'always2' => "modFacture");
		$this->requiredby = array(); // List of module class names as string to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array(); // List of module class names as string this module is in conflict with. Example: array('modModuleToDisable1', ...)

		// The language file dedicated to your module
		$this->langfiles = array("peppol@peppolpeppyrus");

		// Prerequisites
		$this->phpmin = array(7, 1); // Minimum version of PHP required by module (and by the einvoicing library)
		$this->need_dolibarr_version = array(18, 0); // Minimum version of Dolibarr required by module (supported: 18.0 up to 24.0.2)

		// Messages at activation
		$this->warnings_activation = array(); // Warning to show when we activate module. array('always'='text') or array('FR'='textfr','ES'='textes'...)
		$this->warnings_activation_ext = array(); // Warning to show when we activate an external module. array('always'='text') or array('FR'='textfr','ES'='textes'...)
		//$this->automatic_activation = array('FR'=>'PeppolWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		//$this->always_enabled = true;								// If true, can't be disabled

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('PEPPOL_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1),
		//                             2 => array('PEPPOL_MYNEWCONST2', 'chaine', 'myvalue', 'This is another constant to add', 0, 'current', 1)
		// );
		$this->const = array();

		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mère ou revendeur'
		)*/

		if (!isset($conf->peppolpeppyrus) || !isset($conf->peppolpeppyrus->enabled)) {
			$conf->peppolpeppyrus = new stdClass();
			$conf->peppolpeppyrus->enabled = 0;
		}

		// Array to add new pages in new tabs
		$this->tabs = array();
		$this->tabs[] = array('data' => 'invoice:+tabPeppol:PeppolTab:peppol@peppolpeppyrus:1:/peppolpeppyrus/peppol_tab.php?objectType=invoice&id=__ID__');

		// Example:
		// $this->tabs[] = array('data'=>'objecttype:+tabname1:Title1:mylangfile@peppol:$user->hasRight('peppol', 'read'):/peppol/mynewtab1.php?id=__ID__');  					// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data'=>'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@peppol:$user->hasRight('othermodule', 'read'):/peppol/mynewtab2.php?id=__ID__',  	// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data'=>'objecttype:-tabname:NU:conditiontoremove');                                                     										// To remove an existing tab identified by code tabname
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'invoice_supplier' to add a tab in supplier invoice view
		// 'member'           to add a tab in fundation member view
		// 'opensurveypoll'	  to add a tab in opensurvey poll view
		// 'order'            to add a tab in customer order view
		// 'order_supplier'   to add a tab in supplier order view
		// 'payment'		  to add a tab in payment view
		// 'payment_supplier' to add a tab in supplier payment view
		// 'product'          to add a tab in product view
		// 'propal'           to add a tab in propal view
		// 'project'          to add a tab in project view
		// 'stock'            to add a tab in stock view
		// 'thirdparty'       to add a tab in third party view
		// 'user'             to add a tab in user view

		// Dictionaries
		$this->dictionaries = array();
		/* Example:
		$this->dictionaries=array(
			'langs'=>'peppol@peppolpeppyrus',
			// List of tables we want to see into dictonnary editor
			'tabname'=>array(MAIN_DB_PREFIX."table1", MAIN_DB_PREFIX."table2", MAIN_DB_PREFIX."table3"),
			// Label of tables
			'tablib'=>array("Table1", "Table2", "Table3"),
			// Request to select fields
			'tabsql'=>array('SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table1 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table2 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table3 as f'),
			// Sort order
			'tabsqlsort'=>array("label ASC", "label ASC", "label ASC"),
			// List of fields (result of select to show dictionary)
			'tabfield'=>array("code,label", "code,label", "code,label"),
			// List of fields (list of fields to edit a record)
			'tabfieldvalue'=>array("code,label", "code,label", "code,label"),
			// List of fields (list of fields for insert)
			'tabfieldinsert'=>array("code,label", "code,label", "code,label"),
			// Name of columns with primary key (try to always name it 'rowid')
			'tabrowid'=>array("rowid", "rowid", "rowid"),
			// Condition to show each dictionary
			'tabcond'=>array($conf->peppolpeppyrus->enabled, $conf->peppolpeppyrus->enabled, $conf->peppolpeppyrus->enabled)
		);
		*/

		// Boxes/Widgets
		// Add here list of php file(s) stored in peppolpeppyrus/core/boxes that contains a class to show a widget.
		$this->boxes = array(
			//  0 => array(
			//      'file' => 'peppolwidget1.php@peppolpeppyrus',
			//      'note' => 'Widget provided by Peppol',
			//      'enabledbydefaulton' => 'Home',
			//  ),
			//  ...
		);

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		$this->cronjobs = array(
			0 => array(
				'label' => 'Peppol: weekly check of customers and suppliers in the Peppol directory',
				'jobtype' => 'method',
				'class' => '/peppolpeppyrus/class/peppolcandidatecheck.class.php',
				'objectname' => 'PeppolCandidateCheck',
				'method' => 'runWeeklyCheck',
				'parameters' => '',
				'comment' => 'Searches customers and suppliers without a Peppol ID in the Peppol directory by VAT number and registration number (not by name). Results: Third parties > Peppol candidates.',
				'frequency' => 1,
				'unitfrequency' => 604800,
				'status' => 1,
				'test' => 'isModEnabled("peppolpeppyrus")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'Peppol: daily fetch of incoming documents from the access point',
				'jobtype' => 'method',
				'class' => '/peppolpeppyrus/class/peppolinboxfetch.class.php',
				'objectname' => 'PeppolInboxFetch',
				'method' => 'runDailyFetch',
				'parameters' => '',
				'comment' => 'Fetches the new documents (supplier invoices) from the access point into Billing > Peppol > Incoming documents. Does not create or validate supplier invoices. The user of the job needs the right Peppol: write.',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("peppolpeppyrus")',
				'priority' => 50,
			),
			2 => array(
				'label' => 'Peppol: automatic sending of validated customer invoices',
				'jobtype' => 'method',
				'class' => '/peppolpeppyrus/class/peppolautosend.class.php',
				'objectname' => 'PeppolAutoSend',
				'method' => 'runAutoSend',
				'parameters' => '',
				'comment' => 'Off by default; switched on and off on the setup page of the module. Runs every hour and sends only at the hours set up there, only invoices validated after switching on, of customers with a Peppol ID. The user of the job needs the rights Peppol: write and create invoices.',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => 'isModEnabled("peppolpeppyrus")',
				'priority' => 60,
			),
			// Since 2.12.0: invoices Peppyrus could not deliver (folder FAILED)
			3 => array(
				'label' => 'Peppol: daily check of invoices the access point could not deliver',
				'jobtype' => 'method',
				'class' => '/peppolpeppyrus/class/peppolfailedcheck.class.php',
				'objectname' => 'PeppolFailedCheck',
				'method' => 'runDailyCheck',
				'parameters' => '',
				'comment' => 'Reads the folder FAILED at the access point. For each own invoice that could not be delivered: one line "failed" on the Peppol tab of the invoice and, with the agenda on, an event on the invoice. The user of the job needs the right Peppol: read.',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 1,
				'test' => 'isModEnabled("peppolpeppyrus")',
				'priority' => 50,
			),
		);
		// Example: $this->cronjobs=array(
		//    0=>array('label'=>'My label', 'jobtype'=>'method', 'class'=>'/dir/class/file.class.php', 'objectname'=>'MyClass', 'method'=>'myMethod', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'$conf->peppolpeppyrus->enabled', 'priority'=>50),
		//    1=>array('label'=>'My label', 'jobtype'=>'command', 'command'=>'', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>1, 'unitfrequency'=>3600*24, 'status'=>0, 'test'=>'$conf->peppolpeppyrus->enabled', 'priority'=>50)
		// );

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		// Add here entries to declare new permissions
		/* BEGIN MODULEBUILDER PERMISSIONS */
		$this->rights[$r][0] = (int) ($this->numero . '01'); // Permission id: <numero><nn>, never change (granted rights are stored by number)
		$this->rights[$r][1] = 'Read Peppol objects'; // Permission label
		$this->rights[$r][4] = 'read'; // In php code: $user->hasRight('peppolpeppyrus', '<level1>')
		$r++;
		$this->rights[$r][0] = (int) ($this->numero . '02'); // Permission id: <numero><nn>, never change (granted rights are stored by number)
		$this->rights[$r][1] = 'Create/Update Peppol objects'; // Permission label
		$this->rights[$r][4] = 'write'; // In php code: $user->hasRight('peppolpeppyrus', '<level1>')
		$r++;
		$this->rights[$r][0] = (int) ($this->numero . '03'); // Permission id: <numero><nn>, never change (granted rights are stored by number)
		$this->rights[$r][1] = 'Delete Peppol objects'; // Permission label
		$this->rights[$r][4] = 'delete'; // In php code: $user->hasRight('peppolpeppyrus', '<level1>')
		$r++;
		$this->rights[$r][0] = (int) ($this->numero . '04'); // Permission id: <numero><nn>, never change (granted rights are stored by number)
		$this->rights[$r][1] = 'Import Peppol invoices (supplier)'; // Permission label
		$this->rights[$r][4] = 'import'; // In php code: $user->hasRight('peppolpeppyrus', '<level1>')
		$r++;
		/* END MODULEBUILDER PERMISSIONS */

		// Main menu entries to add: one entry "Peppol" in the left menu of Billing, with the pages under it
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = [
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'PeppolMenu',
			'prefix' => 'fa-paper-plane',
			'mainmenu' => 'billing',
			'leftmenu' => 'peppol',
			'url' => '/peppolpeppyrus/index.php',
			'langs' => 'peppol@peppolpeppyrus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("peppolpeppyrus")',
			'perms' => '$user->hasRight("peppolpeppyrus", "read") || $user->hasRight("peppolpeppyrus", "import")',
			'target' => '',
			'user' => 0, // internal users only
		];
		// Documents received from the access point (supplier invoices)
		$this->menu[$r++] = [
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=peppol',
			'type' => 'left',
			'titre' => 'peppolMenuDocuments',
			'mainmenu' => 'billing',
			'leftmenu' => 'peppol_documents',
			'url' => '/peppolpeppyrus/peppolimport_list.php',
			'langs' => 'peppol@peppolpeppyrus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("peppolpeppyrus")',
			'perms' => '$user->hasRight("peppolpeppyrus", "import")',
			'target' => '',
			'user' => 0,
		];
		// Customers and suppliers found on Peppol (weekly check)
		$this->menu[$r++] = [
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=peppol',
			'type' => 'left',
			'titre' => 'peppolCandidatesMenu',
			'mainmenu' => 'billing',
			'leftmenu' => 'peppol_candidates',
			'url' => '/peppolpeppyrus/peppol_candidates.php',
			'langs' => 'peppol@peppolpeppyrus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("peppolpeppyrus")',
			'perms' => '$user->hasRight("peppolpeppyrus", "read") && $user->hasRight("societe", "lire")',
			'target' => '',
			'user' => 0,
		];
		// Since 2.12.0: outgoing invoices with their status at Peppyrus (waiting, sent, failed)
		$this->menu[$r++] = [
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=peppol',
			'type' => 'left',
			'titre' => 'peppolOutgoingMenu',
			'mainmenu' => 'billing',
			'leftmenu' => 'peppol_outgoing',
			'url' => '/peppolpeppyrus/peppol_outgoing.php',
			'langs' => 'peppol@peppolpeppyrus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("peppolpeppyrus")',
			'perms' => '$user->hasRight("peppolpeppyrus", "read") && $user->hasRight("facture", "lire")',
			'target' => '',
			'user' => 0,
		];
		// Log of the exchanges with the access point
		$this->menu[$r++] = [
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=peppol',
			'type' => 'left',
			'titre' => 'PeppolList',
			'mainmenu' => 'billing',
			'leftmenu' => 'peppol_log',
			'url' => '/peppolpeppyrus/peppol_list.php',
			'langs' => 'peppol@peppolpeppyrus',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("peppolpeppyrus")',
			'perms' => '$user->hasRight("peppolpeppyrus", "import")',
			'target' => '',
			'user' => 0,
		];

		// Add here entries to declare new menus
		/* BEGIN MODULEBUILDER TOPMENU */
		/* END MODULEBUILDER TOPMENU */
		/* BEGIN MODULEBUILDER LEFTMENU MYOBJECT */
		/* END MODULEBUILDER LEFTMENU MYOBJECT */
		// Exports profiles provided by this module
		$r = 1;
		/* BEGIN MODULEBUILDER EXPORT MYOBJECT */
		/* END MODULEBUILDER EXPORT MYOBJECT */

		// Imports profiles provided by this module
		$r = 1;
		/* BEGIN MODULEBUILDER IMPORT MYOBJECT */
		/* END MODULEBUILDER IMPORT MYOBJECT */
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int             	1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		$result = $this->_load_tables('/peppolpeppyrus/sql/');
		if ($result < 0) return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')

		// Create extrafields during init
		include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$result = $extrafields->addExtraField('peppol_id',  $langs->trans('PeppolSpecificID'),    		'varchar', 1101, 100, 'thirdparty', 0, 0, '', null, 1, '', 1, $langs->trans('PeppolSpecificIDTooltip'), '', '', 'peppol@peppolpeppyrus', '$conf->peppolpeppyrus->enabled');
		$result = $extrafields->addExtraField('peppol_id',  $langs->trans('PeppolSpecificInvoiceID'),   'varchar', 1101, 100, 'facture', 0, 0, '', null, 0, '', 5, $langs->trans('PeppolSpecificInvoiceIDTooltip'), '', '', 'peppol@peppolpeppyrus', '$conf->peppolpeppyrus->enabled');
		$result = $extrafields->addExtraField('peppol_id',  $langs->trans('PeppolSpecificInvoiceID'),   'varchar', 1101, 100, 'facture_fourn', 0, 0, '', null, 0, '', 5, $langs->trans('PeppolSpecificInvoiceIDTooltip'), '', '', 'peppol@peppolpeppyrus', '$conf->peppolpeppyrus->enabled');

		// Since 2.12.0 no webhook any more (Peppyrus has no webhooks): remove its secret key
		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		dolibarr_del_const($this->db, 'PEPPOL_AP_WEBHOOK_KEY', $conf->entity);

		// Permissions
		$this->remove($options);

		$sql = array();

		$sql[] = "UPDATE " . MAIN_DB_PREFIX . "extrafields SET label='PeppolSpecificInvoiceID',help='PeppolSpecificInvoiceIDTooltip',list=5 WHERE elementtype='facture' AND name='peppol_id'";

		dolibarr_set_const($this->db, 'PEPPOL_MODULE_VERSION', $this->version, 'chaine', 0, 'Active module version', $conf->entity);
		$result = $this->_init($sql, $options);
		if ($result > 0) {
			// The scheduled jobs get the status chosen on the setup page (deactivating removes the jobs)
			dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');
			peppolApplyCronSettings($this->db);
		}
		return $result;
	}

	/**
	 *  Function called when module is disabled.
	 *  Remove from database constants, boxes and permissions from Dolibarr database.
	 *  Data directories are not deleted
	 *
	 *  @param      string	$options    Options when enabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}

	/**
	 * Check whether a newer version exists (shown in the module list).
	 * Same as DolibarrModules::checkForUpdate(), but HTTPS only and only an answer with HTTP status 200 counts:
	 * the built-in version would also take a short error page as version number. The repository is public,
	 * so no key is sent.
	 *
	 * @return int	1 newer version available, 0 not (or no URL), -1 could not be checked
	 */
	public function checkForUpdate()
	{
		require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
		if (empty($this->url_last_version)) {
			return 0;
		}
		$result = $this->fetchLastVersion();
		$content = isset($result['content']) ? trim((string) $result['content']) : '';
		if ((int) ($result['http_code'] ?? 0) !== 200 || $content === '' || strlen($content) >= 30) {
			return -1;
		}
		// Remote content: keep only the characters of a version number
		$this->lastVersion = preg_replace('/[^a-zA-Z0-9_\.\-]+/', '', $content);
		$this->needUpdate = (version_compare($this->lastVersion, $this->version) > 0);
		return $this->needUpdate ? 1 : 0;
	}

	/**
	 * Read the file with the latest version number: HTTPS only, no redirects
	 *
	 * @return array{http_code?:int,content?:string}	Result of getURLContent()
	 */
	protected function fetchLastVersion()
	{
		return getURLContent($this->url_last_version, 'GET', '', 0, array(), array('https'), 0);
	}
}
