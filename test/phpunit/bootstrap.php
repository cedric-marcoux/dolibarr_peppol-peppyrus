<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Bootstrap of the PHPUnit tests: loads the functions of Dolibarr without a database and without a
 * logged-in user, so rules, parsers and checks of the module can be tested on their own.
 *
 * DOLIBARR_HTDOCS points to the htdocs directory of a Dolibarr checkout (the workflow does this for
 * Dolibarr 18.0 and 24.0). The module is linked into htdocs/custom/peppolpeppyrus, as on a server.
 * Tests use made-up data only: no names, VAT numbers or documents of real customers or suppliers.
 *
 * Run from the root:   DOLIBARR_HTDOCS=/path/to/dolibarr/htdocs phpunit
 */

$htdocs = rtrim((string) getenv('DOLIBARR_HTDOCS'), '/');
if ($htdocs === '' || !is_file($htdocs.'/core/lib/functions.lib.php')) {
	fwrite(STDERR, "Set DOLIBARR_HTDOCS to the htdocs directory of Dolibarr (for example DOLIBARR_HTDOCS=../dolibarr/htdocs phpunit).\n");
	exit(1);
}
$root = dirname(__DIR__, 2);

// The module in htdocs/custom, as on the server
if (!is_dir($htdocs.'/custom')) {
	mkdir($htdocs.'/custom', 0755, true);
}
$target = $htdocs.'/custom/peppolpeppyrus';
if (!file_exists($target) && !is_link($target)) {
	symlink($root, $target);
}

define('DOL_DOCUMENT_ROOT', $htdocs);
define('DOL_URL_ROOT', '');
define('DOL_DATA_ROOT', sys_get_temp_dir().'/peppolpeppyrus-test-documents');
define('MAIN_DB_PREFIX', 'llx_');

require_once $htdocs.'/core/class/conf.class.php';
require_once $htdocs.'/core/lib/functions.lib.php';
require_once $htdocs.'/core/class/translate.class.php';

global $conf, $langs, $db, $user, $mysoc;
$conf = new Conf();
$conf->entity = 1;
$conf->file->dol_document_root = array('main' => $htdocs, 'alt0' => $htdocs.'/custom');
$conf->file->dol_url_root = array('main' => '', 'alt0' => '/custom');
$langs = new Translate('', $conf);
$langs->setDefaultLang('en_US');
$db = null;
$user = null;
$mysoc = null;

require_once $root.'/lib/peppol.lib.php';
