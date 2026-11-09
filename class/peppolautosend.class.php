<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    peppolpeppyrus/class/peppolautosend.class.php
 * \ingroup peppol
 * \brief   Scheduled job (off by default): send validated customer invoices to Peppol at the hours set up
 *
 * Switched on and off on the setup page of the module (the scheduled job is enabled or disabled there).
 * The job runs every hour and only sends at the hours set up (PEPPOL_AUTO_SEND_HOURS, for example "9" for once
 * a day or "9,13,17"). Only invoices validated after the sending was switched on (PEPPOL_AUTO_SEND_SINCE), of
 * customers with a Peppol ID, not private, not sent yet; at most PEPPOL_AUTO_SEND_MAX per run.
 * The result and the log lines hold ids and counts only: no names, no Peppol IDs, no amounts.
 */

dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

/**
 * Automatic sending of customer invoices
 */
class PeppolAutoSend
{
	/** @var DoliDB */
	public $db;

	/** @var string Message for the scheduled job list */
	public $output = '';

	/** @var string */
	public $error = '';

	/** @var callable Function that returns the access point object (replaceable in tests) */
	public $apFactory = 'peppolGetAPObject';

	/**
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * The hours of the day to send at, from the setting ("9" or "9,13,17"); empty setting: 9
	 *
	 * @param	string	$setting	Setting
	 * @return	int[]	Hours 0-23, sorted, without duplicates
	 */
	public static function hours($setting)
	{
		$hours = array();
		foreach (preg_split('/[\s,;]+/', trim((string) $setting)) as $h) {
			if ($h !== '' && ctype_digit($h) && (int) $h >= 0 && (int) $h <= 23) {
				$hours[(int) $h] = (int) $h;
			}
		}
		if (!count($hours)) {
			$hours = array(9 => 9);
		}
		sort($hours);
		return $hours;
	}

	/**
	 * Whether this run has to send: the current hour is one of the hours, and nothing was sent in this hour yet
	 *
	 * @param	int[]	$hours		Hours of hours()
	 * @param	int		$now		Timestamp
	 * @param	string	$lastSlot	Slot of the last run that sent (Y-m-d H), or ''
	 * @return	string	Slot of this run (Y-m-d H), or '' when nothing has to be sent now
	 */
	public static function slotToSend($hours, $now, $lastSlot)
	{
		$hour = (int) dol_print_date($now, '%H', 'tzserver');
		if (!in_array($hour, $hours, true)) {
			return '';
		}
		$slot = dol_print_date($now, '%Y-%m-%d %H', 'tzserver');
		return ($slot === $lastSlot) ? '' : $slot;
	}

	/**
	 * Scheduled job (every hour): send at the hours set up
	 *
	 * @return	int		0 if OK, >0 if error (convention of the Dolibarr scheduled jobs)
	 */
	public function runAutoSend()
	{
		global $conf, $langs, $user;

		if (!isModEnabled('peppolpeppyrus')) {
			$this->output = 'Module not enabled';
			return 0;
		}
		$langs->loadLangs(array('peppol@peppolpeppyrus', 'bills'));

		// Switched on outside the setup page (for example in the list of scheduled jobs): start from now
		$since = getDolGlobalInt('PEPPOL_AUTO_SEND_SINCE');
		if ($since <= 0) {
			$since = dol_now();
			dolibarr_set_const($this->db, 'PEPPOL_AUTO_SEND_SINCE', (string) $since, 'chaine', 0, '', $conf->entity);
		}

		$slot = self::slotToSend(self::hours(getDolGlobalString('PEPPOL_AUTO_SEND_HOURS')), dol_now(), getDolGlobalString('PEPPOL_AUTO_SEND_LAST_SLOT'));
		if ($slot === '') {
			$this->output = $langs->trans('peppolAutoSendNotNow');
			return 0;
		}

		if (!is_object($user) || !$user->hasRight('peppolpeppyrus', 'write') || !$user->hasRight('facture', 'creer')) {
			$this->error = $langs->trans('peppolAutoSendNoRight');
			$this->output = $this->error;
			return 1;
		}
		$ap = call_user_func($this->apFactory);
		if (!is_object($ap) || !method_exists($ap, 'sendToAccessPoint')) {
			$this->error = $langs->trans('peppolInboxFetchNoAccessPoint');
			$this->output = $this->error;
			return 1;
		}
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		// The slot is taken before sending, so that a second run in the same hour never sends twice
		dolibarr_set_const($this->db, 'PEPPOL_AUTO_SEND_LAST_SLOT', $slot, 'chaine', 0, '', $conf->entity);

		$stats = array('sent' => 0, 'skipped' => 0, 'failed' => 0);
		foreach ($this->invoicesToSend($since, max(1, getDolGlobalInt('PEPPOL_AUTO_SEND_MAX', 20))) as $id) {
			$res = $this->sendOne($ap, $id);
			$stats[$res > 0 ? 'sent' : ($res === 0 ? 'skipped' : 'failed')]++;
		}
		dol_syslog('PeppolAutoSend::runAutoSend sent ' . $stats['sent'] . ', skipped ' . $stats['skipped'] . ', failed ' . $stats['failed']);

		$this->output = $langs->trans('peppolAutoSendResult', $stats['sent'], $stats['skipped'], $stats['failed']);
		if ($stats['failed'] > 0) {
			$this->error = $this->output;
			return 1;
		}
		return 0;
	}

	/**
	 * Validated customer invoices not sent yet, of customers with a Peppol ID, validated since $since
	 *
	 * @param	int		$since	Timestamp
	 * @param	int		$limit	Maximum
	 * @return	int[]	Invoice ids, oldest first
	 */
	public function invoicesToSend($since, $limit)
	{
		$sql = "SELECT f.rowid FROM " . MAIN_DB_PREFIX . "facture as f";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = f.fk_soc";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe_extrafields as se ON se.fk_object = s.rowid";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_extrafields as fe ON fe.fk_object = f.rowid";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_typent as t ON t.id = s.fk_typent";
		$sql .= " WHERE f.entity IN (" . getEntity('invoice') . ")";
		$sql .= " AND f.fk_statut IN (1, 2) AND f.type IN (0, 2)";
		$sql .= " AND f.date_valid >= '" . $this->db->idate($since) . "'";
		$sql .= " AND se.peppol_id IS NOT NULL AND se.peppol_id <> ''";
		$sql .= " AND (fe.peppol_id IS NULL OR fe.peppol_id = '')";
		$sql .= " AND (t.code IS NULL OR t.code <> 'TE_PRIVATE')";
		$sql .= " ORDER BY f.date_valid, f.rowid";
		$sql .= $this->db->plimit((int) $limit);
		$ids = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$ids[] = (int) $obj->rowid;
			}
			$this->db->free($resql);
		} else {
			dol_syslog('PeppolAutoSend::invoicesToSend ' . $this->db->lasterror(), LOG_ERR);
		}
		return $ids;
	}

	/**
	 * Send one invoice; the Peppol file is made with the PDF when it is not there yet
	 *
	 * @param	object	$ap		Access point
	 * @param	int		$id		Invoice id
	 * @return	int		1 sent, 0 skipped (no Peppol file could be made), <0 error
	 */
	private function sendOne($ap, $id)
	{
		global $conf, $langs;

		$inv = new Facture($this->db);
		if ($inv->fetch($id) <= 0) {
			return -1;
		}
		$inv->fetch_thirdparty();
		$inv->fetch_optionals();
		$file = $conf->facture->dir_output . '/' . dol_sanitizeFileName($inv->ref) . '/' . dol_sanitizeFileName($inv->ref) . '_peppol.xml';
		if (!dol_is_file($file)) {
			// The Peppol file is made by the module when the PDF is made
			$model = $inv->model_pdf ? $inv->model_pdf : getDolGlobalString('FACTURE_ADDON_PDF', 'crabe');
			$inv->generateDocument($model, $langs);
		}
		if (!dol_is_file($file)) {
			dol_syslog('PeppolAutoSend::sendOne invoice ' . ((int) $id) . ' no Peppol file, skipped', LOG_WARNING);
			return 0;
		}
		$res = $ap->sendToAccessPoint($file, $inv);
		dol_syslog('PeppolAutoSend::sendOne invoice ' . ((int) $id) . ' result ' . ((int) $res));
		return ($res > 0) ? 1 : -1;
	}
}
