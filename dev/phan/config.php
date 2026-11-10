<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Phan only for SQL injection, with the plugin of Dolibarr itself (dev/tools/phan/plugins/SqlInjectionPlugin.php,
 * since Dolibarr 24). The plugin is read from the Dolibarr checkout, not copied into this repository. It follows a
 * value from where it comes from into $sql and reports values that are not escaped, cast or sanitised.
 * The rest of the code analysis is done by PHPStan (phpstan.neon.dist).
 *
 * Usage (from the root):
 *   DOLIBARR_HTDOCS=/path/to/dolibarr-24.0/htdocs php phan.phar --config-file dev/phan/config.php
 * Needs the PHP extension ast.
 * A report that is not a real problem gets on the line itself: // @phan-suppress-current-line SqlInjection -- <reason>
 */

$root = dirname(__DIR__, 2);
$htdocs = (string) getenv('DOLIBARR_HTDOCS');
if (!is_file($htdocs.'/filefunc.inc.php')) {
	fwrite(STDERR, "DOLIBARR_HTDOCS does not point to the htdocs directory of a Dolibarr checkout.\n");
	exit(1);
}
$plugin = dirname($htdocs).'/dev/tools/phan/plugins/SqlInjectionPlugin.php';
if (!is_file($plugin)) {
	fwrite(STDERR, "The SQL plugin of Phan is missing in this Dolibarr checkout (only since Dolibarr 24): $plugin\n");
	exit(1);
}

$module = array();
foreach (array('admin', 'class', 'core', 'lib') as $dir) {
	$module[] = $root.'/'.$dir;
}
$files = glob($root.'/*.php');

return array(
	'target_php_version' => '8.4',
	'minimum_target_php_version' => '7.1',
	'directory_list' => array_merge($module, array($htdocs)),
	'file_list' => $files,
	// Dolibarr itself is only read (to know its classes and functions), not checked
	'exclude_analysis_directory_list' => array($htdocs),
	'exclude_file_regex' => '@/(vendor|backport|test|dev)/@',
	'plugins' => array($plugin),
	'SqlInjectionPlugin' => array('debug' => false),
	// Only the results of the SQL plugin
	'whitelist_issue_types' => array('SqlInjection', 'SqlInjectionUnquotedEscape'),
	'backward_compatibility_checks' => false,
	'quick_mode' => false,
	'analyze_signature_compatibility' => false,
	'dead_code_detection' => false,
	'processes' => 1,
);
