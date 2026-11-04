<?php
/**
 * Version check: VERSION, $this->version of the module and the latest heading in CHANGELOG.md are the same,
 * and that version in the CHANGELOG has an "After updating" line. need_dolibarr_version is 18.0 (README,
 * "Standard and deliberate deviations").
 *
 * Usage (from the root):   php dev/tools/check-versions.php
 * Exit 0: OK. Exit 1: the differences are listed.
 */
$root = dirname(__DIR__, 2);
$errors = array();

$version = trim((string) @file_get_contents($root.'/VERSION'));
$descriptor = (string) @file_get_contents($root.'/core/modules/modPeppolpeppyrus.class.php');
$changelog = (string) @file_get_contents($root.'/CHANGELOG.md');

if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
	$errors[] = "VERSION does not contain a version number (found '$version')";
}
if (!preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', $descriptor, $m)) {
	$errors[] = 'No $this->version in core/modules/modPeppolpeppyrus.class.php';
} elseif ($m[1] !== $version) {
	$errors[] = "\$this->version ('{$m[1]}') is not VERSION ('$version')";
}
if (!preg_match('/^## (\S+)\s*$/m', $changelog, $m, PREG_OFFSET_CAPTURE)) {
	$errors[] = 'No version heading (## x.y.z) in CHANGELOG.md';
} else {
	if ($m[1][0] !== $version) {
		$errors[] = "Latest version in CHANGELOG.md ('{$m[1][0]}') is not VERSION ('$version')";
	}
	$rest = substr($changelog, $m[0][1] + strlen($m[0][0]));
	$section = preg_split('/^## /m', $rest)[0];
	if (stripos($section, 'After updating') === false) {
		$errors[] = "CHANGELOG.md, version {$m[1][0]}: no 'After updating' line";
	}
}
if (!preg_match('/need_dolibarr_version\s*=\s*array\(\s*18\s*,\s*0\s*\)/', $descriptor)) {
	$errors[] = 'need_dolibarr_version is not array(18, 0)';
}

if ($errors) {
	foreach ($errors as $e) {
		echo "ERROR: $e\n";
	}
	exit(1);
}
echo "Versions: OK ($version)\n";
