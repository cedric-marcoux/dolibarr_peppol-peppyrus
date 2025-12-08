<?php

/**
 * \file        class/peppolimport.class.php
 * \ingroup     scaninvoices
 * \brief       This file is a CRUD class file for Peppolimport (Create/Read/Update/Delete)
 */

namespace custom\peppolpeppyrus;

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/includes/sabre/autoload.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security2.lib.php';

use Sabre\DAV\Client;

// require_once __DIR__.'/../lib/scaninvoices.lib.php';
dol_include_once('/scaninvoices/lib/scaninvoices.lib.php');

/**
 * Class for Peppolimport
 */
class Peppolimport extends \CommonObject
{

	public $socid;
	public $labelStatusShort;
	public $labelStatus;
	public $output;
	public $user_validation;
	public $oldref;
	public $user_creation;
	public $user_creation_id;
	public $user_modification_id;
	public $user_validation_id;


	/**
	 * @var string ID of module.
	 */
	public $module = 'peppol';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'peppolimport';

	/**
	 * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
	 */
	public $table_element = 'peppol_peppolimport';

	/**
	 * @var int  Does this object support multicompany module ?
	 * 0=No test on entity, 1=Test with field entity, 'field@table'=Test with link by field@table
	 */
	public $ismultientitymanaged = 1;

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var string String with name of icon for peppolimport. Must be the part after the 'object_' into object_peppolimport.png
	 */
	public $picto = 'fa-file-upload';

	const STATUS_DRAFT = 0; //WAITING
	const STATUS_VALIDATED = 1; // ? analyzed, but could be success or partial success or fail
	const STATUS_CLOSED = 2; // full success
	const STATUS_ERROR = 3; // what kind of error ?
	const STATUS_HALFSUCCESS = 5; //supplier but not invoice for example
	const STATUS_CANCELED = 9;

	/**
	 *  'type' field format ('integer', 'integer:ObjectClass:PathToClass[:AddCreateButtonOrNot[:Filter]]', 'sellist:TableName:LabelFieldName[:KeyFieldName[:KeyFieldParent[:Filter]]]', 'varchar(x)', 'double(24,8)', 'real', 'price', 'text', 'text:none', 'html', 'date', 'datetime', 'timestamp', 'duration', 'mail', 'phone', 'url', 'password')
	 *         Note: Filter can be a string like "(t.ref:like:'SO-%') or (t.date_creation:<:'20160101') or (t.nature:is:NULL)"
	 *  'label' the translation key.
	 *  'picto' is code of a picto to show before value in forms
	 *  'enabled' is a condition when the field must be managed (Example: 1 or 'getDolGlobalString('MY_SETUP_PARAM'))
	 *  'position' is the sort order of field.
	 *  'notnull' is set to 1 if not null in database. Set to -1 if we must set data to null if empty ('' or 0).
	 *  'visible' says if field is visible in list (Examples: 0=Not visible, 1=Visible on list and create/update/view forms, 2=Visible on list only, 3=Visible on create/update/view form only (not list), 4=Visible on list and update/view form only (not create). 5=Visible on list and view only (not create/not update). Using a negative value means field is not shown by default on list but can be selected for viewing)
	 *  'noteditable' says if field is not editable (1 or 0)
	 *  'default' is a default value for creation (can still be overwrote by the Setup of Default Values if field is editable in creation form). Note: If default is set to '(PROV)' and field is 'ref', the default value will be set to '(PROVid)' where id is rowid when a new record is created.
	 *  'index' if we want an index in database.
	 *  'foreignkey'=>'tablename.field' if the field is a foreign key (it is recommanded to name the field fk_...).
	 *  'searchall' is 1 if we want to search in this field when making a search from the quick search button.
	 *  'isameasure' must be set to 1 if you want to have a total on list for this field. Field type must be summable like integer or double(24,8).
	 *  'css' and 'cssview' and 'csslist' is the CSS style to use on field. 'css' is used in creation and update. 'cssview' is used in view mode. 'csslist' is used for columns in lists. For example: 'maxwidth200', 'wordbreak', 'tdoverflowmax200'
	 *  'help' is a 'TranslationString' to use to show a tooltip on field. You can also use 'TranslationString:keyfortooltiponlick' for a tooltip on click.
	 *  'showoncombobox' if value of the field must be visible into the label of the combobox that list record
	 *  'disabled' is 1 if we want to have the field locked by a 'disabled' attribute. In most cases, this is never set into the definition of $fields into class, but is set dynamically by some part of code.
	 *  'arraykeyval' to set list of value if type is a list of predefined values. For example: array("0"=>"Draft","1"=>"Active","-1"=>"Cancel")
	 *  'autofocusoncreate' to have field having the focus on a create form. Only 1 field should have this property set to 1.
	 *  'comment' is not used. You can store here any text of your choice. It is not used by application.
	 *
	 *  Note: To have value dynamic, you can set value to 0 in definition and edit the value on the fly into the constructor.
	 */

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @var array  Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => '1', 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => '1', 'index' => 1, 'css' => 'left', 'comment' => "Id"),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'default' => 1, 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 20, 'index' => 1),
		'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => '1', 'position' => 10, 'notnull' => 1, 'visible' => 4, 'noteditable' => '1', 'default' => '(PROV)', 'index' => 1, 'searchall' => 1, 'showoncombobox' => '1', 'comment' => "Reference of object", 'csslist' => 'nowraponall'),
		'filename' => array('type' => 'varchar(255)', 'label' => 'Filename', 'enabled' => '1', 'position' => 30, 'notnull' => 0, 'visible' => 1, 'searchall' => 1, 'css' => 'minwidth300', 'help' => "Help text", 'showoncombobox' => '1', 'csslist' => 'tdoverflowmax200'),
		'sha1' => array('type' => 'varchar(40)', 'label' => 'SHA1', 'enabled' => '1', 'position' => 31, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => "SHA1 CheckSum",),
		'peppolid' => array('type' => 'varchar(255)', 'label' => 'peppolid', 'enabled' => '1', 'position' => 40, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => "",),
		'internalNumber' => array('type' => 'integer', 'label' => 'internalNumber', 'default' => 1, 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 41, 'index' => 1),
		'senderScheme' => array('type' => 'varchar(255)', 'label' => 'senderScheme', 'enabled' => '1', 'position' => 51, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'senderID' => array('type' => 'varchar(255)', 'label' => 'senderID', 'enabled' => '1', 'position' => 52, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'receiverScheme' => array('type' => 'varchar(255)', 'label' => 'receiverScheme', 'enabled' => '1', 'position' => 53, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'receiverID' => array('type' => 'varchar(255)', 'label' => 'receiverID', 'enabled' => '1', 'position' => 54, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'c1CountryCode' => array('type' => 'varchar(255)', 'label' => 'c1CountryCode', 'enabled' => '1', 'position' => 55, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'c2Timestamp' => array('type' => 'varchar(255)', 'label' => 'c2Timestamp', 'enabled' => '1', 'position' => 56, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'c2SeatID' => array('type' => 'varchar(255)', 'label' => 'c2SeatID', 'enabled' => '1', 'position' => 57, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'c2MessageID' => array('type' => 'varchar(255)', 'label' => 'c2MessageID', 'enabled' => '1', 'position' => 58, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'c3IncomingUniqueID' => array('type' => 'varchar(255)', 'label' => 'c3IncomingUniqueID', 'enabled' => '1', 'position' => 59, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'c3MessageID' => array('type' => 'varchar(255)', 'label' => 'c3MessageID', 'enabled' => '1', 'position' => 60, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'c3Timestamp' => array('type' => 'varchar(255)', 'label' => 'c3Timestamp', 'enabled' => '1', 'position' => 61, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'conversationID' => array('type' => 'varchar(255)', 'label' => 'conversationID', 'enabled' => '1', 'position' => 62, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'sbdhInstanceID' => array('type' => 'varchar(255)', 'label' => 'sbdhInstanceID', 'enabled' => '1', 'position' => 63, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'processScheme' => array('type' => 'varchar(255)', 'label' => 'processScheme', 'enabled' => '1', 'position' => 64, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'processValue' => array('type' => 'varchar(255)', 'label' => 'processValue', 'enabled' => '1', 'position' => 65, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'documentTypeScheme' => array('type' => 'varchar(255)', 'label' => 'documentTypeScheme', 'enabled' => '1', 'position' => 66, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'documentTypeValue' => array('type' => 'varchar(255)', 'label' => 'documentTypeValue', 'enabled' => '1', 'position' => 67, 'notnull' => 0, 'visible' => -1, 'css' => 'minwidth300', 'help' => '',),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => '1', 'position' => 503, 'notnull' => 0, 'visible' => -2,),
		'fk_supplier' => array('type' => 'integer:Societe:societe/class/societe.class.php', 'label' => 'Supplier', 'enabled' => '1', 'position' => 504, 'alwayseditable' => 1, 'noteditable' => 0, 'notnull' => 0, 'visible' => 4, 'csslist' => 'tdoverflowmax150'),
		'fk_invoice' => array('type' => 'integer:FactureFournisseur:fourn/class/fournisseur.facture.class.php', 'label' => 'Invoice', 'enabled' => '1', 'position' => 505, 'notnull' => 0, 'visible' => 4, 'css' => 'maxwidth500', 'csslist' => 'tdoverflowmax150',),
		'fk_user_creat' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => '1', 'position' => 510, 'notnull' => 1, 'visible' => -2, 'foreignkey' => 'user.rowid',),
		'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => '1', 'position' => 511, 'notnull' => -1, 'visible' => -2,),
		'import_key' => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => '1', 'position' => 1000, 'notnull' => -1, 'visible' => 4,),
		'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => '1', 'position' => 1002, 'notnull' => 0, 'visible' => 4, 'index' => 1, 'arrayofkeyval' => array('' => '', self::STATUS_DRAFT => 'Waiting', self::STATUS_VALIDATED => 'Analyzed', self::STATUS_CLOSED => 'Success', self::STATUS_ERROR => 'Error',  self::STATUS_HALFSUCCESS => 'Partial', self::STATUS_CANCELED => 'Canceled'),),
	);
	public $rowid;
	public $ref;
	public $filename;
	public $sha1;
	public $peppolid;
	public $internalNumber;
	public $senderScheme;
	public $senderID;
	public $receiverScheme;
	public $receiverID;
	public $c1CountryCode;
	public $c2Timestamp;
	public $c2SeatID;
	public $c2MessageID;
	public $c3IncomingUniqueID;
	public $c3MessageID;
	public $c3Timestamp;
	public $conversationID;
	public $sbdhInstanceID;
	public $processScheme;
	public $processValue;
	public $documentTypeScheme;
	public $documentTypeValue;
	public $tms;
	public $fk_supplier;
	public $fk_invoice;
	public $fk_user_creat;
	public $fk_user_modif;
	public $import_key;
	public $status;
	// END MODULEBUILDER PROPERTIES


	// If this object has a subtable with lines

	// /**
	//  * @var string    Name of subtable line
	//  */
	// public $table_element_line = 'peppol_peppolimportline';

	// /**
	//  * @var string    Field with ID of parent key if this object has a parent
	//  */
	// public $fk_element = 'fk_peppolimport';

	// /**
	//  * @var string    Name of subtable class that manage subtable lines
	//  */
	// public $class_element_line = 'Peppolimportline';

	// /**
	//  * @var array	List of child tables. To test if we can delete object.
	//  */
	// protected $childtables = array();

	// /**
	//  * @var array    List of child tables. To know object to delete on cascade.
	//  *               If name matches '@ClassNAme:FilePathClass;ParentFkFieldName' it will
	//  *               call method deleteByParentField(parentId, ParentFkFieldName) to fetch and delete child object
	//  */
	// protected $childtablesoncascade = array('peppol_peppolimportdet');

	// /**
	//  * @var PeppolimportLine[]     Array of subtable lines
	//  */
	// public $lines = array();



	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database handler
	 */
	public function __construct(\DoliDB $db)
	{
		global $conf, $langs;

		$this->db = $db;

		if (empty(getDolGlobalString('MAIN_SHOW_TECHNICAL_ID')) && isset($this->fields['rowid'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (empty($conf->multicompany->enabled) && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		// Example to show how to set values of fields definition dynamically
		/*if ($user->rights->peppolpeppyrus->read) {
			$this->fields['myfield']['visible'] = 1;
			$this->fields['myfield']['noteditable'] = 0;
		}*/

		// Unset fields that are disabled
		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		// Translate some data of arrayofkeyval
		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}
		$this->status = Peppolimport::STATUS_DRAFT;

		if (((int) DOL_VERSION) < 20) {
			$this->picto = 'fa-file-upload';
		} else {
			$this->picto = 'fa-file-arrow-up';
		}
	}

	/**
	 * Create object into database
	 *
	 * @param  User $user      User that creates
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, Id of created object if OK
	 */
	public function create(\User $user, $notrigger = false)
	{
		dol_syslog("Peppol::Peppolimport: create status is : " . $this->status);
		if (null === $this->status) {
			dol_syslog("Peppol::Peppolimport: create status is null -> set to Draft");
			$this->status = self::STATUS_DRAFT;
		}

		dol_syslog("Peppol::Peppolimport: create object : " . json_encode($this));
		if (null === $this->peppolid) {
			return -2;
		}

		/** @phpstan-ignore-next-line */
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Clone an object into another one
	 *
	 * @param  	User 	$user      	User that creates
	 * @param  	int 	$fromid     Id of object to clone
	 * @return 	mixed 				New object created, <0 if KO
	 */
	public function createFromClone(\User $user, $fromid)
	{
		global $langs, $extrafields;
		$error = 0;

		dol_syslog(__METHOD__, LOG_DEBUG);

		$object = new self($this->db);

		$this->db->begin();

		// Load source object
		$result = $object->fetchCommon($fromid);
		if ($result > 0 && !empty($object->table_element_line)) {
			$object->fetchLines();
		}

		// get lines so they will be clone
		//foreach($this->lines as $line)
		//	$line->fetch_optionals();

		// Reset some properties
		unset($object->id);
		unset($object->fk_user_creat);
		unset($object->import_key);

		// Clear fields
		if (property_exists($object, 'ref')) {
			$object->ref = empty($this->fields['ref']['default']) ? "Copy_Of_" . $object->ref : $this->fields['ref']['default'];
		}
		if (property_exists($object, 'label')) {
			$object->label = empty($this->fields['label']['default']) ? $langs->trans("CopyOf") . " " . $object->label : $this->fields['label']['default'];
		}
		if (property_exists($object, 'status')) {
			$object->status = self::STATUS_DRAFT;
		}
		if (property_exists($object, 'date_creation')) {
			$object->date_creation = dol_now();
		}
		if (property_exists($object, 'date_modification')) {
			$object->date_modification = null;
		}
		// ...
		// Clear extrafields that are unique
		if (is_array($object->array_options) && count($object->array_options) > 0) {
			$extrafields->fetch_name_optionals_label($this->table_element);
			foreach ($object->array_options as $key => $option) {
				$shortkey = preg_replace('/options_/', '', $key);
				if (!empty($extrafields->attributes[$this->table_element]['unique'][$shortkey])) {
					//var_dump($key); var_dump($clonedObj->array_options[$key]); exit;
					unset($object->array_options[$key]);
				}
			}
		}

		// Create clone
		$object->context['createfromclone'] = 'createfromclone';
		$result = $object->createCommon($user);
		if ($result < 0) {
			$error++;
			$this->error = $object->error;
			$this->errors = $object->errors;
		}

		if (!$error) {
			// copy internal contacts
			if ($this->copy_linked_contact($object, 'internal') < 0) {
				$error++;
			}
		}

		if (!$error) {
			// copy external contacts if same company
			if (property_exists($this, 'socid') && $this->socid == $object->socid) {
				if ($this->copy_linked_contact($object, 'external') < 0) {
					$error++;
				}
			}
		}

		unset($object->context['createfromclone']);

		// End
		if (!$error) {
			$this->db->commit();
			return $object;
		} else {
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param int    $id   Id object
	 * @param string $ref  Ref
	 * @param int    $peppolid   Peppol Id object
	 * @return int         <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null, $peppolid = "")
	{
		if ($peppolid != "") {
			$sql = 'SELECT rowid';
			$sql .= ' FROM ' . MAIN_DB_PREFIX . $this->table_element . ' as t';
			if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
				$sql .= ' WHERE t.entity IN (' . getEntity($this->table_element) . ')';
			} else {
				$sql .= ' WHERE 1 = 1';
			}
			$sql .= " AND t.peppolid='" . $this->db->escape($peppolid) . "'";

			$resql = $this->db->query($sql);
			if ($resql) {
				$obj = $this->db->fetch_object($resql);
				if (isset($obj->rowid)) {
					$id = $obj->rowid;
				}
			}
		}

		$result = $this->fetchCommon($id, $ref);
		if ($result > 0 && !empty($this->table_element_line)) {
			$this->fetchLines();
		}
		return $result;
	}

	/**
	 * Load object lines in memory from the database
	 *
	 * @return int         <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchLines()
	{
		$this->lines = array();

		$result = $this->fetchLinesCommon();
		return $result;
	}


	/**
	 * Load list of objects in memory from the database.
	 *
	 * @param  string      $sortorder    Sort Order
	 * @param  string      $sortfield    Sort field
	 * @param  int         $limit        limit
	 * @param  int         $offset       Offset
	 * @param  array       $filter       Filter array. Example array('field'=>'valueforlike', 'customurl'=>...)
	 * @param  string      $filtermode   Filter mode (AND or OR)
	 * @return array|int                 int <0 if KO, array of pages if OK
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
	{
		global $conf;

		dol_syslog(__METHOD__, LOG_DEBUG);

		$records = array();

		$sql = 'SELECT ';
		$sql .= $this->getFieldList();
		$sql .= ' FROM ' . MAIN_DB_PREFIX . $this->table_element . ' as t';
		if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
			$sql .= ' WHERE t.entity IN (' . getEntity($this->table_element) . ')';
		} else {
			$sql .= ' WHERE 1 = 1';
		}
		// Manage filter
		$sqlwhere = array();
		if (count($filter) > 0) {
			foreach ($filter as $key => $value) {
				if ($key == 't.rowid') {
					$sqlwhere[] = $key . '=' . $value;
				} elseif (isset($this->fields[$key]['type']) && in_array($this->fields[$key]['type'], array('date', 'datetime', 'timestamp'))) {
					$sqlwhere[] = $key . ' = \'' . $this->db->idate($value) . '\'';
				} elseif ($key == 'customsql') {
					$sqlwhere[] = $value;
				} elseif (strpos($value, '%') === false) {
					$sqlwhere[] = $key . ' IN (' . $this->db->sanitize($this->db->escape($value)) . ')';
				} else {
					$sqlwhere[] = $key . ' LIKE \'%' . $this->db->escape($value) . '%\'';
				}
			}
		}
		if (count($sqlwhere) > 0) {
			$sql .= ' AND (' . implode(' ' . $filtermode . ' ', $sqlwhere) . ')';
		}

		if (!empty($sortfield)) {
			$sql .= $this->db->order($sortfield, $sortorder);
		}
		if (!empty($limit)) {
			$sql .= ' ' . $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < ($limit ? min($limit, $num) : $num)) {
				$obj = $this->db->fetch_object($resql);

				$record = new self($this->db);
				$record->setVarsFromFetchObj($obj);

				$records[$record->id] = $record;

				$i++;
			}
			$this->db->free($resql);

			return $records;
		} else {
			$this->errors[] = 'Error ' . $this->db->lasterror();
			dol_syslog(__METHOD__ . ' ' . join(',', $this->errors), LOG_ERR);

			return -1;
		}
	}

	/**
	 * Update object into database
	 *
	 * @param  User $user      User that modifies
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function update(\User $user, $notrigger = false)
	{
		/** @phpstan-ignore-next-line */
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object in database
	 *
	 * @param User $user       User that deletes
	 * @param bool $notrigger  false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function delete(\User $user, $notrigger = false)
	{
		//remove files from storage
		$fullfilename = $this->fullFilename();
		dol_syslog("peppol delete " . $fullfilename);
		if (file_exists($fullfilename)) {
			dol_delete_file($fullfilename);
			//just in case
			dol_delete_file(str_replace('.xml', '.pdf', $fullfilename));
			//just in case
			dol_delete_file(str_replace('.xml', '.jpg', $fullfilename));
		}

		$base = DOL_DATA_ROOT . '/peppol/';
		$dirpath = dirname($fullfilename);
		if ($dirpath != $base) {
			dol_delete_dir($dirpath);
		}

		/** @phpstan-ignore-next-line */
		return $this->deleteCommon($user, $notrigger);
		//return $this->deleteCommon($user, $notrigger, 1);
	}

	/**
	 *  Delete a line of object in database
	 *
	 *	@param  User	$user       User that delete
	 *  @param	int		$idline		Id of line to delete
	 *  @param 	bool 	$notrigger  false=launch triggers after, true=disable triggers
	 *  @return int         		>0 if OK, <0 if KO
	 */
	public function deleteLine(\User $user, $idline, $notrigger = false)
	{
		if ($this->status < 0) {
			$this->error = 'ErrorDeleteLineNotAllowedByObjectStatus';
			return -2;
		}

		/** @phpstan-ignore-next-line */
		return $this->deleteLineCommon($user, $idline, $notrigger);
	}


	/**
	 *	Validate object
	 *
	 *	@param		User	$user     		User making status change
	 *  @param		int		$notrigger		1=Does not execute triggers, 0= execute triggers
	 *	@return  	int						<=0 if OK, 0=Nothing done, >0 if KO
	 */
	public function validate($user, $notrigger = 0)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

		$error = 0;

		// Protection
		if ($this->status == self::STATUS_VALIDATED) {
			dol_syslog(get_class($this) . "::validate action abandonned: already validated", LOG_WARNING);
			return 0;
		}

		if (null === $this->status) {
			$this->status = self::STATUS_DRAFT;
		}

		/*if (! ((empty(getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ! empty($user->rights->peppolpeppyrus->write))
		 || (! empty(getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ! empty($user->rights->peppolpeppyrus->peppolimport_advance->validate))))
		 {
		 $this->error='NotEnoughPermissions';
		 dol_syslog(get_class($this)."::valid ".$this->error, LOG_ERR);
		 return -1;
		 }*/

		$now = dol_now();

		$this->db->begin();

		// Define new ref
		if (!$error && (preg_match('/^[\(]?PROV/i', $this->ref) || empty($this->ref))) { // empty should not happened, but when it occurs, the test save life
			$num = $this->getNextNumRef();
		} else {
			$num = $this->ref;
		}
		$this->newref = $num;

		if (!empty($num)) {
			// Validate
			$sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
			$sql .= " SET ref = '" . $this->db->escape($num) . "',";
			$sql .= " status = " . self::STATUS_VALIDATED;
			if (!empty($this->fields['date_validation'])) {
				$sql .= ", date_validation = '" . $this->db->idate($now) . "'";
			}
			if (!empty($this->fields['fk_user_valid'])) {
				$sql .= ", fk_user_valid = " . $user->id;
			}
			$sql .= " WHERE rowid = " . $this->id;

			dol_syslog(get_class($this) . "::validate()", LOG_DEBUG);
			$resql = $this->db->query($sql);
			if (!$resql) {
				dol_print_error($this->db);
				$this->error = $this->db->lasterror();
				$error++;
			}

			if (!$error && !$notrigger) {
				// Call trigger
				$result = $this->call_trigger('PEPPOLIMPORT_VALIDATE', $user);
				if ($result < 0) {
					$error++;
				}
				// End call triggers
			}
		}

		if (!$error) {
			$this->oldref = $this->ref;

			// Rename directory if dir was a temporary ref
			if (preg_match('/^[\(]?PROV/i', $this->ref)) {
				// Now we rename also files into index
				$sql = 'UPDATE ' . MAIN_DB_PREFIX . "ecm_files set filename = CONCAT('" . $this->db->escape($this->newref) . "', SUBSTR(filename, " . (strlen($this->ref) + 1) . ")), filepath = 'peppolimport/" . $this->db->escape($this->newref) . "'";
				$sql .= " WHERE filename LIKE '" . $this->db->escape($this->ref) . "%' AND filepath = 'peppolimport/" . $this->db->escape($this->ref) . "' and entity = " . $conf->entity;
				$resql = $this->db->query($sql);
				if (!$resql) {
					$error++;
					$this->error = $this->db->lasterror();
				}

				// We rename directory ($this->ref = old ref, $num = new ref) in order not to lose the attachments
				$oldref = dol_sanitizeFileName($this->ref);
				$newref = dol_sanitizeFileName($num);
				$dirsource = $conf->peppolpeppyrus->dir_output . '/peppolimport/' . $oldref;
				$dirdest = $conf->peppolpeppyrus->dir_output . '/peppolimport/' . $newref;
				if (!$error && file_exists($dirsource)) {
					dol_syslog(get_class($this) . "::validate() rename dir " . $dirsource . " into " . $dirdest);

					if (@rename($dirsource, $dirdest)) {
						dol_syslog("Rename ok");
						// Rename docs starting with $oldref with $newref
						$listoffiles = dol_dir_list($conf->peppolpeppyrus->dir_output . '/peppolimport/' . $newref, 'files', 1, '^' . preg_quote($oldref, '/'));
						foreach ($listoffiles as $fileentry) {
							$dirsource = $fileentry['name'];
							$dirdest = preg_replace('/^' . preg_quote($oldref, '/') . '/', $newref, $dirsource);
							$dirsource = $fileentry['path'] . '/' . $dirsource;
							$dirdest = $fileentry['path'] . '/' . $dirdest;
							@rename($dirsource, $dirdest);
						}
					}
				}
			}
		}

		// Set new ref and current status
		if (!$error) {
			$this->ref = $num;
			$this->status = self::STATUS_VALIDATED;
		}

		if (!$error) {
			$this->db->commit();
			return 1;
		} else {
			$this->db->rollback();
			return -1;
		}
	}


	/**
	 *	Set draft status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, >0 if OK
	 */
	public function setDraft($user, $notrigger = 0)
	{
		// Protection
		if ($this->status <= self::STATUS_DRAFT) {
			return 0;
		}

		/*if (! ((empty(getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ! empty($user->rights->peppolpeppyrus->write))
		 || (! empty(getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ! empty($user->rights->peppolpeppyrus->peppol_advance->validate))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'PEPPOLIMPORT_UNVALIDATE');
	}

	/**
	 *	Set cancel status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, 0=Nothing done, >0 if OK
	 */
	public function cancel($user, $notrigger = 0)
	{
		// Protection
		if ($this->status != self::STATUS_VALIDATED) {
			return 0;
		}

		/*if (! ((empty(getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ! empty($user->rights->peppolpeppyrus->write))
		 || (! empty(getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ! empty($user->rights->peppolpeppyrus->peppol_advance->validate))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, 'PEPPOLIMPORT_CANCEL');
	}

	/**
	 *	Set back to validated status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, 0=Nothing done, >0 if OK
	 */
	public function reopen($user, $notrigger = 0)
	{
		// Protection
		if ($this->status != self::STATUS_CANCELED) {
			return 0;
		}

		/*if (! ((empty(getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ! empty($user->rights->peppolpeppyrus->write))
		 || (! empty(getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) && ! empty($user->rights->peppolpeppyrus->peppol_advance->validate))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_VALIDATED, $notrigger, 'PEPPOLIMPORT_REOPEN');
	}

	/**
	 *  Return a link to the object card (with optionaly the picto)
	 *
	 *  @param  int     $withpicto                  Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
	 *  @param  string  $option                     On what the link point to ('nolink', ...)
	 *  @param  int     $notooltip                  1=Disable tooltip
	 *  @param  string  $morecss                    Add more css on link
	 *  @param  int     $save_lastsearch_value      -1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values whenclicking
	 *  @return	string                              String with URL
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $conf, $langs, $hookmanager;

		if (!empty($conf->dol_no_mouse_hover)) {
			$notooltip = 1;
		} // Force disable tooltips

		$result = '';

		$label = img_picto('', $this->picto) . ' <u>' . $langs->trans("Filetoimport") . '</u>';
		if (isset($this->status)) {
			$label .= ' ' . $this->getLibStatut(5);
		}
		$label .= '<br>';
		$label .= '<b>' . $langs->trans('Ref') . ':</b> ' . $this->ref;

		$url = dol_buildpath('/peppolpeppyrus/peppolimport_card.php', 1) . '?id=' . $this->id;

		if ($option != 'nolink') {
			// Add param to save lastsearch_values or not
			$add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
			if ($save_lastsearch_value == -1 && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
				$add_save_lastsearch_values = 1;
			}
			if ($add_save_lastsearch_values) {
				$url .= '&save_lastsearch_values=1';
			}
		}

		$linkclose = '';
		if (empty($notooltip)) {
			if (!empty(getDolGlobalString('MAIN_OPTIMIZEFORTEXTBROWSER'))) {
				$label = $langs->trans("ShowPeppolimport");
				$linkclose .= ' alt="' . dol_escape_htmltag($label, 1) . '"';
			}
			$linkclose .= ' title="' . dol_escape_htmltag($label, 1) . '"';
			$linkclose .= ' class="classfortooltip' . ($morecss ? ' ' . $morecss : '') . '"';
		} else {
			$linkclose = ($morecss ? ' class="' . $morecss . '"' : '');
		}

		$linkstart = '<a href="' . $url . '"';
		$linkstart .= $linkclose . '>';
		$linkend = '</a>';

		$result .= $linkstart;

		if (empty($this->showphoto_on_popup)) {
			if ($withpicto) {
				$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="' . (($withpicto != 2) ? 'paddingright ' : '') . 'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
			}
		} else {
			if ($withpicto) {
				require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

				list($class, $module) = explode('@', $this->picto);
				$upload_dir = $conf->$module->multidir_output[$conf->entity] . "/$class/" . dol_sanitizeFileName($this->ref);
				$filearray = dol_dir_list($upload_dir, "files");
				$filename = $filearray[0]['name'];
				if (!empty($filename)) {
					$pospoint = strpos($filearray[0]['name'], '.');

					$pathtophoto = $class . '/' . $this->ref . '/thumbs/' . substr($filename, 0, $pospoint) . '_mini' . substr($filename, $pospoint);
					if (empty($conf->global->{strtoupper($module . '_' . $class) . '_FORMATLISTPHOTOSASUSERS'})) {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><div class="photoref"><img class="photo' . $module . '" alt="No photo" border="0" src="' . DOL_URL_ROOT . '/viewimage.php?modulepart=' . $module . '&entity=' . $conf->entity . '&file=' . urlencode($pathtophoto) . '"></div></div>';
					} else {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><img class="photouserphoto userphoto" alt="No photo" border="0" src="' . DOL_URL_ROOT . '/viewimage.php?modulepart=' . $module . '&entity=' . $conf->entity . '&file=' . urlencode($pathtophoto) . '"></div>';
					}

					$result .= '</div>';
				} else {
					$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="' . (($withpicto != 2) ? 'paddingright ' : '') . 'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
				}
			}
		}

		if ($withpicto != 2) {
			$result .= $this->ref;
		}

		$result .= $linkend;
		//if ($withpicto != 2) $result.=(($addlabel && $this->label) ? $sep . dol_trunc($this->label, ($addlabel > 1 ? $addlabel : 0)) : '');

		global $action, $hookmanager;
		$hookmanager->initHooks(array('peppolimportdao'));
		$parameters = array('id' => $this->id, 'getnomurl' => $result);
		$reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook > 0) {
			$result = $hookmanager->resPrint;
		} else {
			$result .= $hookmanager->resPrint;
		}

		return $result;
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return the status
	 *
	 *  @param	int		$status        Id status
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return string 			       Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
			global $langs;
			//$langs->load("peppol@peppolpeppyrus");
			$this->labelStatus[self::STATUS_DRAFT] = $langs->trans('Waiting');
			$this->labelStatus[self::STATUS_VALIDATED] = $langs->trans('Analyzed');
			$this->labelStatus[self::STATUS_CLOSED] = $langs->trans('Success');
			$this->labelStatus[self::STATUS_HALFSUCCESS] = $langs->trans('Partial');
			// $this->labelStatus[self::STATUS_SUCCESS] = $langs->trans('Success');
			$this->labelStatus[self::STATUS_CANCELED] = $langs->trans('Disabled');
			$this->labelStatus[self::STATUS_ERROR] = $langs->trans('Error');

			$this->labelStatusShort[self::STATUS_DRAFT] = $langs->trans('Waiting');
			$this->labelStatusShort[self::STATUS_VALIDATED] = $langs->trans('Analyzed');
			$this->labelStatusShort[self::STATUS_CLOSED] = $langs->trans('Success');
			$this->labelStatusShort[self::STATUS_HALFSUCCESS] = $langs->trans('Partial');
			// $this->labelStatusShort[self::STATUS_SUCCESS] = $langs->trans('Success');
			$this->labelStatusShort[self::STATUS_CANCELED] = $langs->trans('Disabled');
			$this->labelStatusShort[self::STATUS_ERROR] = $langs->trans('Error');
		}

		$statusType = 'status' . $status;
		//if ($status == self::STATUS_VALIDATED) $statusType = 'status1';
		if ($status == self::STATUS_CANCELED) {
			$statusType = 'status6';
		}

		return dolGetStatus($this->labelStatus[$status], $this->labelStatusShort[$status], '', $statusType, $mode);
	}

	/**
	 *	Load the info information in the object
	 *
	 *	@param  int		$id       Id of object
	 *	@return	void
	 */
	public function info($id)
	{
		$sql = 'SELECT rowid, date_creation as datec, tms as datem,';
		$sql .= ' fk_user_creat, fk_user_modif';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . $this->table_element . ' as t';
		$sql .= ' WHERE t.rowid = ' . $id;
		$result = $this->db->query($sql);
		if ($result) {
			if ($this->db->num_rows($result)) {
				$obj = $this->db->fetch_object($result);
				$this->id = $obj->rowid;
				if ($obj->fk_user_author) {
					$cuser = new \User($this->db);
					$cuser->fetch($obj->fk_user_author);
					$this->user_creation = $cuser;
				}

				if ($obj->fk_user_valid) {
					$vuser = new \User($this->db);
					$vuser->fetch($obj->fk_user_valid);
					$this->user_validation = $vuser;
				}

				$this->date_creation     = $this->db->jdate($obj->datec);
				$this->date_modification = $this->db->jdate($obj->datem);
				$this->date_validation   = $this->db->jdate($obj->datev);
			}

			$this->db->free($result);
		} else {
			dol_print_error($this->db);
		}
	}

	/**
	 * Initialise object with example values
	 * Id must be 0 if object instance is a specimen
	 *
	 * @return void
	 */
	public function initAsSpecimen()
	{
		$this->initAsSpecimenCommon();
	}

	/**
	 * 	Create an array of lines
	 *
	 * 	@return array|int		array of lines if OK, <0 if KO
	 */
	public function getLinesArray()
	{
		$this->lines = array();

		$objectline = new PeppolimportLine($this->db);
		$result = $objectline->fetchAll('ASC', 'position', 0, 0, array('customsql' => 'fk_peppolimport = ' . $this->id));

		if (is_numeric($result)) {
			$this->error = $this->error;
			$this->errors = $this->errors;
			return $result;
		} else {
			$this->lines = $result;
			return $this->lines;
		}
	}

	/**
	 *  Returns the reference to the following non used object depending on the active numbering module.
	 *
	 *  @return string      		Object free reference
	 */
	public function getNextNumRef()
	{
		global $langs, $conf;
		$langs->load("peppol@peppolpeppyrus");

		$numref = $this->getNextValue($this);

		if ($numref != '' && $numref != '-1') {
			return $numref;
		} else {
			return "";
		}
	}

	/**
	 * 	Return next free value
	 *
	 *  @param  Object		$object		Object we need next value for
	 *  @return string      			Value if KO, <0 if KO
	 */
	public function getNextValue($object)
	{
		global $db, $conf;

		$prefix = "PIM";
		$posindice = strlen($prefix) + 2;
		$sql = "SELECT MAX(CAST(SUBSTRING(ref FROM " . $posindice . ") AS SIGNED)) as max";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
		$sql .= " WHERE ref LIKE '" . $prefix . "-%'";
		if ($object->ismultientitymanaged == 1) {
			$sql .= " AND entity = " . $conf->entity;
		} elseif ($object->ismultientitymanaged == 2) {
			// TODO
		}

		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj) $max = intval($obj->max);
			else $max = 0;
		} else {
			dol_syslog("peppolimport::getNextValue", LOG_DEBUG);
			return -1;
		}

		//$date=time();
		if ($max >= (pow(5, 4) - 1)) $num = $max + 1; // If counter > 9999, we do not format on 4 chars, we take number as it is
		else $num = sprintf("%04s", $max + 1);

		dol_syslog("peppolimport::getNextValue return " . $prefix . "-" . $num);
		return $prefix . "-" . $num;
	}


	/**
	 *  Create a document onto disk according to template module.
	 *
	 *  @param	    string		$modele			Force template to use ('' to not force)
	 *  @param		Translate	$outputlangs	objet lang a utiliser pour traduction
	 *  @param      int			$hidedetails    Hide details of lines
	 *  @param      int			$hidedesc       Hide description
	 *  @param      int			$hideref        Hide ref
	 *  @param      null|array  $moreparams     Array to provide more information
	 *  @return     int         				0 if KO, 1 if OK
	 */
	public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
		global $conf, $langs;

		$result = 0;
		$includedocgeneration = 0;

		$langs->load("peppol@peppolpeppyrus");

		if (!dol_strlen($modele)) {
			$modele = 'standard_peppolimport';

			if (!empty($this->model_pdf)) {
				$modele = $this->model_pdf;
			} elseif (!empty(getDolGlobalString('PEPPOLIMPORT_ADDON_PDF'))) {
				$modele = getDolGlobalString('PEPPOLIMPORT_ADDON_PDF');
			}
		}

		$modelpath = "core/modules/peppol/doc/";

		if ($includedocgeneration && !empty($modele)) {
			$result = $this->commonGenerateDocument($modelpath, $modele, $outputlangs, $hidedetails, $hidedesc, $hideref, $moreparams);
		}

		return $result;
	}

	/**
	 * Action executed by scheduler
	 * CAN BE A CRON TASK. In such a case, parameters come from the schedule job setup field 'Parameters'
	 * Use public function doScheduledJob($param1, $param2, ...) to get parameters
	 *
	 * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doScheduledJob()
	{
		global $conf, $langs, $user, $db;
		//getDolGlobalString('SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_mydedicatedlofile.log'');

		$error = 0;
		$this->output = '';
		$this->error = '';

		dol_syslog(__METHOD__, LOG_DEBUG);
		$now = dol_now();

		//TODO

		return $error;
	}

	public function importInvoices($list)
	{
		global $db, $user;
		foreach ($list as $key => $fi) {
			//TODO
		}
	}

	/**
	 * force to import now a file even if it's in "later" queue
	 *
	 * @param   [int]   $fourID            to force supplier (fournisseur)
	 * @return  [type]  [return description]
	 */
	public function importNow($fournID = -1)
	{
		global $db, $user, $conf;
		dol_syslog('ScanInvoices: importNow with fournID=' . $fournID);

		//TODO
		if (isModEnabled('scaninvoices')) {
			$url = dol_buildpath('/scaninvoices/importauto.php?action=now&token=' . newToken() . "&iref=" . $this->ref . "&localFileName=" . basename($this->fullFilename() . "&module=peppol"), 1);
			header("Location: " . $url);
		}

		return;
	}

	/**
	 * return true if file exist, false in other case
	 *
	 * @return  [type]  [return description]
	 */
	public function fileExist()
	{

		//TODO
	}

	/**
	 * return full file name : database store only filename
	 * and full file path is made from id and local setup
	 *
	 * @return  [type]  [return description]
	 */
	public function fullFilename()
	{
		$ret = "";
		if (!empty($this->peppolid)) {
			$completefilepath = DOL_DATA_ROOT . '/peppol/' . dol_sanitizeFileName($this->ref);
			if (empty($this->filename)) {
				$filename = dol_sanitizeFileName($this->peppolid) . ".xml";
				$res = dol_mkdir($completefilepath);
				if ($res >= 0) {
					$this->filename = $filename;
				}
			}
			$ret = $completefilepath . '/' . $this->filename;
		} else {
			dol_syslog("peppol ask for fullFilename but peppolid is empty !");
		}
		return $ret;
	}

	/**
	 * double check if that import is really a success (ie user did not delete invoice and forget to delete Peppolimport entry )
	 */
	public function fullImportSuccess()
	{
		//TODO
	}

	public function setValue($key, $value)
	{
		dol_syslog("PeppolImport set " . $key . ", value=" . $value);
		$this->{$key} = $value;
	}

	public function setValues($array)
	{
		dol_syslog("PeppolImport setvalues " . json_encode($array));
		foreach ($array as $key => $value) {
			$this->{$key} = $value;
		}
	}

	public function saveXML($xml)
	{
		global $user;
		$ret = -1;
		$completefilename = $this->fullFilename();
		dol_syslog("Peppol: save XML file to " . $completefilename);
		if ($fp = fopen($completefilename, 'w')) {
			fwrite($fp, $xml);
			fclose($fp);
			$retupdate = $this->update($user);
			if ($retupdate > 0) {
				$ret = $this->indexFile($completefilename, 0);
				try {
					$this->extractPDFfromXML($xml);
				} catch (\Exception $e) {
					dol_syslog("Peppol::saveXML error on extractPDFfromXML " . $e->getMessage(), LOG_ERR);
				}
			} else {
				dol_syslog("Peppol::saveXML error on update object : " . $retupdate, LOG_ERR);
			}
		}
		return $ret;
	}

	public function savePDF($pdf)
	{
		global $user;
		$ret = -1;
		$completefilename = str_replace(".xml", ".pdf", $this->fullFilename());
		dol_syslog("Peppol: save PDF file to " . $completefilename);
		if ($fp = fopen($completefilename, 'w')) {
			fwrite($fp, $pdf);
			fclose($fp);
			$ret = $this->indexFile($completefilename, 0);
		}
		return $ret;
	}

	public function extractPDFfromXML($xml)
	{
		// Create a DOMDocument and load the XML
		$dom = new \DOMDocument();
		$dom->loadXML($xml);

		// Create XPath object for querying
		$xpath = new \DOMXPath($dom);

		// Register namespaces used in the document
		$xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
		$xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

		// Query for the EmbeddedDocumentBinaryObject element
		$query = "//cbc:EmbeddedDocumentBinaryObject";
		$elements = $xpath->query($query);

		if ($elements->length === 0) {
			throw new \Exception("EmbeddedDocumentBinaryObject not found in XML");
		}

		// Get the base64 encoded PDF content
		$base64Content = $elements->item(0)->nodeValue;

		// Remove any whitespace or line breaks from the base64 string
		$base64Content = preg_replace('/\s+/', '', $base64Content);

		// Decode the base64 content to binary
		$pdfBinary = base64_decode($base64Content);

		if ($pdfBinary === false) {
			throw new \Exception("Failed to decode base64 content");
		}

		// Save the PDF binary content to a file
		$this->savePDF($pdfBinary);

		return true;
	}
}

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobjectline.class.php';

/**
 * Class PeppolimportLine. You can also remove this and generate a CRUD class for lines objects.
 */
class PeppolimportLine extends \CommonObjectLine
{
	// To complete with content of an object PeppolimportLine
	// We should have a field rowid, fk_peppolimport and position

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database handler
	 */
	public function __construct(\DoliDB $db)
	{
		$this->db = $db;
	}
}
