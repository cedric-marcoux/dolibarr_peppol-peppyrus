<?php

//convertisseur rapide du fichier json en CSV pour avoir les codes pays

$csvFile = "lib/PeppolCodeLists.csv";
$jsonFile = "lib/Peppol Code Lists - Participant identifier schemes v8.0.json";
$json = json_decode(file_get_contents($jsonFile));

// ==============================
foreach ($json->values as $bloc) {
	$scheme = $bloc->schemeid;
	$iso6523 = $bloc->iso6523;
	$country = $bloc->country;
	$csv .= "$scheme;$iso6523;$country\n";
}

// ==============================
if ($fp = fopen($csvFile, 'w')) {
	fwrite($fp, $csv);
	fclose($fp);
}
