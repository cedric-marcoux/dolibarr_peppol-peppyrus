<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Constants that main.inc.php of Dolibarr sets, so PHPStan can read the module without a running installation
 * (same approach as DoliCloud/DoliMods). DOLIBARR_HTDOCS points to the htdocs directory of a Dolibarr checkout.
 */
$htdocs = getenv('DOLIBARR_HTDOCS');
if (!is_string($htdocs) || $htdocs === '' || !is_file($htdocs.'/filefunc.inc.php')) {
	fwrite(STDERR, "DOLIBARR_HTDOCS does not point to the htdocs directory of a Dolibarr checkout.\n");
	exit(2);
}
$htdocs = rtrim(str_replace('\\', '/', $htdocs), '/');
define('DOL_DOCUMENT_ROOT', $htdocs);
define('DOL_DOCUMENT_ROOT_ALT', $htdocs.'/custom');
define('DOL_DATA_ROOT', dirname($htdocs).'/documents');
define('DOL_URL_ROOT', '');
define('DOL_MAIN_URL_ROOT', 'http://localhost');
define('MAIN_DB_PREFIX', 'llx_');
