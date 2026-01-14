<?php

/**
 * \file    peppol_search_directory.php
 * \ingroup peppol
 * \brief   Search PEPPOL directory via Access Point API
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
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

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

// load peppol libraries
dol_include_once('/peppolpeppyrus/class/peppol.class.php');
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

// Load translation files
$langs->loadLangs(array("peppol@peppolpeppyrus", "companies"));

// Get parameters
$action = GETPOST('action', 'aZ09');
$search_query = GETPOST('search_query', 'alpha');
$search_name = GETPOST('search_name', 'alpha');
$search_country = GETPOST('search_country', 'aZ09');
$socid = GETPOST('socid', 'int');

// Security check
$result = restrictedArea($user, 'peppolpeppyrus');

// Load third party if socid is provided
$thirdparty = null;
if ($socid > 0) {
	$thirdparty = new Societe($db);
	$thirdparty->fetch($socid);
}

/*
 * Actions
 */

$searchResults = null;

if ($action == 'search') {
	$ap = peppolGetAPObject();
	if ($ap && method_exists($ap, 'searchPeppolDirectory')) {
		$params = [];

		if (!empty($search_query)) {
			$params['query'] = $search_query;
		}
		if (!empty($search_name)) {
			$params['name'] = $search_name;
		}
		if (!empty($search_country)) {
			$params['country'] = $search_country;
		}

		if (!empty($params)) {
			$searchResults = $ap->searchPeppolDirectory($params);
			dol_syslog("Peppol directory search result: " . json_encode($searchResults));
		} else {
			setEventMessage($langs->trans('peppolSearchErrorNoParams'), 'warnings');
		}
	} else {
		setEventMessage($langs->trans('peppolSearchNotAvailable'), 'errors');
	}
}

if ($action == 'select' && $socid > 0) {
	$participantid = GETPOST('participantid', 'alpha');
	if (!empty($participantid) && $thirdparty) {
		$thirdparty->array_options['options_peppol_id'] = $participantid;
		// Save extrafield - update() alone doesn't save extrafields in Dolibarr!
		$thirdparty->insertExtraFields();
		setEventMessage($langs->trans('peppolIdSet'));
		print "<script>window.top.location.href = \"" . dol_buildpath('/societe/card.php?id='.$socid, 1) . "\";</script>";
		exit;
	}
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("peppolSearchDirectory");
$help_url = '';

llxHeader('', $title, $help_url);

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="search">';
if ($socid > 0) {
	print '<input type="hidden" name="socid" value="' . $socid . '">';
}

print load_fiche_titre($title, '', 'title_generic.png');

// Display third party info if available
if ($thirdparty && $thirdparty->id > 0) {
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">' . $langs->trans("ThirdParty") . '</td>';
	print '<td>' . $thirdparty->getNomUrl(1) . '</td></tr>';
	print '<tr><td>' . $langs->trans("Name") . '</td>';
	print '<td>' . dol_escape_htmltag($thirdparty->name) . '</td></tr>';
	if (!empty($thirdparty->country_code)) {
		print '<tr><td>' . $langs->trans("Country") . '</td>';
		print '<td>' . dol_escape_htmltag($thirdparty->country_code) . '</td></tr>';
	}
	print '</table>';
	print '<br>';
}

// Search form
print '<table class="border centpercent">';

print '<tr><td class="titlefield">' . $langs->trans("SearchQuery") . '</td>';
print '<td>';
print '<input type="text" name="search_query" size="50" value="' . dol_escape_htmltag($search_query) . '" placeholder="' . $langs->trans("SearchQuery") . '...">';
print '</td></tr>';

print '<tr><td>' . $langs->trans("peppolSearchByName") . '</td>';
print '<td>';
print '<input type="text" name="search_name" size="50" value="' . dol_escape_htmltag($search_name) . '" placeholder="' . $langs->trans("CompanyName") . '...">';
print '</td></tr>';

print '<tr><td>' . $langs->trans("peppolSearchByCountry") . '</td>';
print '<td>';
print '<input type="text" name="search_country" size="10" value="' . dol_escape_htmltag($search_country) . '" placeholder="BE, FR, NL...">';
print '</td></tr>';

print '</table>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button" value="' . $langs->trans("Search") . '">';
print '</div>';

print '</form>';

// Display search results
if (!empty($searchResults) && is_array($searchResults)) {
	print '<br><hr><br>';

	$count = count($searchResults);
	print '<h3>' . $langs->trans("SearchResults") . ' (' . $count . ' ' . $langs->trans("results") . ')</h3>';

	if ($count > 0) {
		print '<div class="div-table-responsive">';
		print '<table class="tagtable nobottomiftotal liste">';

		// Headers
		print '<tr class="liste_titre">';
		print '<th>' . $langs->trans("peppolParticipantID") . '</th>';
		print '<th>' . $langs->trans("peppolName") . '</th>';
		print '<th>' . $langs->trans("peppolCountryCode") . '</th>';
		print '<th>' . $langs->trans("peppolAction") . '</th>';
		print '</tr>';

		foreach ($searchResults as $result) {
			$participantId = isset($result['participantId']) ? $result['participantId'] : (isset($result['participantID']) ? $result['participantID'] : '');
			$name = isset($result['name']) ? $result['name'] : '';
			$countryCode = isset($result['countryCode']) ? $result['countryCode'] : '';

			// Handle nested structures
			if (empty($name) && isset($result['entities'][0]['name'][0]['name'])) {
				$name = $result['entities'][0]['name'][0]['name'];
			}
			if (empty($countryCode) && isset($result['entities'][0]['countryCode'])) {
				$countryCode = $result['entities'][0]['countryCode'];
			}

			print '<tr class="oddeven">';

			// Participant ID
			print '<td>';
			print '<strong>' . dol_escape_htmltag($participantId) . '</strong>';
			print '</td>';

			// Name
			print '<td>' . dol_escape_htmltag($name) . '</td>';

			// Country
			print '<td>' . dol_escape_htmltag($countryCode) . '</td>';

			// Action
			print '<td>';
			if ($socid > 0 && !empty($participantId)) {
				print '<a class="button smallpaddingimp" href="' . $_SERVER["PHP_SELF"] . '?socid=' . $socid . '&action=select&participantid=' . urlencode($participantId) . '&token=' . newToken() . '">';
				print $langs->trans("PeppolApplyPeppolID");
				print '</a>';
			} else {
				print '-';
			}
			print '</td>';

			print '</tr>';
		}

		print '</table>';
		print '</div>';
	} else {
		print '<div class="info">' . $langs->trans("NoResultFound") . '</div>';
	}
} elseif ($action == 'search') {
	print '<br><div class="warning">' . $langs->trans("NoResultFound") . '</div>';
}

// End of page
llxFooter();
$db->close();
