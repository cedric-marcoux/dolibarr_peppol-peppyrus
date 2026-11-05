<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    peppolpeppyrus/class/peppolcandidatecheck.class.php
 * \ingroup peppol
 * \brief   Weekly check: which customers and suppliers without a Peppol ID can be found in the Peppol directory
 *
 * Only searches by VAT number and professional ID 1 (KvK number in the Netherlands), never by name, and keeps only
 * directory entries whose number is exactly the number of the third party. Log lines hold counts only: no names,
 * no VAT numbers, no registration numbers, no Peppol IDs.
 */

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

/**
 * Check of third parties against the Peppol directory, and the candidates found
 */
class PeppolCandidateCheck
{
	const STATUS_NEW = 0;
	const STATUS_LINKED = 1;
	const STATUS_IGNORED = 2;

	/** @var DoliDB */
	public $db;

	/** @var string Message for the scheduled job list */
	public $output = '';

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var int Pause between two requests to the directory, in microseconds (the directory limits requests per second) */
	public $pause = 1100000;

	/** @var int Wait before the one retry after HTTP 429 (too many requests), in seconds */
	public $retryWait = 10;

	/** @var string Technical reason of the last directory error (HTTP code, curl error), no search term */
	public $lastErrorReason = '';

	/** @var callable Directory search function (replaceable in tests) */
	public $searchFunction = 'peppolDirectorySearch';

	/**
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Scheduled job (weekly): check the third parties that were not checked in the last 6 days.
	 *
	 * @return	int		0 if OK, >0 if error (convention of the Dolibarr scheduled jobs)
	 */
	public function runWeeklyCheck()
	{
		global $langs;

		if (!isModEnabled('peppolpeppyrus')) {
			$this->output = 'Module not enabled';
			return 0;
		}
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		$langs->load("peppol@peppolpeppyrus");

		$stats = $this->checkBatch(getDolGlobalInt('PEPPOL_CANDIDATE_BATCH', 500));
		$this->output = $this->resultMessage($stats);
		if ($stats['errors'] > 0) {
			$this->error = $this->output;
			return 1;
		}
		return 0;
	}

	/**
	 * Readable result of a check, also when the translation is not available (for example a stale language cache)
	 *
	 * @param	array{checked:int,found:int,new:int,errors:int}	$stats	Result of checkBatch()
	 * @return	string
	 */
	public function resultMessage($stats)
	{
		global $langs;

		$msg = $langs->trans('peppolCandidateCheckResult', $stats['checked'], $stats['found'], $stats['new']);
		if (strpos($msg, 'peppolCandidateCheckResult') === 0) {
			$msg = $stats['checked'] . ' third parties checked, ' . $stats['found'] . ' ID(s) found, ' . $stats['new'] . ' new.';
		}
		if ($stats['errors'] > 0) {
			$err = $langs->trans('peppolCandidateCheckErrors', $stats['errors']);
			if (strpos($err, 'peppolCandidateCheckErrors') === 0) {
				$err = 'The Peppol directory could not be reached; the remaining third parties are checked next time.';
			}
			$msg .= ' ' . $err . ($this->lastErrorReason !== '' ? ' (' . $this->lastErrorReason . ')' : '');
		}
		return $msg;
	}

	/**
	 * Check a number of third parties against the Peppol directory.
	 * Third parties: active customers and suppliers, not private, without Peppol ID, with a VAT number or professional ID 1,
	 * not checked in the last 6 days. The oldest checks come first.
	 *
	 * @param	int		$limit	Maximum number of third parties
	 * @return	array{checked:int,found:int,new:int,errors:int}	checked, found (IDs found), new (new candidates), errors
	 */
	public function checkBatch($limit)
	{
		global $conf;

		$stats = array('checked' => 0, 'found' => 0, 'new' => 0, 'errors' => 0);
		$this->lastErrorReason = '';
		$limit = max(1, (int) $limit);
		$now = dol_now();

		$sql = "SELECT s.rowid";
		$sql .= " FROM " . MAIN_DB_PREFIX . "societe as s";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe_extrafields as se ON se.fk_object = s.rowid";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_typent as t ON t.id = s.fk_typent";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "peppol_lookup as l ON l.fk_soc = s.rowid AND l.entity = " . ((int) $conf->entity);
		$sql .= " WHERE s.entity IN (" . getEntity('societe') . ")";
		$sql .= " AND s.status = 1";
		$sql .= " AND (s.client IN (1, 3) OR s.fournisseur = 1)";
		$sql .= " AND (t.code IS NULL OR t.code <> 'TE_PRIVATE')";
		$sql .= " AND (se.peppol_id IS NULL OR se.peppol_id = '')";
		$sql .= " AND ((s.tva_intra IS NOT NULL AND s.tva_intra <> '') OR (s.siren IS NOT NULL AND s.siren <> ''))";
		$sql .= " AND (l.date_checked IS NULL OR l.date_checked < '" . $this->db->idate($now - 6 * 24 * 3600) . "')";
		$sql .= " ORDER BY CASE WHEN l.date_checked IS NULL THEN 0 ELSE 1 END, l.date_checked, s.rowid";
		$sql .= $this->db->plimit($limit);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__ . ' error reading third parties', LOG_ERR);
			$stats['errors']++;
			return $stats;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$first = true;
		$reason = '';
		foreach ($ids as $socid) {
			$soc = new Societe($this->db);
			if ($soc->fetch($socid) <= 0) {
				continue;
			}
			$res = $this->checkThirdparty($soc, $first);
			$first = false;
			if ($res['error']) {
				// Directory not reachable: stop, the remaining third parties stay unchecked for the next run
				$stats['errors']++;
				$reason = $res['reason'];
				break;
			}
			$stats['checked']++;
			$stats['found'] += $res['found'];
			$stats['new'] += $res['new'];
		}

		dol_syslog(__METHOD__ . ' checked=' . $stats['checked'] . ' found=' . $stats['found'] . ' new=' . $stats['new'] . ' errors=' . $stats['errors'] . ($stats['errors'] > 0 && $reason !== '' ? ' reason=' . $reason : ''), $stats['errors'] ? LOG_WARNING : LOG_INFO);
		return $stats;
	}

	/**
	 * Check one third party: search by VAT number and professional ID 1, keep the entries with exactly that number.
	 *
	 * @param	Societe	$soc		Third party
	 * @param	bool	$nopause	true to skip the pause before the first request
	 * @return	array{found:int,new:int,error:bool,reason:string}	found, new, error, reason (technical, no search term)
	 */
	public function checkThirdparty($soc, $nopause = false)
	{
		$out = array('found' => 0, 'new' => 0, 'error' => false, 'reason' => '');
		$terms = peppolDirectorySearchCandidates($soc);
		unset($terms['name']); // numbers only: a name gives too many wrong candidates

		$found = array();
		foreach ($terms as $term) {
			if (!$nopause && $this->pause > 0) {
				usleep($this->pause);
			}
			$nopause = false;
			$res = call_user_func($this->searchFunction, $term);
			if (isset($res['error']) && (int) ($res['http_code'] ?? 0) == 429) {
				// Too many requests: wait and try once more
				if ($this->retryWait > 0) {
					sleep($this->retryWait);
				}
				$res = call_user_func($this->searchFunction, $term);
			}
			if (isset($res['error'])) {
				$this->lastErrorReason = (string) ($res['reason'] ?? 'error');
				$out['error'] = true;
				$out['reason'] = $this->lastErrorReason;
				return $out;
			}
			if (empty($res['matches']) || !is_array($res['matches'])) {
				continue;
			}
			foreach ($res['matches'] as $match) {
				$info = peppolDirectoryMatchInfo($match, $soc);
				if ($info['participantId'] !== '' && $info['matchedField'] !== '') {
					$found[strtolower($info['participantId'])] = $info;
				}
			}
		}

		foreach ($found as $info) {
			$r = $this->saveCandidate($soc->id, $info);
			if ($r < 0) {
				$this->lastErrorReason = 'database error';
				$out['error'] = true;
				$out['reason'] = $this->lastErrorReason;
				return $out;
			}
			$out['found']++;
			if ($r == 2) {
				$out['new']++;
			}
		}
		$this->saveLookup($soc->id, count($found));
		return $out;
	}

	/**
	 * Insert or update a candidate
	 *
	 * @param	int		$socid	Third party id
	 * @param	array{participantId:string,scheme:string,icd:string,value:string,name:string,country:string,location:string,regDate:string,additionalInfo:string,acceptsInvoice:bool|null,matchedField:string}	$info	Result of peppolDirectoryMatchInfo()
	 * @return	int				2 inserted, 1 updated, <0 error
	 */
	protected function saveCandidate($socid, $info)
	{
		global $conf;


		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "peppol_candidate";
		$sql .= " WHERE entity = " . ((int) $conf->entity) . " AND fk_soc = " . ((int) $socid);
		$sql .= " AND participant_id = '" . $this->db->escape($info['participantId']) . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if ($obj) {
			$sql = "UPDATE " . MAIN_DB_PREFIX . "peppol_candidate SET";
			$sql .= " dir_name = '" . $this->db->escape(dol_trunc($info['name'], 255, 'right', 'UTF-8', 1)) . "',";
			$sql .= " matched_field = '" . $this->db->escape($info['matchedField']) . "',";
			$sql .= " accepts_invoice = " . ($info['acceptsInvoice'] === null ? 'NULL' : ($info['acceptsInvoice'] ? '1' : '0')) . ",";
			$sql .= " reg_date = '" . $this->db->escape(dol_trunc($info['regDate'], 32, 'right', 'UTF-8', 1)) . "',";
			$sql .= " date_checked = '" . $this->db->idate(dol_now()) . "'";
			$sql .= " WHERE rowid = " . ((int) $obj->rowid);
			return $this->db->query($sql) ? 1 : -1;
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "peppol_candidate";
		$sql .= " (entity, fk_soc, participant_id, participant_scheme, dir_name, matched_field, accepts_invoice, reg_date, status, date_found, date_checked)";
		$sql .= " VALUES (" . ((int) $conf->entity) . ", " . ((int) $socid) . ",";
		$sql .= " '" . $this->db->escape(dol_trunc($info['participantId'], 255, 'right', 'UTF-8', 1)) . "',";
		$sql .= " '" . $this->db->escape(dol_trunc($info['scheme'], 64, 'right', 'UTF-8', 1)) . "',";
		$sql .= " '" . $this->db->escape(dol_trunc($info['name'], 255, 'right', 'UTF-8', 1)) . "',";
		$sql .= " '" . $this->db->escape($info['matchedField']) . "',";
		$sql .= " " . ($info['acceptsInvoice'] === null ? 'NULL' : ($info['acceptsInvoice'] ? '1' : '0')) . ",";
		$sql .= " '" . $this->db->escape(dol_trunc($info['regDate'], 32, 'right', 'UTF-8', 1)) . "',";
		$sql .= " " . self::STATUS_NEW . ", '" . $this->db->idate(dol_now()) . "', '" . $this->db->idate(dol_now()) . "')";
		return $this->db->query($sql) ? 2 : -1;
	}

	/**
	 * Remember when a third party was checked
	 *
	 * @param	int		$socid		Third party id
	 * @param	int		$nbfound	Number of IDs found
	 * @return	int					>0 if OK
	 */
	protected function saveLookup($socid, $nbfound)
	{
		global $conf;

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "peppol_lookup";
		$sql .= " WHERE entity = " . ((int) $conf->entity) . " AND fk_soc = " . ((int) $socid);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if ($obj) {
			$sql = "UPDATE " . MAIN_DB_PREFIX . "peppol_lookup SET date_checked = '" . $this->db->idate(dol_now()) . "', nb_found = " . ((int) $nbfound);
			$sql .= " WHERE rowid = " . ((int) $obj->rowid);
		} else {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "peppol_lookup (entity, fk_soc, date_checked, nb_found)";
			$sql .= " VALUES (" . ((int) $conf->entity) . ", " . ((int) $socid) . ", '" . $this->db->idate(dol_now()) . "', " . ((int) $nbfound) . ")";
		}
		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Store the Peppol ID of a candidate on its third party and mark the candidate as linked
	 *
	 * @param	int		$candidateid	Candidate id
	 * @param	User	$user			User
	 * @return	int						>0 linked, 0 skipped (third party already has a Peppol ID), <0 error
	 */
	public function link($candidateid, $user)
	{
		global $conf;

		$cand = $this->fetchCandidate($candidateid);
		if (!$cand) {
			return -1;
		}
		$soc = new Societe($this->db);
		if ($soc->fetch((int) $cand->fk_soc) <= 0) {
			return -1;
		}
		if (!empty($soc->array_options['options_peppol_id'])) {
			return 0;
		}
		$soc->array_options['options_peppol_id'] = $cand->participant_id;
		// Update only this extra field, the other extra fields of the third party stay as they are
		if ($soc->updateExtraField('peppol_id', null, $user) < 0) {
			$this->error = $soc->error;
			return -1;
		}
		return $this->setStatus($candidateid, self::STATUS_LINKED, $user);
	}

	/**
	 * Change the status of a candidate
	 *
	 * @param	int		$candidateid	Candidate id
	 * @param	int		$status			STATUS_NEW, STATUS_LINKED or STATUS_IGNORED
	 * @param	User	$user			User
	 * @return	int						>0 if OK
	 */
	public function setStatus($candidateid, $status, $user)
	{
		global $conf;

		$sql = "UPDATE " . MAIN_DB_PREFIX . "peppol_candidate SET status = " . ((int) $status) . ", fk_user_modif = " . ((int) $user->id);
		$sql .= " WHERE rowid = " . ((int) $candidateid) . " AND entity = " . ((int) $conf->entity);
		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Read a candidate of the current entity
	 *
	 * @param	int		$candidateid	Candidate id
	 * @return	object|null
	 */
	public function fetchCandidate($candidateid)
	{
		global $conf;

		$sql = "SELECT rowid, fk_soc, participant_id, status FROM " . MAIN_DB_PREFIX . "peppol_candidate";
		$sql .= " WHERE rowid = " . ((int) $candidateid) . " AND entity = " . ((int) $conf->entity);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? $obj : null;
	}
}
