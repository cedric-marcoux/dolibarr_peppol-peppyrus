<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    peppol_outgoing.php
 * \ingroup peppol
 * \brief   Outgoing invoices with their status at Peppyrus: waiting, sent or not delivered (since 2.12.0)
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
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
 * @var Translate $langs
 * @var User $user
 */
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

$langs->loadLangs(array("peppol@peppolpeppyrus", "bills", "companies"));

// Module enabled, right of the module and to read invoices; no external users
if (!isModEnabled('peppolpeppyrus') || !$user->hasRight('peppolpeppyrus', 'read') || !$user->hasRight('facture', 'lire') || !empty($user->socid)) {
	accessforbidden();
}

$search_status = GETPOST('search_status', 'aZ09');
if (!in_array($search_status, array('pending', 'queued', 'failed', 'sent', 'all'), true)) {
	$search_status = 'pending';
}
$days = GETPOSTINT('days');
if (!in_array($days, array(30, 90, 365), true)) {
	$days = 90;
}
$since = dol_now() - $days * 86400;

/*
 * View
 */

// Own sent invoices (with a Peppyrus message id) in the chosen period; without the right to see all customers only
// the own customers
$facturen = array();
$sql = "SELECT f.rowid, f.ref, f.datef, f.total_ttc, s.rowid AS socid, s.nom, fe.peppol_id";
$sql .= " FROM " . MAIN_DB_PREFIX . "facture AS f";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields AS fe ON fe.fk_object = f.rowid";
$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe AS s ON s.rowid = f.fk_soc";
if (!$user->hasRight('societe', 'client', 'voir')) {
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe_commerciaux AS sc ON sc.fk_soc = s.rowid AND sc.fk_user = " . ((int) $user->id);
}
$sql .= " WHERE f.entity IN (" . getEntity('invoice') . ") AND fe.peppol_id IS NOT NULL AND fe.peppol_id <> ''";
$sql .= " AND f.datef >= '" . $db->idate($since) . "'";
$sql .= " ORDER BY f.datef DESC, f.rowid DESC LIMIT 1000";
$resql = $db->query($sql);
while ($resql && ($obj = $db->fetch_object($resql))) {
	$facturen[] = $obj;
}

// The folders OUTBOX and FAILED at Peppyrus; what is not in them has been sent. This can take a moment: the session
// is released so other pages do not wait.
$outbox = array();
$failed = array();
$fout = '';
$ap = peppolGetAPObject();
if (!empty($facturen)) {
	peppol_sessie_los();
	foreach (array('OUTBOX' => 'outbox', 'FAILED' => 'failed') as $map => $doel) {
		$lijst = ($ap && method_exists($ap, 'getOutgoingFolder')) ? $ap->getOutgoingFolder($map, 10) : -1;
		if (!is_array($lijst)) {
			$fout = $langs->trans('peppolOutgoingNoAnswer');
			break;
		}
		foreach ($lijst as $m) {
			${$doel}[$m['id']] = true;
		}
	}
	peppol_sessie_terug();
}

// Since 2.13.0: the latest answer of the customer (Invoice Response) per invoice, from the Peppol log
$antwoorden = array();
if (!empty($facturen)) {
	$ids = array_map(function ($f) {
		return (int) $f->rowid;
	}, $facturen);
	$sql = "SELECT fk_object, status, message FROM " . MAIN_DB_PREFIX . "peppol WHERE object_typeid = 1 AND status LIKE 'IR:%'";
	$sql .= " AND entity IN (" . getEntity('invoice') . ") AND fk_object IN (" . $db->sanitize(implode(',', $ids)) . ") ORDER BY rowid ASC";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$niveau = peppolResponseLevel(substr((string) $obj->status, 3));
		if ($niveau !== '') {
			$antwoorden[(int) $obj->fk_object] = array($niveau, (string) $obj->message);
		}
	}
}

$aantal = array('queued' => 0, 'failed' => 0, 'sent' => 0, 'unknown' => 0);
$regels = array();
foreach ($facturen as $f) {
	// Without an answer from Peppyrus the status is unknown (not "delivered")
	$stand = $fout !== '' ? 'unknown' : peppolOutgoingStatus((string) $f->peppol_id, $outbox, $failed);
	$aantal[$stand]++;
	if ($search_status === 'all' || $search_status === $stand || ($search_status === 'pending' && $stand !== 'sent') || $stand === 'unknown') {
		$regels[] = array($f, $stand);
	}
}

$title = $langs->trans('peppolOutgoingTitle');
llxHeader('', $title, '');
print load_fiche_titre($title, '', 'peppol@peppolpeppyrus');
print '<div class="opacitymedium marginbottomonly">' . $langs->trans('peppolOutgoingIntro') . '</div>';
if ($fout !== '') {
	print '<div class="warning">' . dol_escape_htmltag($fout) . '</div>';
}

// Filter
$keuzes = array('pending' => 'peppolOutgoingPending', 'queued' => 'peppolOutgoingQueued', 'failed' => 'peppolOutgoingFailed', 'sent' => 'peppolOutgoingSent', 'all' => 'All');
print '<form method="GET" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" class="marginbottomonly">';
print '<select name="search_status" class="flat">';
foreach ($keuzes as $code => $sleutel) {
	print '<option value="' . dol_escape_htmltag($code) . '"' . ($search_status === $code ? ' selected' : '') . '>' . dol_escape_htmltag($langs->transnoentitiesnoconv($sleutel)) . '</option>';
}
print '</select> <select name="days" class="flat">';
foreach (array(30, 90, 365) as $d) {
	print '<option value="' . $d . '"' . ($days === $d ? ' selected' : '') . '>' . dol_escape_htmltag($langs->transnoentitiesnoconv('peppolOutgoingDays', $d)) . '</option>';
}
print '</select> <input type="submit" class="button smallpaddingimp" value="' . dol_escape_htmltag($langs->transnoentitiesnoconv('Search')) . '">';
print ' <span class="opacitymedium marginleftonly">' . $langs->trans('peppolOutgoingCounts', $aantal['queued'], $aantal['failed'], $aantal['sent']) . '</span>';
print '</form>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';
print '<tr class="liste_titre"><th>' . $langs->trans('Invoice') . '</th><th>' . $langs->trans('Date') . '</th><th>' . $langs->trans('Customer') . '</th><th class="right">' . $langs->trans('AmountTTC') . '</th><th>' . $langs->trans('Status') . '</th></tr>';
// Ticks: one (grey) = waiting at Peppyrus, two (green) = delivered to the access point of the customer, a red cross =
// not delivered. Since 2.13.0 a third tick when the customer sends an Invoice Response (only when the customer does:
// Peppol does not require it).
$teken = array(
	'queued' => array('fa-check', 'opacitymedium', 'peppolOutgoingQueued'),
	'sent' => array('fa-check-double', 'peppol-vinkjes-ok', 'peppolOutgoingSent'),
	'failed' => array('fa-times', 'error', 'peppolOutgoingFailed'),
	'unknown' => array('fa-question', 'opacitymedium', 'peppolOutgoingUnknown'),
);
// Since 2.13.0: the answer of the customer (Invoice Response)
$drie = '<span class="fas fa-check-double"></span><span class="fas fa-check" style="margin-left:-0.35em"></span>';
$antwoordTeken = array(
	'read' => array($drie, '#2d7dd2', 'peppolOutgoingRead'),
	'approved' => array($drie, '#25a580', 'peppolOutgoingApproved'),
	'query' => array('<span class="fas fa-question-circle"></span>', '#d68a00', 'peppolOutgoingQuery'),
	'rejected' => array('<span class="fas fa-times-circle"></span>', '#c0392b', 'peppolOutgoingRejected'),
);
foreach ($regels as $r) {
	list($f, $stand) = $r;
	print '<tr class="oddeven">';
	print '<td class="nowraponall"><a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . ((int) $f->rowid) . '">' . img_picto('', 'bill', 'class="pictofixedwidth"') . dol_escape_htmltag((string) $f->ref) . '</a>';
	print ' <a class="marginleftonly" href="' . dol_buildpath('/peppolpeppyrus/peppol_tab.php', 1) . '?id=' . ((int) $f->rowid) . '" title="' . dol_escape_htmltag($langs->transnoentitiesnoconv('peppolDeliveryReport')) . '">' . img_picto('', 'fa-paper-plane') . '</a></td>';
	print '<td class="nowraponall">' . dol_print_date($db->jdate($f->datef), 'day') . '</td>';
	print '<td class="tdoverflowmax200"><a href="' . DOL_URL_ROOT . '/societe/card.php?socid=' . ((int) $f->socid) . '">' . dol_escape_htmltag((string) $f->nom) . '</a></td>';
	print '<td class="right nowraponall">' . price((float) $f->total_ttc, 0, $langs, 1, -1, -1, $conf->currency) . '</td>';
	$t = $langs->transnoentitiesnoconv($teken[$stand][2]);
	$antwoord = $antwoorden[(int) $f->rowid] ?? null;
	if ($antwoord !== null && $stand !== 'failed') {
		// Answer of the customer: three ticks (received or accepted), a question mark (query) or a red cross (rejected)
		$a = $antwoordTeken[$antwoord[0]];
		print '<td class="nowraponall" title="' . dol_escape_htmltag($antwoord[1]) . '"><span class="peppol-vinkjes" style="color:' . $a[1] . '">' . $a[0] . '</span> <span class="small">' . dol_escape_htmltag($langs->transnoentitiesnoconv($a[2])) . '</span></td>';
	} else {
		print '<td class="nowraponall"><span class="fas ' . $teken[$stand][0] . ' ' . $teken[$stand][1] . '" title="' . dol_escape_htmltag($t) . '"' . ($stand === 'sent' ? ' style="color:#25a580"' : '') . '></span> <span class="small">' . dol_escape_htmltag($t) . '</span></td>';
	}
	print '</tr>';
}
if (empty($regels)) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">' . $langs->trans('peppolOutgoingNone') . '</span></td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
