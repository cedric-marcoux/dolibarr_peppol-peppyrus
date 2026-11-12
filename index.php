<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    index.php
 * \ingroup peppol
 * \brief   Peppol overview: the pages of the module (menu Billing > Peppol)
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
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

$langs->loadLangs(array("peppol@peppolpeppyrus", "companies"));

// Security check: module enabled, internal user, and the right for at least one of the pages
$canDocuments = $user->hasRight('peppolpeppyrus', 'import');
$canCandidates = $user->hasRight('peppolpeppyrus', 'read') && $user->hasRight('societe', 'lire');
if (!isModEnabled('peppolpeppyrus') || !empty($user->socid) || (!$user->hasRight('peppolpeppyrus', 'read') && !$canDocuments)) {
	accessforbidden();
}

/*
 * Actions
 */

// None

/*
 * View
 */

$title = $langs->trans('PeppolMenu');
llxHeader('', $title, '');
print load_fiche_titre($title, '', 'peppol@peppolpeppyrus');

$pages = array();
if ($canDocuments) {
	$pages[] = array('url' => dol_buildpath('/peppolpeppyrus/peppolimport_list.php', 1), 'title' => $langs->trans('peppolMenuDocuments'), 'desc' => $langs->trans('peppolMenuDocumentsDesc'), 'badge' => '');
}
if ($canCandidates) {
	$nb = peppolCountNewCandidates($user);
	$pages[] = array('url' => dol_buildpath('/peppolpeppyrus/peppol_candidates.php', 1), 'title' => $langs->trans('peppolCandidatesMenu'), 'desc' => $langs->trans('peppolMenuCandidatesDesc'), 'badge' => ($nb > 0 ? $langs->trans('peppolCandidatesNewCount', $nb) : ''));
}
if ($canDocuments) {
	$pages[] = array('url' => dol_buildpath('/peppolpeppyrus/peppol_list.php', 1), 'title' => $langs->trans('PeppolList'), 'desc' => $langs->trans('peppolMenuLogDesc'), 'badge' => '');
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('peppolMenuPage') . '</th><th>' . $langs->trans('Description') . '</th></tr>';
foreach ($pages as $p) {
	print '<tr class="oddeven">';
	print '<td class="nowraponall"><a href="' . $p['url'] . '">' . $p['title'] . '</a>';
	if ($p['badge'] !== '') {
		print ' <span class="badge badge-status4">' . $p['badge'] . '</span>';
	}
	print '</td>';
	print '<td>' . $p['desc'] . '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

llxFooter();
$db->close();
