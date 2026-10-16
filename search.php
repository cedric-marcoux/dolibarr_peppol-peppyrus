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


/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */
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
// alphanohtml: names contain spaces and dots ("MKB ONDERNEMERS B.V."), aZ09 would empty them
$search_query = trim(GETPOST('search_query', 'alphanohtml'));
$socid = GETPOST('socid', 'int');
$backto = GETPOST('backto', 'aZ09');
if (!in_array($backto, array('card', 'comm', 'fourn'))) {
	$backto = 'card';
}
$backurl = peppolThirdpartyBackUrl($socid, $backto);

// Security check
$result = restrictedArea($user, 'peppolpeppyrus');

// Load third party if socid is provided
$thirdparty = null;
if ($socid > 0) {
	restrictedArea($user, 'societe', $socid, '&societe', '', 'fk_soc', 'rowid', 0);
	$thirdparty = new Societe($db);
	$res = $thirdparty->fetch($socid);
	if ($res <= 0) {
		$thirdparty = null;
	} else {
		if ($action == "") {
			// Opened from the third party card: search automatically
			$action = 'autosearch';
		}
	}
}

/*
 * Actions
 */

// Note: never log names, VAT numbers, registration numbers or Peppol IDs, only the criterion and the number of results
$searchResults = null;
$searchCriterion = '';
if ($action == 'autosearch' && $thirdparty) {
	// Many participants (e.g. Dutch companies, scheme 0106 KvK) are registered with their registration number,
	// not with their VAT number: try VAT number, then registration number (professional ID 1), then name.
	foreach (peppolDirectorySearchCandidates($thirdparty) as $criterion => $query) {
		$res = Peppol::searchPeppolDirectory($query);
		$searchResults = $res;
		$search_query = $query;
		$searchCriterion = $criterion;
		$nb = (is_array($res) && isset($res['matches']) && is_array($res['matches'])) ? count($res['matches']) : 0;
		dol_syslog("Peppol directory search by " . $criterion . ": " . (isset($res['error']) ? 'error' : $nb . ' result(s)'));
		if (isset($res['error']) || $nb > 0) {
			break;
		}
	}
} elseif ($action == 'search' && $search_query !== '') {
	$searchResults = Peppol::searchPeppolDirectory($search_query);
	$nb = (is_array($searchResults) && isset($searchResults['matches']) && is_array($searchResults['matches'])) ? count($searchResults['matches']) : 0;
	dol_syslog("Peppol directory search by manual query: " . (isset($searchResults['error']) ? 'error' : $nb . ' result(s)'));
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
			dol_syslog("Peppol autodetect found a participant");
		}
	} else {
		setEventMessage($langs->trans('peppolAutoDetectNotAvailable'), 'warnings');
	}
}

if ($action == 'select') {
	// Changing the Peppol ID of a third party: needs write access to the module and to that third party, and a valid token
	if (!peppolCheckToken()) {
		accessforbidden($langs->trans('ErrorBadValueForParameter', 'token'));
	}
	if (empty($thirdparty) || !$user->hasRight('peppolpeppyrus', 'write') || !$user->hasRight('societe', 'creer')) {
		accessforbidden();
	}
	restrictedArea($user, 'societe', $socid, '&societe', '', 'fk_soc', 'rowid', 0);
	$participantid = GETPOST('participantid', 'alphanohtml');
	$peppol_scheme = GETPOST('peppol_scheme', 'alphanohtml');

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
	if ($thirdparty->insertExtraFields() < 0) {
		setEventMessages($thirdparty->error, $thirdparty->errors, 'errors');
	} else {
		setEventMessage($langs->trans('peppolIdSet'));
	}
	// Back to the card the search was started from, in the same window
	header('Location: ' . $backurl);
	exit;
}

/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("PeppolSearch");
$help_url = '';
llxHeader('', $title, $help_url);

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="search">';
if ($socid > 0) {
	print '<input type="hidden" name="socid" value="' . ((int) $socid) . '">';
	print '<input type="hidden" name="backto" value="' . dol_escape_htmltag($backto) . '">';
}

// Show title with thirdparty name if available
if ($thirdparty && $thirdparty->id > 0) {
	// Button back to the card the search was started from
	$linkback = '<a class="butAction" href="' . dol_escape_htmltag($backurl) . '">' . $langs->trans("peppolBackToThirdparty_" . $backto) . '</a>';
	print load_fiche_titre($langs->trans("peppolFindPeppolIDPopupTitle") . ' - ' . dol_escape_htmltag($thirdparty->name), $linkback, 'peppol@peppolpeppyrus');
} else {
	print load_fiche_titre($title, '', 'peppol@peppolpeppyrus');
}
// Search form, also from the third party card, so another name or number can be tried
print '<table class="border centpercent">';
print '<tr><td class="fieldrequired titlefield">' . $langs->trans("SearchQuery") . '</td>';
print '<td>';
print '<input type="text" name="search_query" size="50" value="' . dol_escape_htmltag($search_query) . '" placeholder="' . dol_escape_htmltag($langs->trans("peppolSearchQueryPlaceholder")) . '">';
if ($searchCriterion !== '') {
	print ' <span class="opacitymedium">' . $langs->trans("peppolSearchedBy", $langs->transnoentitiesnoconv("peppolSearchCriterion_" . $searchCriterion)) . '</span>';
}
print '</td></tr>';
print '</table>';
print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button" value="' . $langs->trans("Search") . '">';
print '</div>';

print '</form>';

// Display auto-detected result
if (!empty($autoDetectedId)) {
	print '<br>';
	print '<div class="info">';
	print '<strong>' . $langs->trans("peppolBestMatchSuccess") . ':</strong> ' . dol_escape_htmltag($autoDetectedId);
	print '<br><br>';
	print '<a class="button" href="' . $_SERVER["PHP_SELF"] . '?socid=' . ((int) $socid) . '&backto=' . $backto . '&action=select&participantid=' . urlencode($autoDetectedId) . '&token=' . newToken() . '">';
	print $langs->trans("PeppolApplyPeppolID");
	print '</a>';
	print '</div>';
}

// Display search results
if (!empty($searchResults)) {
	if (isset($searchResults['error'])) {
		print '<div class="error">' . dol_escape_htmltag($searchResults['error']) . '</div>';
	} elseif (isset($searchResults['matches']) && is_array($searchResults['matches'])) {
		$infos = array();
		foreach ($searchResults['matches'] as $match) {
			$info = peppolDirectoryMatchInfo($match, $thirdparty);
			if ($info['participantId'] !== '') {
				$infos[] = $info;
			}
		}

		if (count($infos) > 0) {
			// Recommended: matches the data of the third party and can receive invoices (or unknown)
			$recommended = -1;
			foreach ($infos as $i => $info) {
				if ($info['matchedField'] !== '' && $info['acceptsInvoice'] !== false) {
					$recommended = $i;
					break;
				}
			}

			// Several IDs of the same organisation (same name and country): say that each of them works
			$names = array();
			foreach ($infos as $info) {
				$names[strtoupper($info['name']) . '|' . $info['country']] = true;
			}
			if (count($infos) > 1 && count($names) == 1) {
				print '<div class="info" style="margin-top: 15px;">' . $langs->trans("peppolSameOrganisation", count($infos)) . '</div>';
			}

			print '<br>';
			print '<div class="div-table-responsive">';
			print '<table class="tagtable nobottomiftotal liste">';
			print '<tr class="liste_titre">';
			print '<th>' . $langs->trans("peppolParticipantID") . '</th>';
			print '<th>' . $langs->trans("peppolName") . '</th>';
			print '<th>' . $langs->trans("peppolLocation") . '</th>';
			print '<th class="center">' . $langs->trans("peppolAcceptsInvoice") . '</th>';
			print '<th>' . $langs->trans("peppolMatchThirdparty") . '</th>';
			if ($socid > 0) {
				print '<th></th>';
			}
			print '</tr>';

			foreach ($infos as $i => $info) {
				print '<tr class="oddeven">';

				// Peppol ID with the kind of number
				print '<td><strong>' . dol_escape_htmltag($info['participantId']) . '</strong>';
				$schemelabel = peppolSchemeLabel($info['icd']);
				if ($schemelabel !== '') {
					print '<br><span class="opacitymedium small">' . dol_escape_htmltag($schemelabel) . '</span>';
				}
				if ($i === $recommended) {
					print '<br><span class="badge badge-status4">' . $langs->trans("peppolRecommended") . '</span>';
				}
				print '</td>';

				// Name, extra information from the directory
				print '<td>' . dol_escape_htmltag($info['name']);
				if ($info['additionalInfo'] !== '') {
					print '<br><span class="opacitymedium small">' . dol_escape_htmltag(dol_trunc($info['additionalInfo'], 120)) . '</span>';
				}
				if ($info['regDate'] !== '') {
					print '<br><span class="opacitymedium small">' . $langs->trans("peppolRegisteredSince", dol_escape_htmltag($info['regDate'])) . '</span>';
				}
				print '</td>';

				// Country and location
				print '<td>' . dol_escape_htmltag($info['country']);
				if ($info['location'] !== '') {
					print '<br><span class="opacitymedium small">' . dol_escape_htmltag(dol_trunc($info['location'], 80)) . '</span>';
				}
				print '</td>';

				// Can receive invoices
				print '<td class="center">';
				if ($info['acceptsInvoice'] === true) {
					print '<span class="badge badge-status4">' . $langs->trans("Yes") . '</span>';
				} elseif ($info['acceptsInvoice'] === false) {
					print '<span class="badge badge-status8">' . $langs->trans("No") . '</span>';
				} else {
					print '<span class="opacitymedium">' . $langs->trans("peppolUnknown") . '</span>';
				}
				print '</td>';

				// Match with the data of the third party in Dolibarr
				print '<td>';
				if ($info['matchedField'] !== '') {
					$fieldlabel = ($info['matchedField'] == 'tva_intra') ? $langs->trans("VATIntra") : $langs->transcountry(str_replace('idprof', 'ProfId', $info['matchedField']), (is_object($thirdparty) ? $thirdparty->country_code : ''));
					print '<span class="badge badge-status4">' . $langs->trans("peppolMatchesField", dol_escape_htmltag($fieldlabel)) . '</span>';
				} else {
					print '<span class="opacitymedium">-</span>';
				}
				print '</td>';

				if ($socid > 0) {
					print '<td class="right">';
					print '<a class="button smallpaddingimp" href="' . $_SERVER["PHP_SELF"] . '?socid=' . ((int) $socid) . '&backto=' . $backto . '&action=select&peppol_scheme=' . urlencode($info['scheme']) . '&participantid=' . urlencode($info['participantId']) . '&token=' . newToken() . '">';
					print $langs->trans("PeppolApplyPeppolID");
					print '</a>';
					print '</td>';
				}
				print '</tr>';
			}
			print '</table>';
			print '</div>';
			if ($recommended < 0 && is_object($thirdparty)) {
				print '<div class="opacitymedium" style="margin-top: 8px;">' . $langs->trans("peppolNoRecommendation") . '</div>';
			}
		} else {
			print '<div class="warning" style="margin-top: 20px;">' . $langs->trans("peppolDirectoryNotFound") . '</div>';
		}
	} else {
		print '<div class="warning">' . $langs->trans("InvalidResponse") . '</div>';
	}
}
// End of page
llxFooter();
$db->close();
