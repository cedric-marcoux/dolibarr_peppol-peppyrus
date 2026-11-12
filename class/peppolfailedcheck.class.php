<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    peppolpeppyrus/class/peppolfailedcheck.class.php
 * \ingroup peppol
 * \brief   Daily scheduled job (since 2.12.0): invoices Peppyrus could not deliver
 *
 * Peppyrus puts a sent message that could not be delivered in the folder FAILED. Without this job nobody notices: the
 * invoice looks sent. Per invoice one line "failed" is added to the Peppol log (Peppol tab of the invoice) and, with
 * the agenda on, a neutral event on the invoice. The invoice itself does not change.
 * The result and the log hold counts only: no names, no amounts.
 */

dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

/**
 * Check of failed deliveries by the scheduled job
 */
class PeppolFailedCheck
{
	/** @var DoliDB */
	public $db;

	/** @var string Message for the scheduled job list */
	public $output = '';

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

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
	 * Scheduled job (daily): report failed deliveries
	 *
	 * @return	int		0 if OK, >0 if error (convention of the Dolibarr scheduled jobs)
	 */
	public function runDailyCheck()
	{
		global $langs, $user;

		if (!isModEnabled('peppolpeppyrus')) {
			$this->output = 'Module not enabled';
			return 0;
		}
		$langs->load("peppol@peppolpeppyrus");
		if (!is_object($user) || !method_exists($user, 'hasRight') || !$user->hasRight('peppolpeppyrus', 'read')) {
			$this->error = $this->output = $langs->transnoentitiesnoconv('peppolFailedCheckNoRight');
			return 1;
		}
		$ap = call_user_func($this->apFactory);
		if (!is_object($ap) || !method_exists($ap, 'getFailedMessages')) {
			$this->error = $this->output = $langs->transnoentitiesnoconv('peppolInboxFetchNoAccessPoint');
			return 1;
		}
		$failed = $ap->getFailedMessages();
		if (!is_array($failed)) {
			$this->error = $this->output = $langs->transnoentitiesnoconv('peppolFailedCheckNoAnswer').' (code '.((int) $failed).')';
			return 1;
		}

		// Own invoices with these message ids (valid ids only, each one escaped)
		$invoices = array();
		$conditions = array();
		foreach ($failed as $f) {
			if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $f['id'])) {
				$conditions[] = "fe.peppol_id = '".$this->db->escape($f['id'])."'";
			}
		}
		foreach (array_chunk($conditions, 200) as $part) {
			$sql = "SELECT f.rowid, fe.peppol_id FROM ".MAIN_DB_PREFIX."facture AS f";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture_extrafields AS fe ON fe.fk_object = f.rowid";
			$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND (".implode(' OR ', $part).")"; // @phan-suppress-current-line SqlInjection -- every condition is built above with escape()
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$invoices[(string) $obj->peppol_id] = (int) $obj->rowid;
			}
		}
		// Already reported
		$reported = array();
		if (!empty($invoices)) {
			$sql = "SELECT DISTINCT fk_object FROM ".MAIN_DB_PREFIX."peppol WHERE object_typeid = 1 AND status = 'FAILED'";
			$sql .= " AND entity IN (".getEntity('invoice').") AND fk_object IN (".$this->db->sanitize(implode(',', array_map('intval', $invoices))).")";
			$resql = $this->db->query($sql);
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$reported[(int) $obj->fk_object] = true;
			}
		}

		$new = peppolFailedToReport($failed, $invoices, $reported);
		$text = $langs->transnoentitiesnoconv('peppolSendFailedAtAp');
		foreach ($new as $n) {
			peppolAddLog('peppyrus', $n['invoice'], 1, 'FAILED', $text, (string) json_encode(array('folder' => 'FAILED', 'messageId' => $n['id'], 'created' => $n['created'])));
			$this->agenda($n['invoice'], $text);
		}
		dol_syslog('PeppolFailedCheck::runDailyCheck failed '.count($failed).', own invoices '.count($invoices).', new '.count($new));
		$this->output = $langs->transnoentitiesnoconv('peppolFailedCheckResult', count($new));
		return 0;
	}

	/**
	 * Neutral event on the invoice, when the agenda is on
	 *
	 * @param	int		$fid	Invoice
	 * @param	string	$text	Description
	 * @return	void
	 */
	private function agenda($fid, $text)
	{
		global $user, $langs;
		if (!isModEnabled('agenda')) {
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$invoice = new Facture($this->db);
		if ($invoice->fetch($fid) <= 0) {
			return;
		}
		$ac = new ActionComm($this->db);
		$ac->type_code = 'AC_OTH_AUTO';
		$ac->code = 'AC_PEPPOL_FAILED';
		$ac->label = dol_trunc($langs->transnoentitiesnoconv('peppolSendFailedShort').' ('.$invoice->ref.')', 120, 'right', 'UTF-8', 1);
		$ac->note_private = $text;
		$ac->datep = dol_now();
		$ac->userownerid = (int) $user->id;
		$ac->percentage = -1;
		$ac->socid = (int) $invoice->socid;
		$ac->fk_element = (int) $fid;
		$ac->elementtype = 'invoice';
		if ($ac->create($user) < 0) {
			dol_syslog('PeppolFailedCheck: agenda event failed: '.$ac->error, LOG_WARNING);
		}
	}
}
