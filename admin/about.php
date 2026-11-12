<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * \file    peppol/admin/about.php
 * \ingroup peppol
 * \brief   About page of module Peppol.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");


/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */
// Libraries
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once '../lib/peppol.lib.php';

// Translations
$langs->loadLangs(array("errors", "admin", "peppol@peppolpeppyrus"));

// Access control
if (!$user->admin) accessforbidden();

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');


/*
 * Actions
 */

// None


/*
 * View
 */

$form = new Form($db);

$page_name = "PeppolAbout";
llxHeader('', $langs->trans($page_name));

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = peppolAdminPrepareHead();
print dol_get_fiche_head($head, 'about',  $langs->trans($page_name), -1, 'peppol@peppolpeppyrus');

dol_include_once('/peppolpeppyrus/core/modules/modPeppolpeppyrus.class.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/parsemd.lib.php';
$tmpmodule = new \modPeppolpeppyrus($db);

print peppolDolibarrRecommendedNotice();

// Version of the files and version activated in this entity (they differ until the module is reactivated)
$activeVersion = getDolGlobalString('PEPPOL_MODULE_VERSION');
print '<div class="info">';
print '<strong>' . $langs->trans('Version') . ':</strong> ' . dol_escape_htmltag($tmpmodule->version);
if ($activeVersion !== '' && $activeVersion !== $tmpmodule->version) {
	print ' &nbsp; <span class="warning">' . $langs->trans('peppolAboutReactivate', dol_escape_htmltag($activeVersion)) . '</span>';
}
print '<br><a href="' . dol_escape_htmltag($tmpmodule->editor_url) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($tmpmodule->editor_url) . '</a>';
print '</div>';

// What changed: the most recent versions of CHANGELOG.md (in English, like the file)
$changelogFile = dirname(__DIR__) . '/CHANGELOG.md';
if (is_readable($changelogFile)) {
	print load_fiche_titre($langs->trans('peppolAboutChangelog'), '', '');
	print '<div class="opacitymedium small marginbottomonly">' . $langs->trans('peppolAboutChangelogHelp') . '</div>';
	print '<div class="peppol-changelog">' . dolMd2Html(peppolChangelogExcerpt((string) file_get_contents($changelogFile), 5), 'parsedown') . '</div>';
	print '<br>';
}

print load_fiche_titre($langs->trans('peppolAboutReadme'), '', '');
print $tmpmodule->getDescLong();

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
