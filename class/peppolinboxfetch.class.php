<?php

/**
 * \file    peppolpeppyrus/class/peppolinboxfetch.class.php
 * \ingroup peppol
 * \brief   Daily scheduled job: fetch the new incoming documents from the access point
 *
 * Only fetches and stores the documents (Billing > Peppol > Incoming documents) and confirms them at the access
 * point. It never creates, validates or pays a supplier invoice: that stays a decision of the user.
 * The result and the log lines hold counts only: no names, no Peppol IDs, no amounts.
 */

dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

/**
 * Fetch of the incoming documents by the scheduled job
 */
class PeppolInboxFetch
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
	 * Scheduled job (daily): fetch the new documents from the access point.
	 * Runs with the user of the scheduled job, who needs the right to fetch documents.
	 *
	 * @return	int		0 if OK, >0 if error (convention of the Dolibarr scheduled jobs)
	 */
	public function runDailyFetch()
	{
		global $langs, $user;

		if (!isModEnabled('peppolpeppyrus')) {
			$this->output = 'Module not enabled';
			return 0;
		}
		$langs->load("peppol@peppolpeppyrus");

		if (!is_object($user) || !method_exists($user, 'hasRight') || !$user->hasRight('peppolpeppyrus', 'write')) {
			$this->error = $this->text('peppolInboxFetchNoRight', 'The user of the scheduled job has no right to fetch Peppol documents (Peppol: write).');
			$this->output = $this->error;
			return 1;
		}
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		$ap = call_user_func($this->apFactory);
		if (!is_object($ap) || !method_exists($ap, 'getSupplierInvoicesList')) {
			$this->error = $this->text('peppolInboxFetchNoAccessPoint', 'The access point could not be loaded.');
			$this->output = $this->error;
			return 1;
		}

		$ret = $ap->getSupplierInvoicesList();
		$stats = (isset($ap->fetchStats) && is_array($ap->fetchStats)) ? $ap->fetchStats : array();
		$stats = array_merge(array('listed' => 0, 'new' => 0, 'retried' => 0, 'failed' => 0), $stats);
		dol_syslog("PeppolInboxFetch::runDailyFetch result " . ((int) $ret) . ", new " . ((int) $stats['new']) . ", retried " . ((int) $stats['retried']) . ", failed " . ((int) $stats['failed']));

		if ($ret < 0) {
			$reason = (!empty($ap->errors) && is_array($ap->errors)) ? implode(', ', $ap->errors) : 'code ' . ((int) $ret);
			$this->error = $this->text('peppolInboxFetchFailed', 'The access point could not be reached; the documents are fetched next time.') . ' (' . $reason . ')';
			$this->output = $this->error;
			return 1;
		}

		$this->output = $this->resultMessage($stats);
		if ($stats['failed'] > 0) {
			$this->error = $this->output;
			return 1;
		}
		return 0;
	}

	/**
	 * Readable result, also when the translation is not available (for example a stale language cache)
	 *
	 * @param	array<string,int>	$stats	Counts: new, retried, failed
	 * @return	string
	 */
	public function resultMessage($stats)
	{
		global $langs;

		$new = (int) ($stats['new'] ?? 0) + (int) ($stats['retried'] ?? 0);
		$failed = (int) ($stats['failed'] ?? 0);
		$msg = $langs->trans('peppolInboxFetchResult', $new);
		if (strpos($msg, 'peppolInboxFetchResult') === 0) {
			$msg = $new . ' new document(s) fetched.';
		}
		if ($failed > 0) {
			$err = $langs->trans('peppolInboxFetchSomeFailed', $failed);
			if (strpos($err, 'peppolInboxFetchSomeFailed') === 0) {
				$err = $failed . ' document(s) could not be fetched; they are tried again next time.';
			}
			$msg .= ' ' . $err;
		}
		return $msg;
	}

	/**
	 * Translation with a fallback text
	 *
	 * @param	string	$key		Language key
	 * @param	string	$fallback	Text when the key is not translated
	 * @return	string
	 */
	private function text($key, $fallback)
	{
		global $langs;
		$t = $langs->trans($key);
		return ($t === $key) ? $fallback : $t;
	}
}
