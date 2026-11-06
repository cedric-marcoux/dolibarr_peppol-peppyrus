<?php
/* Copyright (C) 2026 Cedric MARCOUX
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    peppolpeppyrus/admin/update.php
 * \ingroup peppolpeppyrus
 * \brief   Check and install a new version of the module from signed GitHub releases (#22)
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
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
if (!$res && file_exists("../../main.inc.php")) $res = include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $langs, $user, $conf, $db;

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/peppolpeppyrus/lib/peppol.lib.php');
dol_include_once('/peppolpeppyrus/lib/peppolupdate.lib.php');
dol_include_once('/peppolpeppyrus/core/modules/modPeppolpeppyrus.class.php');

$langs->loadLangs(array('admin', 'peppol@peppolpeppyrus'));

// Access control: administrators only (superadministrator when multicompany is used, the code is shared)
if (empty($user->admin) || !empty($user->socid) || (isModEnabled('multicompany') && !empty($user->entity))) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$moduleDir = dirname(__DIR__);
$workDir = DOL_DATA_ROOT . '/peppolpeppyrus_update';
$mod = new modPeppolpeppyrus($db);
$installed = $mod->version;
$state = peppolUpdateLoadState($workDir);
$includeBeta = !empty($state['beta']);
$refusal = peppolUpdateInstallRefusal($user, $moduleDir, DOL_DATA_ROOT);
if ($refusal === '' && !peppolDisclaimerAcceptance()) {
	$refusal = 'PeppolDisclaimerNotAccepted';
}

/**
 * Check GitHub and store the result in the private state file.
 *
 * @param string $workDir     Private work folder
 * @param bool   $includeBeta Channel
 * @return array              Cache content
 */
function peppolUpdateRefreshCache($workDir, $includeBeta)
{
	$state = peppolUpdateLoadState($workDir);
	$error = '';
	$issued = 0;
	$release = peppolUpdateFetchLatest($includeBeta, $error, $state['manifest_issued'], $issued);
	$cache = array('time' => dol_now(), 'beta' => $includeBeta ? 1 : 0, 'release' => $release, 'error' => $error);
	$saved = peppolUpdateChangeState($workDir, function ($st) use ($issued, $includeBeta, $cache) {
		$st['manifest_issued'] = (int) $issued;
		$st['beta'] = $includeBeta ? 1 : 0;
		$st['cache'] = $cache;
		return $st;
	});
	$cache['not_saved'] = !$saved;
	return $cache;
}

$tokenOk = peppolTokenIsValid(GETPOST('token', 'alpha'), currentToken());

/*
 * Actions
 */
if (in_array($action, array('setchannel', 'check', 'confirm_install', 'finalize'), true) && !$tokenOk) {
	accessforbidden();
}

if ($action == 'setchannel') {
	$includeBeta = GETPOST('PEPPOL_UPDATE_BETA', 'int') ? true : false;
	peppolUpdateRefreshCache($workDir, $includeBeta);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'check') {
	$cache = peppolUpdateRefreshCache($workDir, $includeBeta);
	if (!empty($cache['error'])) {
		setEventMessages($langs->trans('PeppolUpdateCheckFailed'), null, 'errors');
	}
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'confirm_install' && $confirm == 'yes') {
	if ($refusal !== '') {
		setEventMessages($langs->trans($refusal), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}
	// Do not stop half way (time limit, browser closed): the finally blocks must run
	@set_time_limit(0);
	ignore_user_abort(true);
	$error = '';
	// Never trust the cached data for the installation: ask GitHub again
	$issued = 0;
	$release = peppolUpdateFetchLatest($includeBeta, $error, $state['manifest_issued'], $issued);
	peppolUpdateChangeState($workDir, function ($st) use ($issued) {
		$st['manifest_issued'] = (int) $issued;
		return $st;
	});
	$wantedTag = GETPOST('tag', 'alphanohtml');
	if (!$release || $release['tag'] !== $wantedTag) {
		setEventMessages($langs->trans('PeppolUpdateReleaseChanged'), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}
	$ok = peppolUpdateInstall($release, $installed, $moduleDir, $workDir, $error);
	dol_syslog('peppolpeppyrus update ' . $installed . ' -> ' . $release['version'] . ' by user ' . $user->id . ': ' . ($ok ? 'OK' : 'KO') . ($error ? ' (' . $error . ')' : ''), $ok ? LOG_NOTICE : LOG_ERR);
	if (!$ok) {
		setEventMessages($langs->trans('PeppolUpdateFailed', dol_escape_htmltag($error)), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}
	if ($error !== '') {
		setEventMessages(dol_escape_htmltag($error), null, 'warnings');
	}
	peppolUpdateChangeState($workDir, function ($st) {
		$st['cache'] = null;
		return $st;
	});
	// The new code is on disk: run its initialisation in a new request
	header('Location: ' . $_SERVER['PHP_SELF'] . '?action=finalize&token=' . newToken());
	exit;
}

if ($action == 'finalize') {
	// init() runs the SQL updates of the new version and keeps the rights granted to users
	$result = $mod->init();
	if ($result > 0) {
		setEventMessages($langs->trans('PeppolUpdateDone', dol_escape_htmltag($installed)), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('PeppolUpdateInitFailed'), $mod->errors, 'errors');
	}
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */
$form = new Form($db);
$page_name = 'PeppolSetup';
llxHeader('', $langs->trans($page_name), '');

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');
$head = peppolAdminPrepareHead();
print dol_get_fiche_head($head, 'update', $langs->trans($page_name), -1, 'peppol@peppolpeppyrus');

// Folders left by an interrupted update must not stay reachable from the web
// (only when no update is running: the lock is the one taken by the installation)
if (is_dir($workDir)) {
	$lock = @fopen($workDir . '/update.lock', 'c');
	if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
		peppolUpdateCleanLeftovers(dirname($moduleDir));
		flock($lock, LOCK_UN);
	}
	if ($lock) {
		fclose($lock);
	}
}

$cache = (is_array($state['cache']) && isset($state['cache']['time'])) ? $state['cache'] : null;
if ($cache === null || (int) $cache['time'] > dol_now() || ((int) $cache['time'] + PEPPOL_UPDATE_CACHE_SECONDS) < dol_now() || (int) $cache['beta'] !== ($includeBeta ? 1 : 0)) {
	$cache = peppolUpdateRefreshCache($workDir, $includeBeta);
}
$release = (isset($cache['release']) && is_array($cache['release'])) ? $cache['release'] : null;
$available = $release && peppolUpdateIsNewer((string) $release['version'], $installed);

if ($action == 'install' && $available) {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'] . '?tag=' . urlencode($release['tag']),
		$langs->trans('PeppolUpdateInstall'),
		$langs->trans('PeppolUpdateConfirm', dol_escape_htmltag($installed), dol_escape_htmltag($release['version'])),
		'confirm_install',
		'',
		0,
		1
	);
}

// Pending initialisation (files replaced by hand or by an interrupted update)
$pendingInit = getDolGlobalString('PEPPOL_MODULE_VERSION') !== '' && getDolGlobalString('PEPPOL_MODULE_VERSION') !== $installed;

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('PeppolUpdateInstalled') . '</td><td><strong>' . dol_escape_htmltag($installed) . '</strong></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('PeppolUpdateChannel') . '<br><span class="opacitymedium small">' . $langs->trans('PeppolUpdateChannelHelp') . '</span></td><td>';
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="setchannel">';
print '<label><input type="checkbox" name="PEPPOL_UPDATE_BETA" value="1"' . ($includeBeta ? ' checked' : '') . '> ' . $langs->trans('PeppolUpdateIncludeBeta') . '</label> ';
print '<input type="submit" class="button smallpaddingimp" value="' . $langs->trans('Save') . '">';
print '</form></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('PeppolUpdateLatest') . '</td><td>';
if (!empty($cache['error'])) {
	print '<span class="error">' . $langs->trans('PeppolUpdateCheckFailed') . '</span> <span class="opacitymedium">(' . dol_escape_htmltag($cache['error']) . ')</span>';
} elseif ($release) {
	print '<strong>' . dol_escape_htmltag($release['version']) . '</strong>';
	if (!empty($release['prerelease'])) {
		print ' <span class="badge badge-warning">beta</span>';
	}
	if (peppolUpdateUrlAllowed($release['html_url'])) {
		print ' &nbsp; <a href="' . dol_escape_htmltag($release['html_url']) . '" target="_blank" rel="noopener noreferrer">' . $langs->trans('PeppolUpdateReleaseNotes') . '</a>';
	}
	print '<br>' . ($available ? '<span class="ok">' . $langs->trans('PeppolUpdateAvailable') . '</span>' : '<span class="opacitymedium">' . $langs->trans('PeppolUpdateUpToDate') . '</span>');
} else {
	print '<span class="opacitymedium">' . $langs->trans('PeppolUpdateNoSignedRelease') . '</span>';
}
print '<br><span class="opacitymedium small">' . $langs->trans('PeppolUpdateLastCheck') . ' ' . dol_print_date((int) $cache['time'], 'dayhour', 'tzuser') . '</span>';
print '</td></tr>';
print '</table>';

if (!empty($cache['not_saved'])) {
	print '<br>' . info_admin($langs->trans('PeppolUpdateStateNotSaved', dol_escape_htmltag($workDir)), 0, 0, 'error');
}
if ($refusal !== '' && $available) {
	print '<br>' . info_admin($langs->trans($refusal), 0, 0, 'warning');
}
if ($pendingInit) {
	print '<br>' . info_admin($langs->trans('PeppolUpdatePendingInit'), 0, 0, 'warning');
}

print '<div class="tabsAction">';
print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=check&token=' . newToken() . '">' . $langs->trans('PeppolUpdateCheckNow') . '</a>';
if ($available && $refusal === '') {
	print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=install&token=' . newToken() . '">' . $langs->trans('PeppolUpdateInstall') . ' ' . dol_escape_htmltag($release['version']) . '</a>';
} elseif ($available) {
	print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans($refusal)) . '">' . $langs->trans('PeppolUpdateInstall') . '</span>';
}
if ($pendingInit) {
	print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=finalize&token=' . newToken() . '">' . $langs->trans('PeppolUpdateFinish') . '</a>';
}
print '</div>';

print '<p class="opacitymedium small">' . $langs->trans('PeppolUpdateSecurityNote', dol_escape_htmltag($workDir)) . '</p>';

print dol_get_fiche_end();
llxFooter();
$db->close();
