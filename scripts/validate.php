<?php
$matches  = preg_grep('/Restler\/AutoLoader.php/i', get_included_files());
dol_syslog("Peppol matches test for restler is (1) " . json_encode($matches));
if (count($matches) == 0) {
	require_once __DIR__ . '/../vendor/scoper-autoload.php';
} else {
	require_once __DIR__ . '/../vendor/composer/autoload_static.php';
}


use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Readers\UblReader;
use Acme\Invoicing\Presets\CustomPreset;

$reader = new UblReader();
$document = file_get_contents(__DIR__ . "/FA2201-0064_peppol.xml");
try {
	$inv = $reader->import($document);
	try {
		$inv->validate();
		echo "ok\n";
	} catch (ValidationException $e) {
		// The invoice is not valid (see exception for more details)
		echo "ko 1\n";
	}
} catch (\InvalidArgumentException $e) {
	echo "ko 2\n";
}

echo "The End !\n";
