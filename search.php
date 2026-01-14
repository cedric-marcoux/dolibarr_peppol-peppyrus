<?php

/**
 * \file    peppol_search.php
 * \ingroup peppol
 * \brief   Search PEPPOL participant identifiers via PEPPOL Directory API
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

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

// load peppol libraries
dol_include_once('/peppolpeppyrus/class/peppol.class.php');
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');

use custom\peppolpeppyrus\Peppol;

// Load translation files
$langs->loadLangs(array("peppol@peppolpeppyrus", "companies"));

// Get parameters
$action = GETPOST('action', 'aZ09');
$search_query = GETPOST('search_query', 'aZ09');
$socid = GETPOST('socid', 'int');

// Security check
$result = restrictedArea($user, 'peppolpeppyrus');

// Load third party if socid is provided
$thirdparty = null;
if ($socid > 0) {
	$thirdparty = new Societe($db);
	$res = $thirdparty->fetch($socid);
	if ($res) {
		if ($action == "") {
			dol_syslog("peppol : Load socid " . $socid . " success ...");

			if (!empty($thirdparty->tva_intra)) {
				$search_query = strtoupper(preg_replace('/[^A-Z0-9]/', '', $thirdparty->tva_intra));
			}
			$action = 'search';
			dol_syslog("peppol : action " . $action . " search=" . $search_query);
		}
	}
}

/*
 * Actions
 */

if ($action == 'search' && !empty($search_query)) {
	dol_syslog("Peppol search query is " . json_encode($search_query));
	$searchResults = Peppol::searchPeppolDirectory($search_query);
	dol_syslog("Peppol search result is " . json_encode($searchResults));
}

// Auto-detect PEPPOL ID by VAT using Access Point API (Peppyrus bestMatch)
$autoDetectedId = null;
if ($action == 'autodetect' && $socid > 0 && $thirdparty) {
	$ap = peppolGetAPObject();
	if ($ap && method_exists($ap, 'findPeppolIdByVat')) {
		// Extract VAT number without country prefix
		$vatNumber = preg_replace('/^[A-Z]{2}/', '', $thirdparty->tva_intra);
		$countryCode = $thirdparty->country_code;

		$result = $ap->findPeppolIdByVat($vatNumber, $countryCode);
		if (is_array($result) && isset($result['participantId'])) {
			$autoDetectedId = $result['participantId'];
			dol_syslog("Peppol autodetect found: " . $autoDetectedId);
		}
	} else {
		setEventMessage($langs->trans('peppolAutoDetectNotAvailable'), 'warnings');
	}
}

if ($action == 'select') {
	$participantid = GETPOST('participantid');
	$peppol_scheme = GETPOST('peppol_scheme');

	// Extract ICD scheme code from full scheme identifier (e.g., "iso6523-actorid-upis::0208" -> "0208")
	$schemeCode = '';
	if (preg_match('/::(\d+)$/', $peppol_scheme, $matches)) {
		$schemeCode = $matches[1];
	}

	// Save as "schemeCode:participantId" (e.g., "0208:0475670182")
	if (!empty($schemeCode) && !empty($participantid)) {
		$thirdparty->array_options['options_peppol_id'] = $schemeCode . ':' . $participantid;
	} else {
		// Fallback: save participantid as-is if scheme extraction failed
		$thirdparty->array_options['options_peppol_id'] = $participantid;
	}

	// Save extrafield - update() alone doesn't save extrafields in Dolibarr!
	$thirdparty->insertExtraFields();
	setEventMessage($langs->trans('peppolIdSet'));
	print "<script>window.top.location.href = \"" . dol_buildpath('/societe/card.php?id='.$socid, 1) . "\";</script>";
	// header('Location: '.dol_buildpath('/societe/card.php?id='.$socid, 1));
	exit;
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("PeppolSearch");
$help_url = '';
$arrayofjs = ["	$(\"*[id*='idfordialog']\").each(function() {
		$(this).on('dialogclose', function(event) {
			location.reload();
		});
	});
"];
llxHeader('', $title, $help_url,'',0,0,$arrayofjs);

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="search">';
if ($socid > 0) {
	print '<input type="hidden" name="socid" value="' . $socid . '">';
}

// Show title with thirdparty name if available
if ($thirdparty && $thirdparty->id > 0) {
	print load_fiche_titre($langs->trans("peppolFindPeppolIDPopupTitle") . ' - ' . dol_escape_htmltag($thirdparty->name), '', 'peppol@peppolpeppyrus');
} else {
	print load_fiche_titre($title, '', 'peppol@peppolpeppyrus');
	// Only show search form if no thirdparty context
	print '<table class="border centpercent">';
	print '<tr><td class="fieldrequired titlefield">' . $langs->trans("SearchQuery") . '</td>';
	print '<td>';
	print '<input type="text" name="search_query" size="50" value="' . dol_escape_htmltag($search_query) . '" placeholder="' . $langs->trans("CompanyName") . ' / VAT / SIREN...">';
	print '</td></tr>';
	print '</table>';
	print '<div class="center" style="margin-top: 10px;">';
	print '<input type="submit" class="button" value="' . $langs->trans("Search") . '">';
	print '</div>';
}

print '</form>';

// Display auto-detected result
if (!empty($autoDetectedId)) {
	print '<br>';
	print '<div class="info">';
	print '<strong>' . $langs->trans("peppolBestMatchSuccess") . ':</strong> ' . dol_escape_htmltag($autoDetectedId);
	print '<br><br>';
	print '<a class="button" href="' . $_SERVER["PHP_SELF"] . '?socid=' . $socid . '&action=select&participantid=' . urlencode($autoDetectedId) . '">';
	print $langs->trans("PeppolApplyPeppolID");
	print '</a>';
	print '</div>';
}

// Display search results
if (!empty($searchResults)) {
	if (isset($searchResults['error'])) {
		print '<div class="error">' . $searchResults['error'] . '</div>';
	} elseif (isset($searchResults['matches']) && is_array($searchResults['matches'])) {
		$matches = $searchResults['matches'];
		$totalCount = count($matches);

		if ($totalCount > 0) {
			// If only one result and we have a socid, show simplified view
			if ($totalCount == 1 && $socid > 0) {
				$match = $matches[0];
				$participantId = isset($match['participantID']) ? $match['participantID']['value'] : '';
				$scheme = isset($match['participantID']) ? $match['participantID']['scheme'] : '';
				$name = isset($match['entities'][0]['name'][0]['name']) ? $match['entities'][0]['name'][0]['name'] : '';

				print '<div class="info" style="margin-top: 20px; padding: 15px;">';
				print '<p><strong>' . $langs->trans("peppolBestMatchSuccess") . '</strong></p>';
				print '<p style="font-size: 1.2em; margin: 10px 0;"><code>' . dol_escape_htmltag($participantId) . '</code></p>';
				if (!empty($name)) {
					print '<p style="color: #666;">' . dol_escape_htmltag($name) . '</p>';
				}
				print '<br>';
				print '<a class="button" href="' . $_SERVER["PHP_SELF"] . '?socid=' . $socid . '&action=select&peppol_scheme=' . urlencode($scheme) . '&participantid=' . urlencode($participantId) . '">';
				print $langs->trans("PeppolApplyPeppolID");
				print '</a>';
				print '</div>';
			} else {
				// Multiple results - show simple table
				print '<br>';
				print '<div class="div-table-responsive">';
				print '<table class="tagtable nobottomiftotal liste">';
				print '<tr class="liste_titre">';
				print '<th>' . $langs->trans("peppolParticipantID") . '</th>';
				print '<th>' . $langs->trans("peppolName") . '</th>';
				print '<th>' . $langs->trans("peppolCountryCode") . '</th>';
				if ($socid > 0) {
					print '<th></th>';
				}
				print '</tr>';

				foreach ($matches as $match) {
					$participantId = isset($match['participantID']) ? $match['participantID']['value'] : '';
					$scheme = isset($match['participantID']) ? $match['participantID']['scheme'] : '';
					$name = isset($match['entities'][0]['name'][0]['name']) ? $match['entities'][0]['name'][0]['name'] : '';
					$countryCode = isset($match['entities'][0]['countryCode']) ? $match['entities'][0]['countryCode'] : '';

					print '<tr class="oddeven">';
					print '<td><strong>' . dol_escape_htmltag($participantId) . '</strong></td>';
					print '<td>' . dol_escape_htmltag($name) . '</td>';
					print '<td>' . dol_escape_htmltag($countryCode) . '</td>';
					if ($socid > 0) {
						print '<td>';
						print '<a class="button smallpaddingimp" href="' . $_SERVER["PHP_SELF"] . '?socid=' . $socid . '&action=select&peppol_scheme=' . urlencode($scheme) . '&participantid=' . urlencode($participantId) . '">';
						print $langs->trans("PeppolApplyPeppolID");
						print '</a>';
						print '</td>';
					}
					print '</tr>';
				}
				print '</table>';
				print '</div>';
			}
		} else {
			print '<div class="warning" style="margin-top: 20px;">' . $langs->trans("peppolBestMatchNotFound") . '</div>';
		}
	} else {
		print '<div class="warning">' . $langs->trans("InvalidResponse") . '</div>';
	}
}
// End of page
llxFooter();
$db->close();
