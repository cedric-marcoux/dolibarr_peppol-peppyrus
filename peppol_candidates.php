<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    peppol_candidates.php
 * \ingroup peppol
 * \brief   Customers and suppliers without a Peppol ID that were found in the Peppol directory: review and link
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');
dol_include_once('/peppolpeppyrus/class/peppolcandidatecheck.class.php');

$langs->loadLangs(array("peppol@peppolpeppyrus", "companies"));

// Security check
if (!isModEnabled('peppolpeppyrus') || !$user->hasRight('peppolpeppyrus', 'read') || !$user->hasRight('societe', 'lire')) {
	accessforbidden();
}
if (!empty($user->socid)) {
	accessforbidden(); // external users
}
$permissiontolink = ($user->hasRight('peppolpeppyrus', 'write') && $user->hasRight('societe', 'creer'));

$action = GETPOST('action', 'aZ09');
$toselect = GETPOST('toselect', 'array');
$search_type = GETPOST('search_type', 'aZ09');
if (!in_array($search_type, array('all', 'customer', 'supplier'))) {
	$search_type = 'all';
}
$search_status = GETPOST('search_status', 'aZ09');
if (!in_array($search_status, array('new', 'linked', 'ignored', 'all'))) {
	$search_status = 'new';
}
$search_since = GETPOST('search_since', 'alpha');
$since = 0;
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $search_since, $reg) && checkdate((int) $reg[2], (int) $reg[3], (int) $reg[1])) {
	$since = dol_mktime(0, 0, 0, (int) $reg[2], (int) $reg[3], (int) $reg[1]);
} else {
	$search_since = '';
}
$limit = GETPOST('limit', 'int') > 0 ? GETPOST('limit', 'int') : $conf->liste_limit;
$page = max(0, (int) GETPOST('page', 'int'));
$offset = $limit * $page;

$check = new PeppolCandidateCheck($db);
$param = '&search_type=' . urlencode($search_type) . '&search_status=' . urlencode($search_status) . ($search_since !== '' ? '&search_since=' . urlencode($search_since) : '');

/**
 * Restrict a query on third parties to the ones the user may see (sales representatives see only their own)
 *
 * @param	User	$user	User
 * @return	string			SQL to add to the FROM part
 */
function peppolCandidateVisibleSocJoin($user)
{
	if ($user->hasRight('societe', 'client', 'voir')) {
		return '';
	}
	return " INNER JOIN " . MAIN_DB_PREFIX . "societe_commerciaux as sc ON sc.fk_soc = s.rowid AND sc.fk_user = " . ((int) $user->id);
}

/*
 * Actions
 */

if (in_array($action, array('link', 'ignore', 'restore', 'checknow'))) {
	if (!peppolCheckToken()) {
		accessforbidden($langs->trans('ErrorBadValueForParameter', 'token'));
	}
	if (!$permissiontolink) {
		accessforbidden();
	}
}

if ($action == 'checknow') {
	// A small batch, so the page answers within the time limit of the web server; the session is released meanwhile
	peppol_sessie_los();
	$stats = $check->checkBatch(getDolGlobalInt('PEPPOL_CANDIDATE_BATCH_WEB', 10));
	peppol_sessie_terug();
	setEventMessages($check->resultMessage($stats), null, ($stats['errors'] > 0 ? 'warnings' : 'mesgs'));
	header('Location: ' . $_SERVER['PHP_SELF'] . '?' . ltrim($param, '&'));
	exit;
}

if (in_array($action, array('link', 'ignore', 'restore')) && is_array($toselect) && count($toselect) > 0) {
	// Only candidates of this entity, of third parties this user may see
	$ids = array();
	foreach ($toselect as $id) {
		if ((int) $id > 0) {
			$ids[] = (int) $id;
		}
	}
	$allowed = array();
	if (count($ids)) {
		$sql = "SELECT c.rowid, c.fk_soc FROM " . MAIN_DB_PREFIX . "peppol_candidate as c";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = c.fk_soc";
		$sql .= peppolCandidateVisibleSocJoin($user); // @phan-suppress-current-line SqlInjection -- fixed SQL with only the user id cast to int
		$sql .= " WHERE c.entity = " . ((int) $conf->entity) . " AND s.entity IN (" . getEntity('societe') . ")";
		$sql .= " AND c.rowid IN (" . $db->sanitize(implode(',', $ids)) . ")";
		$sql .= " ORDER BY c.rowid";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$allowed[(int) $obj->rowid] = (int) $obj->fk_soc;
			}
		}
	}

	$nbok = 0;
	$nbskip = 0;
	$nberr = 0;
	$linkedsoc = array();
	$db->begin();
	foreach ($allowed as $candidateid => $socid) {
		if ($action == 'link') {
			if (isset($linkedsoc[$socid])) {
				$nbskip++; // one Peppol ID per third party
				continue;
			}
			$r = $check->link($candidateid, $user);
			if ($r > 0) {
				$linkedsoc[$socid] = true;
			}
		} else {
			$r = $check->setStatus($candidateid, ($action == 'ignore' ? PeppolCandidateCheck::STATUS_IGNORED : PeppolCandidateCheck::STATUS_NEW), $user);
		}
		if ($r > 0) {
			$nbok++;
		} elseif ($r == 0) {
			$nbskip++;
		} else {
			$nberr++;
		}
	}
	if ($nberr > 0) {
		$db->rollback();
		setEventMessages($langs->trans('peppolCandidateActionError'), null, 'errors');
	} else {
		$db->commit();
		setEventMessages($langs->trans('peppolCandidateActionDone_' . $action, $nbok), null, 'mesgs');
		if ($nbskip > 0) {
			setEventMessages($langs->trans('peppolCandidateActionSkipped', $nbskip), null, 'warnings');
		}
	}
	header('Location: ' . $_SERVER['PHP_SELF'] . '?' . ltrim($param, '&'));
	exit;
}

/*
 * View
 */

$form = new Form($db);
$title = $langs->trans('peppolCandidatesTitle');
llxHeader('', $title, '');

// Last check
$lastcheck = 0;
$nbchecked = 0;
$sql = "SELECT MAX(date_checked) as lastcheck, COUNT(rowid) as nb FROM " . MAIN_DB_PREFIX . "peppol_lookup WHERE entity = " . ((int) $conf->entity);
$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$lastcheck = $db->jdate($obj->lastcheck);
	$nbchecked = (int) $obj->nb;
}

// Candidates
$sqlfrom = " FROM " . MAIN_DB_PREFIX . "peppol_candidate as c";
$sqlfrom .= " INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = c.fk_soc";
$sqlfrom .= peppolCandidateVisibleSocJoin($user); // @phan-suppress-current-line SqlInjection -- fixed SQL with only the user id cast to int
$sqlfrom .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe_extrafields as se ON se.fk_object = s.rowid";
$sqlfrom .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_country as co ON co.rowid = s.fk_pays";
$sqlwhere = " WHERE c.entity = " . ((int) $conf->entity) . " AND s.entity IN (" . getEntity('societe') . ")";
if ($search_status == 'new') {
	$sqlwhere .= " AND c.status = " . PeppolCandidateCheck::STATUS_NEW . " AND (se.peppol_id IS NULL OR se.peppol_id = '')";
} elseif ($search_status == 'linked') {
	$sqlwhere .= " AND c.status = " . PeppolCandidateCheck::STATUS_LINKED;
} elseif ($search_status == 'ignored') {
	$sqlwhere .= " AND c.status = " . PeppolCandidateCheck::STATUS_IGNORED;
}
if ($search_type == 'customer') {
	$sqlwhere .= " AND s.client IN (1, 3)";
} elseif ($search_type == 'supplier') {
	$sqlwhere .= " AND s.fournisseur = 1";
}
if ($since > 0) {
	$sqlwhere .= " AND c.date_found >= '" . $db->idate($since) . "'";
}

$nbtotal = 0;
$resql = $db->query("SELECT COUNT(c.rowid) as nb" . $sqlfrom . $sqlwhere);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbtotal = (int) $obj->nb;
}

$sql = "SELECT c.rowid, c.fk_soc, c.participant_id, c.dir_name, c.matched_field, c.accepts_invoice, c.reg_date, c.status, c.date_found,";
$sql .= " s.nom as socname, s.name_alias, s.client, s.fournisseur, co.code as country_code";
$sql .= $sqlfrom . $sqlwhere;
$sql .= " ORDER BY c.date_found DESC, s.nom, c.fk_soc, c.rowid";
$sql .= $db->plimit($limit, $offset);
$rows = array();
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$rows[] = $obj;
	}
} else {
	dol_print_error($db);
}

// Recommended per third party: the first ID that accepts invoices, else the first one where this is unknown
$recommended = array();
foreach (array(1, null) as $wanted) {
	foreach ($rows as $row) {
		$accepts = ($row->accepts_invoice === null) ? null : (int) $row->accepts_invoice;
		if (!isset($recommended[$row->fk_soc]) && $accepts === $wanted) {
			$recommended[$row->fk_soc] = (int) $row->rowid;
		}
	}
}

$newcardbutton = '';
if ($permissiontolink) {
	$newcardbutton = '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" class="inline-block">';
	$newcardbutton .= '<input type="hidden" name="token" value="' . newToken() . '">';
	$newcardbutton .= '<input type="hidden" name="action" value="checknow">';
	$newcardbutton .= '<input type="hidden" name="search_type" value="' . dol_escape_htmltag($search_type) . '">';
	$newcardbutton .= '<input type="hidden" name="search_status" value="' . dol_escape_htmltag($search_status) . '">';
	$newcardbutton .= '<input type="hidden" name="search_since" value="' . dol_escape_htmltag($search_since) . '">';
	$newcardbutton .= '<input type="submit" class="button small" value="' . dol_escape_htmltag($langs->trans('peppolCandidateCheckNow')) . '">';
	$newcardbutton .= '</form>';
}
print load_fiche_titre($title, $newcardbutton, 'peppol@peppolpeppyrus');

print '<div class="opacitymedium" style="margin-bottom: 10px;">' . $langs->trans('peppolCandidatesIntro') . '<br>';
if ($lastcheck) {
	print $langs->trans('peppolCandidateLastCheck', dol_print_date($lastcheck, 'dayhour'), $nbchecked);
} else {
	print $langs->trans('peppolCandidateNeverChecked');
}
print '</div>';

// Filters
print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
print '<div class="divsearchfield">' . $langs->trans('Type') . ' ';
print $form->selectarray('search_type', array('all' => $langs->trans('All'), 'customer' => $langs->trans('Customers'), 'supplier' => $langs->trans('Suppliers')), $search_type);
print '</div>';
print '<div class="divsearchfield">' . $langs->trans('Status') . ' ';
print $form->selectarray('search_status', array('new' => $langs->trans('peppolCandidateStatusNew'), 'linked' => $langs->trans('peppolCandidateStatusLinked'), 'ignored' => $langs->trans('peppolCandidateStatusIgnored'), 'all' => $langs->trans('All')), $search_status);
print '</div>';
print '<div class="divsearchfield">' . $langs->trans('peppolCandidateFoundSince') . ' ';
print '<input type="date" name="search_since" value="' . dol_escape_htmltag($search_since) . '">';
print '</div>';
print '<div class="divsearchfield"><input type="submit" class="button small" value="' . dol_escape_htmltag($langs->trans('Search')) . '"></div>';
print '</form>';
print '<div class="clearboth"></div><br>';

// List
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" id="peppolcandidates">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="search_type" value="' . dol_escape_htmltag($search_type) . '">';
print '<input type="hidden" name="search_status" value="' . dol_escape_htmltag($search_status) . '">';
print '<input type="hidden" name="search_since" value="' . dol_escape_htmltag($search_since) . '">';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre">';
if ($permissiontolink) {
	print '<th class="center"><input type="checkbox" id="checkallcandidates" title="' . dol_escape_htmltag($langs->trans('All')) . '"></th>';
}
print '<th>' . $langs->trans('ThirdParty') . '</th>';
print '<th>' . $langs->trans('Type') . '</th>';
print '<th>' . $langs->trans('peppolParticipantID') . '</th>';
print '<th>' . $langs->trans('peppolCandidateDirName') . '</th>';
print '<th class="center">' . $langs->trans('peppolAcceptsInvoice') . '</th>';
print '<th>' . $langs->trans('peppolMatchThirdparty') . '</th>';
print '<th class="center">' . $langs->trans('peppolCandidateFoundOn') . '</th>';
print '<th class="center">' . $langs->trans('Status') . '</th>';
print '</tr>';

if (count($rows) == 0) {
	print '<tr class="oddeven"><td colspan="' . ($permissiontolink ? 9 : 8) . '"><span class="opacitymedium">' . $langs->trans('peppolCandidateNone') . '</span></td></tr>';
}

$soc = new Societe($db);
foreach ($rows as $row) {
	$soc->id = (int) $row->fk_soc;
	$soc->name = $row->socname;
	$soc->name_alias = $row->name_alias;
	$soc->client = $row->client;
	$soc->fournisseur = $row->fournisseur;

	print '<tr class="oddeven">';
	if ($permissiontolink) {
		print '<td class="center"><input type="checkbox" class="checkforselect" name="toselect[]" value="' . ((int) $row->rowid) . '"></td>';
	}
	print '<td>' . $soc->getNomUrl(1) . '</td>';

	$types = array();
	if (in_array((int) $row->client, array(1, 3))) {
		$types[] = $langs->trans('Customer');
	}
	if ((int) $row->fournisseur == 1) {
		$types[] = $langs->trans('Supplier');
	}
	print '<td>' . dol_escape_htmltag(implode(', ', $types)) . '</td>';

	$icd = preg_match('/^(\d{4}):/', $row->participant_id, $m) ? $m[1] : '';
	print '<td><strong>' . dol_escape_htmltag($row->participant_id) . '</strong>';
	$schemelabel = peppolSchemeLabel($icd);
	if ($schemelabel !== '') {
		print '<br><span class="opacitymedium small">' . dol_escape_htmltag($schemelabel) . '</span>';
	}
	if (isset($recommended[$row->fk_soc]) && $recommended[$row->fk_soc] == (int) $row->rowid && (int) $row->status == PeppolCandidateCheck::STATUS_NEW) {
		print '<br><span class="badge badge-status4">' . $langs->trans('peppolRecommended') . '</span>';
	}
	print '</td>';

	print '<td>' . dol_escape_htmltag($row->dir_name);
	if (!empty($row->reg_date)) {
		print '<br><span class="opacitymedium small">' . $langs->trans('peppolRegisteredSince', dol_escape_htmltag($row->reg_date)) . '</span>';
	}
	print '</td>';

	print '<td class="center">';
	if ($row->accepts_invoice === null) {
		print '<span class="opacitymedium">' . $langs->trans('peppolUnknown') . '</span>';
	} elseif ((int) $row->accepts_invoice == 1) {
		print '<span class="badge badge-status4">' . $langs->trans('Yes') . '</span>';
	} else {
		print '<span class="badge badge-status8">' . $langs->trans('No') . '</span>';
	}
	print '</td>';

	print '<td>';
	if (!empty($row->matched_field)) {
		$fieldlabel = ($row->matched_field == 'tva_intra') ? $langs->trans('VATIntra') : $langs->transcountry(str_replace('idprof', 'ProfId', $row->matched_field), (string) $row->country_code);
		print '<span class="badge badge-status4">' . $langs->trans('peppolMatchesField', dol_escape_htmltag($fieldlabel)) . '</span>';
	}
	print '</td>';

	print '<td class="center nowraponall">' . dol_print_date($db->jdate($row->date_found), 'day') . '</td>';

	$statuskeys = array(PeppolCandidateCheck::STATUS_NEW => 'peppolCandidateStatusNew', PeppolCandidateCheck::STATUS_LINKED => 'peppolCandidateStatusLinked', PeppolCandidateCheck::STATUS_IGNORED => 'peppolCandidateStatusIgnored');
	print '<td class="center">' . $langs->trans($statuskeys[(int) $row->status] ?? 'Unknown') . '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

// Paging
if ($nbtotal > $limit) {
	print '<div class="center" style="margin-top: 8px;">';
	if ($page > 0) {
		print '<a class="button small" href="' . $_SERVER['PHP_SELF'] . '?page=' . ($page - 1) . $param . '">&laquo; ' . $langs->trans('Previous') . '</a> ';
	}
	print '<span class="opacitymedium">' . ($offset + 1) . ' - ' . min($offset + $limit, $nbtotal) . ' / ' . $nbtotal . '</span>';
	if ($offset + $limit < $nbtotal) {
		print ' <a class="button small" href="' . $_SERVER['PHP_SELF'] . '?page=' . ($page + 1) . $param . '">' . $langs->trans('Next') . ' &raquo;</a>';
	}
	print '</div>';
}

// Actions on the selection
if ($permissiontolink && count($rows) > 0) {
	print '<div class="center" style="margin-top: 12px;">';
	if ($search_status != 'ignored') {
		print '<button type="submit" class="button" name="action" value="link">' . $langs->trans('peppolCandidateLink') . '</button> ';
		print '<button type="submit" class="button button-cancel" name="action" value="ignore">' . $langs->trans('peppolCandidateIgnore') . '</button>';
	} else {
		print '<button type="submit" class="button" name="action" value="restore">' . $langs->trans('peppolCandidateRestore') . '</button>';
	}
	print '<div class="opacitymedium small" style="margin-top: 6px;">' . $langs->trans('peppolCandidateLinkHelp') . '</div>';
	print '</div>';
}
print '</form>';

print '<script>
jQuery(document).ready(function() {
	jQuery("#checkallcandidates").on("change", function() {
		jQuery("#peppolcandidates .checkforselect").prop("checked", jQuery(this).is(":checked"));
	});
});
</script>';

llxFooter();
$db->close();
