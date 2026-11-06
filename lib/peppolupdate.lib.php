<?php
/* Copyright (C) 2026 Cedric MARCOUX
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    peppolpeppyrus/lib/peppolupdate.lib.php
 * \ingroup peppolpeppyrus
 * \brief   Update of the module from signed GitHub releases (#22)
 *
 * Trust model: the code of a release is accepted only if its ZIP carries a valid Ed25519
 * signature made with the private key of the maintainer. The public key is below; the signed
 * message binds the release tag and the SHA-256 of the ZIP. GitHub is only a transport.
 */

if (!defined('PEPPOL_UPDATE_REPO')) {
	define('PEPPOL_UPDATE_REPO', 'cedric-marcoux/dolibarr_peppol-peppyrus');
}
if (!defined('PEPPOL_UPDATE_PUBLIC_KEY')) {
	define('PEPPOL_UPDATE_PUBLIC_KEY', 'uSa69Jcu0t6geujcjSmWjVZznfg8lzG53f5VW6VhNt0=');
}
define('PEPPOL_UPDATE_MODULE', 'peppolpeppyrus');
define('PEPPOL_UPDATE_MAX_ZIP_BYTES', 50 * 1024 * 1024);
define('PEPPOL_UPDATE_MAX_SIG_BYTES', 1024);
define('PEPPOL_UPDATE_MAX_API_BYTES', 2 * 1024 * 1024);
define('PEPPOL_UPDATE_MAX_FILES', 5000);
define('PEPPOL_UPDATE_MAX_UNCOMPRESSED_BYTES', 200 * 1024 * 1024);
define('PEPPOL_UPDATE_CACHE_SECONDS', 86400);
define('PEPPOL_UPDATE_ERROR_CACHE_SECONDS', 900);
define('PEPPOL_UPDATE_KEEP_BACKUPS', 3);
define('PEPPOL_UPDATE_MAX_MANIFEST_BYTES', 4096);
define('PEPPOL_UPDATE_MANIFEST_MAX_VALIDITY', 8 * 86400);
define('PEPPOL_UPDATE_CHECK_BUDGET', 40);

/**
 * Read the updater state (channel, cache, newest manifest seen).
 *
 * Kept in a private file of the data directory rather than in Dolibarr constants: no Dolibarr
 * screen can write it, so an administrator of another entity cannot change it (multicompany).
 *
 * @param string $workDir Private work folder (DOL_DATA_ROOT/peppolpeppyrus_update)
 * @return array          [beta => 0|1, cache => array|null, manifest_issued => int]
 */
function peppolUpdateLoadState($workDir)
{
	$state = array('beta' => 0, 'cache' => null, 'manifest_issued' => 0);
	$file = $workDir . '/state.json';
	if (is_file($file) && !is_link($file)) {
		$data = json_decode((string) @file_get_contents($file), true);
		if (is_array($data)) {
			$state['beta'] = empty($data['beta']) ? 0 : 1;
			$state['cache'] = (isset($data['cache']) && is_array($data['cache'])) ? $data['cache'] : null;
			$state['manifest_issued'] = (int) ($data['manifest_issued'] ?? 0);
		}
	}
	return $state;
}

/**
 * Change the updater state under a lock; the newest manifest seen never goes back.
 *
 * @param string   $workDir Private work folder
 * @param callable $change  function (array $state): array
 * @return bool             False when the state could not be saved
 */
function peppolUpdateChangeState($workDir, $change)
{
	if (!is_dir($workDir) && !@mkdir($workDir, 0700, true)) {
		return false;
	}
	$lock = @fopen($workDir . '/state.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX)) {
		return false;
	}
	try {
		$old = peppolUpdateLoadState($workDir);
		$new = $change($old);
		$new['manifest_issued'] = max((int) $old['manifest_issued'], (int) ($new['manifest_issued'] ?? 0));
		return peppolUpdateSaveState($workDir, $new);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/**
 * Write the updater state atomically (file readable by the web server user only).
 *
 * @param string $workDir Private work folder
 * @param array  $state   State
 * @return bool
 */
function peppolUpdateSaveState($workDir, $state)
{
	if (!is_dir($workDir) && !@mkdir($workDir, 0700, true)) {
		return false;
	}
	$tmp = $workDir . '/state-' . bin2hex(random_bytes(8)) . '.tmp';
	$fh = @fopen($tmp, 'xb');
	if (!$fh) {
		return false;
	}
	$json = json_encode(array('beta' => empty($state['beta']) ? 0 : 1, 'cache' => $state['cache'] ?? null, 'manifest_issued' => (int) ($state['manifest_issued'] ?? 0)));
	$ok = fwrite($fh, $json) === strlen($json);
	$ok = fclose($fh) && $ok;
	@chmod($tmp, 0600);
	if (!$ok || !@rename($tmp, $workDir . '/state.json')) {
		@unlink($tmp);
		return false;
	}
	return true;
}

/**
 * Tell whether a URL may be fetched by the updater: HTTPS on a GitHub host, nothing else.
 *
 * @param string $url URL
 * @return bool
 */
function peppolUpdateUrlAllowed($url)
{
	if (!is_string($url) || $url === '' || preg_match('/[\s\\\\]/', $url)) {
		return false;
	}
	$p = parse_url($url);
	if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
		return false;
	}
	if (strtolower($p['scheme']) !== 'https' || isset($p['user']) || isset($p['pass'])) {
		return false;
	}
	if (isset($p['port']) && (int) $p['port'] !== 443) {
		return false;
	}
	$allowed = array('api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com');
	return in_array(strtolower($p['host']), $allowed, true);
}

/**
 * HTTPS GET with certificate verification, manual redirects restricted to GitHub hosts and a size cap.
 *
 * @param string $url      URL
 * @param int    $maxBytes Maximum size of the body
 * @param string $accept   Accept header
 * @param string $error    Error text (out)
 * @param int    $timeout  Total time allowed for one request, in seconds
 * @return string|false    Body, or false on error
 */
function peppolUpdateHttpGet($url, $maxBytes, $accept, &$error, $timeout = 15)
{
	$error = '';
	if (!function_exists('curl_init')) {
		$error = 'curl missing';
		return false;
	}
	$end = time() + max(1, (int) $timeout);
	for ($hop = 0; $hop < 6; $hop++) {
		$left = $end - time();
		if ($left <= 0) {
			$error = 'Timeout';
			return false;
		}
		if (!peppolUpdateUrlAllowed($url)) {
			$error = 'URL not allowed';
			return false;
		}
		$body = '';
		$tooBig = false;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, $left);
		curl_setopt($ch, CURLOPT_USERAGENT, 'peppolpeppyrus-updater');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: ' . $accept));
		if (defined('CURLOPT_PROTOCOLS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		}
		if (getDolGlobalString('MAIN_PROXY_USE')) {
			curl_setopt($ch, CURLOPT_PROXY, getDolGlobalString('MAIN_PROXY_HOST') . ':' . getDolGlobalString('MAIN_PROXY_PORT'));
			if (getDolGlobalString('MAIN_PROXY_USER')) {
				curl_setopt($ch, CURLOPT_PROXYUSERPWD, getDolGlobalString('MAIN_PROXY_USER') . ':' . getDolGlobalString('MAIN_PROXY_PASS'));
			}
		}
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body, &$tooBig, $maxBytes) {
			if (strlen($body) + strlen($chunk) > $maxBytes) {
				$tooBig = true;
				return -1;
			}
			$body .= $chunk;
			return strlen($chunk);
		});
		$location = '';
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$location) {
			if (stripos($header, 'Location:') === 0) {
				$location = trim(substr($header, 9));
			}
			return strlen($header);
		});
		$ok = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		if ($tooBig) {
			$error = 'Response too large';
			return false;
		}
		if ($ok === false) {
			$error = 'Download failed: ' . $curlError;
			return false;
		}
		if (in_array($code, array(301, 302, 303, 307, 308), true)) {
			if ($location === '') {
				$error = 'Redirect without location';
				return false;
			}
			$url = $location;
			continue;
		}
		if ($code !== 200) {
			$error = 'HTTP ' . $code;
			return false;
		}
		return $body;
	}
	$error = 'Too many redirects';
	return false;
}

/**
 * Version from a release tag (vX.Y.Z or vX.Y.Z-beta.N).
 *
 * @param string $tag Tag
 * @return string     Version, '' if the tag is not a release tag
 */
function peppolUpdateVersionFromTag($tag)
{
	if (is_string($tag) && preg_match('/^v(\d{1,4}\.\d{1,4}\.\d{1,4}(?:-beta\.\d{1,4})?)$/', $tag, $m)) {
		return $m[1];
	}
	return '';
}

/**
 * Tell whether a version is strictly newer than the installed one (2.3.0-beta.1 < 2.3.0).
 *
 * @param string $candidate Candidate version
 * @param string $installed Installed version
 * @return bool
 */
function peppolUpdateIsNewer($candidate, $installed)
{
	if ($candidate === '' || !preg_match('/^\d+\.\d+\.\d+(-beta\.\d+)?$/', $candidate)) {
		return false;
	}
	return version_compare($candidate, (string) $installed, '>');
}

/**
 * Choose the release to offer from the GitHub API list.
 *
 * Only releases with a well-formed tag, a module ZIP and its signature on this repository are kept.
 *
 * With a manifest (see peppolUpdateParseManifest), only the exact version it names for the
 * channel is returned: an older signed release offered while the newest one is hidden is refused.
 *
 * @param array      $releases    Decoded JSON of GET /repos/{repo}/releases
 * @param bool       $includeBeta Also consider pre-releases
 * @param array|null $manifest    Verified manifest
 * @return array|null             [version, tag, prerelease, zip_name, zip_url, sig_url, html_url] or null
 */
function peppolUpdatePickRelease($releases, $includeBeta, $manifest = null)
{
	$best = null;
	$byVersion = array();
	if (!is_array($releases)) {
		return null;
	}
	foreach ($releases as $rel) {
		if (!is_array($rel) || !empty($rel['draft']) || !isset($rel['tag_name'])) {
			continue;
		}
		$tag = (string) $rel['tag_name'];
		$version = peppolUpdateVersionFromTag($tag);
		if ($version === '') {
			continue;
		}
		$isBeta = (strpos($version, '-beta.') !== false);
		$pre = !empty($rel['prerelease']);
		if (($pre || $isBeta) && !$includeBeta) {
			continue;
		}
		$downloadPrefix = 'https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/' . $tag . '/';
		$zip = $sig = null;
		foreach ((isset($rel['assets']) && is_array($rel['assets'])) ? $rel['assets'] : array() as $asset) {
			$name = isset($asset['name']) ? (string) $asset['name'] : '';
			$url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
			if ($url !== $downloadPrefix . $name) {
				continue;
			}
			if (preg_match('/^module_peppolpeppyrus-\d+(\.\d+)*\.zip$/', $name)) {
				$zip = array('name' => $name, 'url' => $url);
			}
		}
		if ($zip) {
			foreach ($rel['assets'] as $asset) {
				if (isset($asset['name'], $asset['browser_download_url']) && $asset['name'] === $zip['name'] . '.sig'
					&& $asset['browser_download_url'] === $downloadPrefix . $zip['name'] . '.sig') {
					$sig = $asset['browser_download_url'];
				}
			}
		}
		if (!$zip || !$sig) {
			continue;
		}
		$htmlUrl = 'https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/tag/' . $tag;
		$candidate = array(
			'version' => $version,
			'tag' => $tag,
			'prerelease' => ($pre || $isBeta),
			'zip_name' => $zip['name'],
			'zip_url' => $zip['url'],
			'sig_url' => $sig,
			'html_url' => $htmlUrl,
		);
		$byVersion[$version] = $candidate;
		if ($best === null || version_compare($version, $best['version'], '>')) {
			$best = array(
				'version' => $version,
				'tag' => $tag,
				'prerelease' => ($pre || $isBeta),
				'zip_name' => $zip['name'],
				'zip_url' => $zip['url'],
				'sig_url' => $sig,
				'html_url' => $htmlUrl,
			);
		}
	}
	if ($manifest !== null) {
		$target = peppolUpdateManifestTarget($manifest, $includeBeta);
		if ($target === '' || !isset($byVersion[$target])) {
			return null;
		}
		$byVersion[$target]['sha256'] = ($target === $manifest['stable']) ? $manifest['stable_sha256'] : $manifest['beta_sha256'];
		return $byVersion[$target];
	}
	return $best;
}

/**
 * Version the manifest names for a channel.
 *
 * @param array $manifest    Verified manifest
 * @param bool  $includeBeta Beta channel
 * @return string            Version, '' if none
 */
function peppolUpdateManifestTarget($manifest, $includeBeta)
{
	$target = (string) $manifest['stable'];
	if ($includeBeta && $manifest['beta'] !== '' && ($target === '' || version_compare($manifest['beta'], $target, '>'))) {
		$target = (string) $manifest['beta'];
	}
	return $target;
}

/**
 * Message signed for an update manifest.
 *
 * @param string $json Manifest content, as published
 * @return string
 */
function peppolUpdateManifestMessage($json)
{
	return PEPPOL_UPDATE_MODULE . '-manifest|' . $json;
}

/**
 * Verify and decode an update manifest.
 *
 * The manifest names the latest stable and beta versions, is signed with the release key and
 * expires after a few days (it is signed again every week): a release hidden or replaced by an
 * older one on GitHub is detected instead of being installed.
 *
 * @param string $json         Content of update-manifest.json
 * @param string $signatureB64 Content of update-manifest.json.sig (base64)
 * @param int    $now          Current time
 * @param string $error        Error text (out)
 * @param string $publicKeyB64 Public key (base64)
 * @return array|null          [module, issued, expires, stable, beta] or null
 */
function peppolUpdateParseManifest($json, $signatureB64, $now, &$error, $publicKeyB64 = PEPPOL_UPDATE_PUBLIC_KEY)
{
	$error = 'Invalid update manifest';
	if (!is_string($json) || strlen($json) > PEPPOL_UPDATE_MAX_MANIFEST_BYTES || !function_exists('sodium_crypto_sign_verify_detached')) {
		return null;
	}
	$sig = base64_decode(trim((string) $signatureB64), true);
	$pk = base64_decode((string) $publicKeyB64, true);
	if ($sig === false || $pk === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
		return null;
	}
	try {
		if (!sodium_crypto_sign_verify_detached($sig, peppolUpdateManifestMessage($json), $pk)) {
			return null;
		}
	} catch (Throwable $e) {
		return null;
	}
	$m = json_decode($json, true);
	if (!is_array($m) || ($m['module'] ?? '') !== PEPPOL_UPDATE_MODULE || !is_int($m['issued'] ?? null) || !is_int($m['expires'] ?? null)
		|| !is_string($m['stable'] ?? null) || !is_string($m['beta'] ?? null)
		|| !is_string($m['stable_sha256'] ?? null) || !is_string($m['beta_sha256'] ?? null)) {
		return null;
	}
	foreach (array('stable' => '/^\d+\.\d+\.\d+$/', 'beta' => '/^\d+\.\d+\.\d+-beta\.\d+$/') as $k => $re) {
		if ($m[$k] !== '' && !preg_match($re, $m[$k])) {
			return null;
		}
		// Each version is pinned to the exact ZIP built and signed by the maintainer
		if (($m[$k] === '') !== ($m[$k . '_sha256'] === '') || ($m[$k] !== '' && !preg_match('/^[0-9a-f]{64}$/', $m[$k . '_sha256']))) {
			return null;
		}
	}
	if ($m['stable'] === '' && $m['beta'] === '') {
		return null;
	}
	if ($m['expires'] <= $m['issued'] || $m['expires'] - $m['issued'] > PEPPOL_UPDATE_MANIFEST_MAX_VALIDITY || $m['issued'] > $now + 3600) {
		return null;
	}
	if ($m['expires'] < $now) {
		$error = 'Update manifest expired';
		return null;
	}
	$error = '';
	return array('module' => $m['module'], 'issued' => $m['issued'], 'expires' => $m['expires'], 'stable' => $m['stable'], 'beta' => $m['beta'],
		'stable_sha256' => $m['stable_sha256'], 'beta_sha256' => $m['beta_sha256']);
}

/**
 * Manifest assets attached to the releases.
 *
 * @param array $releases Decoded JSON of GET /repos/{repo}/releases
 * @return array          List of [json_url, sig_url]
 */
function peppolUpdateManifestAssets($releases)
{
	$found = array();
	foreach (is_array($releases) ? $releases : array() as $rel) {
		if (!is_array($rel) || !empty($rel['draft']) || !isset($rel['tag_name']) || peppolUpdateVersionFromTag((string) $rel['tag_name']) === '') {
			continue;
		}
		$prefix = 'https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/' . $rel['tag_name'] . '/';
		$urls = array();
		foreach ((isset($rel['assets']) && is_array($rel['assets'])) ? $rel['assets'] : array() as $asset) {
			if (isset($asset['name'], $asset['browser_download_url']) && $asset['browser_download_url'] === $prefix . $asset['name']) {
				$urls[$asset['name']] = $asset['browser_download_url'];
			}
		}
		if (isset($urls['update-manifest.json'], $urls['update-manifest.json.sig'])) {
			$found[] = array($urls['update-manifest.json'], $urls['update-manifest.json.sig']);
		}
	}
	return $found;
}

/**
 * Message signed for a release.
 *
 * @param string $tag       Release tag
 * @param string $sha256Hex SHA-256 of the ZIP, lowercase hexadecimal
 * @return string
 */
function peppolUpdateSignedMessage($tag, $sha256Hex)
{
	return PEPPOL_UPDATE_MODULE . '|' . $tag . '|' . strtolower($sha256Hex);
}

/**
 * Verify the detached Ed25519 signature of a release ZIP.
 *
 * @param string $tag          Release tag
 * @param string $sha256Hex    SHA-256 of the downloaded ZIP
 * @param string $signatureB64 Content of the .sig asset (base64)
 * @param string $publicKeyB64 Public key (base64, 32 bytes)
 * @return bool
 */
function peppolUpdateVerifySignature($tag, $sha256Hex, $signatureB64, $publicKeyB64 = PEPPOL_UPDATE_PUBLIC_KEY)
{
	if (!function_exists('sodium_crypto_sign_verify_detached')) {
		return false;
	}
	if (peppolUpdateVersionFromTag($tag) === '' || !preg_match('/^[0-9a-f]{64}$/i', (string) $sha256Hex)) {
		return false;
	}
	$sig = base64_decode(trim((string) $signatureB64), true);
	$pk = base64_decode((string) $publicKeyB64, true);
	if ($sig === false || $pk === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
		return false;
	}
	try {
		return sodium_crypto_sign_verify_detached($sig, peppolUpdateSignedMessage($tag, $sha256Hex), $pk);
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * Check a release ZIP before extraction: layout, paths, links, sizes, declared version.
 *
 * @param string $zipPath Path of the ZIP
 * @param string $version Version expected in the module descriptor
 * @param string $error   Error text (out)
 * @return bool
 */
function peppolUpdateCheckZip($zipPath, $version, &$error)
{
	$error = '';
	if (!class_exists('ZipArchive')) {
		$error = 'zip extension missing';
		return false;
	}
	$zip = new ZipArchive();
	if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
		$error = 'Invalid ZIP';
		return false;
	}
	$root = PEPPOL_UPDATE_MODULE . '/';
	$descriptor = $root . 'core/modules/modPeppolpeppyrus.class.php';
	$total = 0;
	$hasDescriptor = false;
	if ($zip->numFiles < 1 || $zip->numFiles > PEPPOL_UPDATE_MAX_FILES) {
		$zip->close();
		$error = 'Unexpected number of files';
		return false;
	}
	$seen = array();
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$st = $zip->statIndex($i);
		$name = ($st && isset($st['name'])) ? $st['name'] : '';
		if (!peppolUpdateEntryNameValid($name)) {
			$zip->close();
			$error = 'Unsafe path in ZIP';
			return false;
		}
		$key = strtolower(rtrim($name, '/'));
		if (isset($seen[$key])) {
			$zip->close();
			$error = 'Duplicate entry in ZIP';
			return false;
		}
		$seen[$key] = true;
		$opsys = $attr = 0;
		if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
			$type = ($attr >> 16) & 0170000;
			if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
				$zip->close();
				$error = 'Link or special file in ZIP';
				return false;
			}
		}
		$total += (int) $st['size'];
		if ($total > PEPPOL_UPDATE_MAX_UNCOMPRESSED_BYTES) {
			$zip->close();
			$error = 'ZIP content too large';
			return false;
		}
		if ($name === $descriptor) {
			$hasDescriptor = true;
		}
	}
	$src = $hasDescriptor ? $zip->getFromName($descriptor) : false;
	$zip->close();
	if ($src === false) {
		$error = 'Module descriptor missing';
		return false;
	}
	if (!preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', $src, $m) || $m[1] !== $version) {
		$error = 'Version in ZIP does not match the release';
		return false;
	}
	return true;
}

/**
 * Tell whether a ZIP entry name is a safe relative path under the module root.
 *
 * @param string $name Entry name
 * @return bool
 */
function peppolUpdateEntryNameValid($name)
{
	$root = PEPPOL_UPDATE_MODULE . '/';
	if (!is_string($name) || $name === '' || strpos($name, $root) !== 0) {
		return false;
	}
	if (strpos($name, '\\') !== false || strpos($name, "\0") !== false || strpos($name, ':') !== false || preg_match('/[\x00-\x1f\x7f]/', $name)) {
		return false;
	}
	$parts = explode('/', rtrim($name, '/'));
	foreach ($parts as $k => $part) {
		if ($part === '' || $part === '.' || $part === '..' || strtolower($part) === '.git') {
			return false;
		}
	}
	return true;
}

/**
 * Extract a checked ZIP entry by entry into $destDir (which must not exist).
 *
 * @param string $zipPath Path of the ZIP (already checked by peppolUpdateCheckZip)
 * @param string $destDir Directory to create; the module ends up in $destDir/peppolpeppyrus
 * @param string $error   Error text (out)
 * @return bool
 */
function peppolUpdateExtract($zipPath, $destDir, &$error)
{
	$error = '';
	if (file_exists($destDir) || is_link($destDir) || !@mkdir($destDir, 0700)) {
		$error = 'Cannot create the staging folder';
		return false;
	}
	$zip = new ZipArchive();
	if ($zip->open($zipPath) !== true) {
		$error = 'Invalid ZIP';
		return false;
	}
	$fileMode = 0664;
	$total = 0;
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = $zip->getNameIndex($i);
		if (!peppolUpdateEntryNameValid($name)) {
			$zip->close();
			$error = 'Unsafe path in ZIP';
			return false;
		}
		$target = $destDir . '/' . rtrim($name, '/');
		$parent = (substr($name, -1) === '/') ? $target : dirname($target);
		if (!is_dir($parent) && !@mkdir($parent, 0775, true)) {
			$zip->close();
			$error = 'Cannot create a folder';
			return false;
		}
		if (substr($name, -1) === '/') {
			continue;
		}
		if (file_exists($target) || is_link($target)) {
			$zip->close();
			$error = 'Duplicate entry in ZIP';
			return false;
		}
		$in = $zip->getStream($name);
		$out = @fopen($target, 'xb');
		if (!$in || !$out) {
			$zip->close();
			$error = 'Cannot extract ' . basename($name);
			return false;
		}
		$written = 0;
		$expected = (int) $zip->statName($name)['size'];
		while (!feof($in)) {
			$chunk = fread($in, 65536);
			if ($chunk === false) {
				fclose($in);
				fclose($out);
				$zip->close();
				$error = 'Cannot extract ' . basename($name);
				return false;
			}
			$total += strlen($chunk);
			if ($total > PEPPOL_UPDATE_MAX_UNCOMPRESSED_BYTES) {
				fclose($in);
				fclose($out);
				$zip->close();
				$error = 'ZIP content too large';
				return false;
			}
			if (fwrite($out, $chunk) !== strlen($chunk)) {
				fclose($in);
				fclose($out);
				$zip->close();
				$error = 'Cannot write ' . basename($name) . ' (disk full?)';
				return false;
			}
			$written += strlen($chunk);
		}
		fclose($in);
		if (!fclose($out) || $written !== $expected) {
			$zip->close();
			$error = 'Incomplete extraction of ' . basename($name);
			return false;
		}
		@chmod($target, $fileMode);
	}
	$zip->close();
	@chmod($destDir, 0775);
	if (!is_file($destDir . '/' . PEPPOL_UPDATE_MODULE . '/core/modules/modPeppolpeppyrus.class.php')) {
		$error = 'Module descriptor missing';
		return false;
	}
	return true;
}

/**
 * Delete a directory tree without following symbolic links.
 *
 * @param string $dir Directory
 * @return bool
 */
function peppolUpdateDeleteTree($dir)
{
	if (is_link($dir) || is_file($dir)) {
		return @unlink($dir);
	}
	// A folder made unreadable after a failed deletion (peppolUpdateSwap) is readable again for us
	if (is_dir($dir) && !is_readable($dir)) {
		@chmod($dir, 0700);
	}
	if (!is_dir($dir)) {
		return true;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	$ok = true;
	foreach ($it as $f) {
		$path = $f->getPathname();
		if (is_link($path) || !$f->isDir()) {
			$ok = @unlink($path) && $ok;
		} else {
			$ok = @rmdir($path) && $ok;
		}
	}
	return @rmdir($dir) && $ok;
}

/**
 * Back up the installed module into a ZIP.
 *
 * @param string $moduleDir  Module folder
 * @param string $backupFile ZIP to create
 * @return bool
 */
function peppolUpdateBackup($moduleDir, $backupFile)
{
	if (!class_exists('ZipArchive') || file_exists($backupFile)) {
		return false;
	}
	$zip = new ZipArchive();
	if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
		return false;
	}
	$base = strlen(rtrim($moduleDir, '/')) + 1;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $f) {
		$path = $f->getPathname();
		if (is_link($path)) {
			continue;
		}
		$rel = PEPPOL_UPDATE_MODULE . '/' . substr($path, $base);
		if ($f->isDir()) {
			$zip->addEmptyDir($rel);
		} else {
			$zip->addFile($path, $rel);
		}
	}
	$ok = $zip->close();
	@chmod($backupFile, 0600);
	return $ok;
}

/**
 * Keep only the newest backups.
 *
 * @param string $workDir Private work folder
 * @param int    $keep    Number of backups kept
 * @return void
 */
function peppolUpdatePruneBackups($workDir, $keep)
{
	$files = array();
	foreach ((array) glob($workDir . '/backup-*.zip', GLOB_NOSORT) as $f) {
		if ($f && is_file($f) && !is_link($f)) {
			$files[$f] = filemtime($f);
		}
	}
	arsort($files);
	foreach (array_slice(array_keys($files), (int) $keep) as $f) {
		@unlink($f);
	}
}

/**
 * Replace the module folder by the new one, restore it on failure.
 *
 * Both folders must be on the same file system (they are siblings).
 *
 * @param string $newModuleDir New module folder (staging)
 * @param string $moduleDir    Installed module folder
 * @param string $error        Error text (out)
 * @return bool
 */
function peppolUpdateSwap($newModuleDir, $moduleDir, &$error)
{
	$error = '';
	$old = dirname($moduleDir) . '/.peppolpeppyrus-old-' . bin2hex(random_bytes(12));
	if (!is_dir($newModuleDir) || is_link($newModuleDir) || !is_dir($moduleDir) || is_link($moduleDir)) {
		$error = 'Folder missing';
		return false;
	}
	if (!@rename($moduleDir, $old)) {
		$error = 'Cannot move the installed module';
		return false;
	}
	if (!@rename($newModuleDir, $moduleDir)) {
		@rename($old, $moduleDir);
		$error = 'Cannot install the new module, previous version restored';
		return false;
	}
	// Servers without timestamp validation would keep running the previous compiled code
	if (function_exists('opcache_invalidate')) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if (substr($f->getFilename(), -4) === '.php') {
				@opcache_invalidate($f->getPathname(), true);
			}
		}
	}
	// The previous code must not stay reachable from the web
	if (!peppolUpdateDeleteTree($old)) {
		// What is left must not be served: make it unreadable, it is removed at the next update or
		// when the Updates tab is opened (peppolUpdateCleanLeftovers)
		@chmod($old, 0);
		$error = 'Previous version could not be fully removed';
	}
	return true;
}

/**
 * Reason why the current user may not install an update in this module folder.
 *
 * @param User   $user      User
 * @param string $moduleDir Installed module folder
 * @param string $dataRoot  Dolibarr data root (installmodules.lock)
 * @return string           '' when allowed, otherwise a translation key
 */
function peppolUpdateInstallRefusal($user, $moduleDir, $dataRoot)
{
	if (empty($user->admin) || !empty($user->socid)) {
		return 'NotEnoughPermissions';
	}
	if (isModEnabled('multicompany') && !empty($user->entity)) {
		return 'PeppolUpdateSuperAdminOnly';
	}
	if (is_file(rtrim($dataRoot, '/') . '/installmodules.lock')) {
		return 'PeppolUpdateLocked';
	}
	if (basename($moduleDir) !== PEPPOL_UPDATE_MODULE || is_link($moduleDir) || !is_dir($moduleDir)) {
		return 'PeppolUpdateFolderNotWritable';
	}
	if (file_exists($moduleDir . '/.git')) {
		return 'PeppolUpdateGitCheckout';
	}
	if (!class_exists('ZipArchive') || !function_exists('sodium_crypto_sign_verify_detached') || !function_exists('curl_init')) {
		return 'PeppolUpdateMissingExtension';
	}
	if (!is_writable($moduleDir) || !is_writable(dirname($moduleDir))) {
		return 'PeppolUpdateFolderNotWritable';
	}
	// Every folder must be writable, otherwise the previous version could not be removed
	// completely after the swap and would stay reachable from the web
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $f) {
		if ($f->isDir() && !$f->isLink() && !is_writable($f->getPathname())) {
			return 'PeppolUpdateFolderNotWritable';
		}
	}
	return '';
}

/**
 * Remove folders left next to the module by an interrupted update (staging or previous version).
 *
 * @param string $customDir Folder that contains the module folder
 * @return bool             True when nothing is left
 */
function peppolUpdateCleanLeftovers($customDir)
{
	$ok = true;
	foreach (array('.peppolpeppyrus-old-', '.peppolpeppyrus-new-') as $prefix) {
		foreach ((array) glob(rtrim($customDir, '/') . '/' . $prefix . '*', GLOB_NOSORT) as $path) {
			if ($path && preg_match('/^' . preg_quote($prefix, '/') . '[0-9a-f]{24}$/', basename($path))) {
				$ok = peppolUpdateDeleteTree($path) && $ok;
			}
		}
	}
	return $ok;
}

/**
 * Version declared by a module descriptor file.
 *
 * @param string $file Path of modPeppolpeppyrus.class.php
 * @return string      Version, '' if not found
 */
function peppolUpdateDescriptorVersion($file)
{
	$src = is_file($file) ? @file_get_contents($file) : false;
	if ($src !== false && preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', $src, $m)) {
		return $m[1];
	}
	return '';
}

/**
 * Ask GitHub for the newest release of the chosen channel.
 *
 * Only the version named by the newest valid signed manifest is returned.
 *
 * @param bool     $includeBeta    Include pre-releases
 * @param string   $error          Error text (out)
 * @param int      $minIssued      Issue time of the newest manifest already seen (replay protection)
 * @param int      $manifestIssued Issue time of the manifest used (out)
 * @param callable $fetch          Transport (tests)
 * @param int|null $now            Current time (tests)
 * @param string   $publicKey      Public key (tests)
 * @return array|null              See peppolUpdatePickRelease(), null when none or on error
 */
function peppolUpdateFetchLatest($includeBeta, &$error, $minIssued = 0, &$manifestIssued = 0, $fetch = 'peppolUpdateHttpGet', $now = null, $publicKey = PEPPOL_UPDATE_PUBLIC_KEY)
{
	$error = '';
	$now = ($now === null) ? time() : (int) $now;
	$deadline = time() + PEPPOL_UPDATE_CHECK_BUDGET;
	$body = $fetch('https://api.github.com/repos/' . PEPPOL_UPDATE_REPO . '/releases', PEPPOL_UPDATE_MAX_API_BYTES, 'application/vnd.github+json', $error);
	if ($body === false) {
		return null;
	}
	$list = json_decode($body, true);
	if (!is_array($list)) {
		$error = 'Invalid answer from GitHub';
		return null;
	}
	$assets = peppolUpdateManifestAssets($list);
	if (count($assets) > 1) {
		// The maintainer publishes exactly one manifest: copies are an attempt to hide the real one
		$error = 'Several update manifests found';
		return null;
	}
	if (!$assets) {
		// Every version with this updater is published with a manifest: its absence is an error
		// (removed or hidden on GitHub), never a silent "nothing new"
		$error = 'No update manifest found';
		return null;
	}
	$manifest = null;
	$lastError = 'Invalid update manifest';
	foreach ($assets as $pair) {
		$json = $fetch($pair[0], PEPPOL_UPDATE_MAX_MANIFEST_BYTES, 'application/octet-stream', $e, max(1, $deadline - time()));
		$sig = ($json === false) ? false : $fetch($pair[1], PEPPOL_UPDATE_MAX_SIG_BYTES, 'application/octet-stream', $e, max(1, $deadline - time()));
		$m = ($sig === false) ? null : peppolUpdateParseManifest($json, $sig, $now, $e, $publicKey);
		if ($m === null) {
			$lastError = $e ?: $lastError;
			continue;
		}
		if ($manifest === null || $m['issued'] > $manifest['issued']) {
			$manifest = $m;
		}
	}
	if ($manifest === null) {
		$error = $lastError;
		return null;
	}
	if ($manifest['issued'] < (int) $minIssued) {
		$error = 'Update manifest older than the last one seen';
		return null;
	}
	$manifestIssued = $manifest['issued'];
	$target = peppolUpdateManifestTarget($manifest, $includeBeta);
	$release = peppolUpdatePickRelease($list, $includeBeta, $manifest);
	if ($target !== '' && $release === null) {
		$error = 'The latest version (' . $target . ') is not available';
	}
	return $release;
}

/**
 * Download, verify and install a release. The module initialisation (SQL) is run afterwards,
 * in a new request, by the update page.
 *
 * @param array  $release   Release from peppolUpdatePickRelease()
 * @param string $installed Installed version
 * @param string $moduleDir Installed module folder
 * @param string $workDir   Private work folder in the data directory (backups)
 * @param string $error     Error text (out)
 * @param callable $fetch    Transport (tests)
 * @param string $publicKey Public key (tests)
 * @return bool
 */
function peppolUpdateInstall($release, $installed, $moduleDir, $workDir, &$error, $fetch = 'peppolUpdateHttpGet', $publicKey = PEPPOL_UPDATE_PUBLIC_KEY)
{
	$error = '';
	if (!is_array($release) || !peppolUpdateIsNewer($release['version'], $installed)) {
		$error = 'No newer version';
		return false;
	}
	foreach (array('zip_url', 'sig_url') as $k) {
		if (empty($release[$k]) || strpos($release[$k], 'https://github.com/' . PEPPOL_UPDATE_REPO . '/releases/download/' . $release['tag'] . '/') !== 0) {
			$error = 'URL not allowed';
			return false;
		}
	}
	// A release chosen by peppolUpdateFetchLatest() is the one named by the signed manifest
	$sig = $fetch($release['sig_url'], PEPPOL_UPDATE_MAX_SIG_BYTES, 'application/octet-stream', $error);
	if ($sig === false) {
		return false;
	}
	$data = $fetch($release['zip_url'], PEPPOL_UPDATE_MAX_ZIP_BYTES, 'application/octet-stream', $error, 180);
	if ($data === false) {
		return false;
	}
	if (empty($release['sha256']) || !hash_equals((string) $release['sha256'], hash('sha256', $data))) {
		$error = 'The ZIP is not the one named by the update manifest';
		return false;
	}
	return peppolUpdateInstallData($release['tag'], $data, $sig, $installed, $moduleDir, $workDir, $error, $publicKey);
}

/**
 * Verify and install a downloaded release ZIP.
 *
 * @param string $tag       Release tag
 * @param string $data      ZIP content
 * @param string $sig       Signature (base64)
 * @param string $installed Installed version
 * @param string $moduleDir Installed module folder
 * @param string $workDir   Private work folder in the data directory (backups)
 * @param string $error     Error text (out)
 * @param string $publicKey Public key (base64)
 * @return bool
 */
function peppolUpdateInstallData($tag, $data, $sig, $installed, $moduleDir, $workDir, &$error, $publicKey = PEPPOL_UPDATE_PUBLIC_KEY)
{
	$error = '';
	$version = peppolUpdateVersionFromTag($tag);
	if (!peppolUpdateIsNewer($version, $installed)) {
		$error = 'No newer version';
		return false;
	}
	if (!peppolUpdateVerifySignature($tag, hash('sha256', (string) $data), $sig, $publicKey)) {
		$error = 'Invalid signature, update refused';
		return false;
	}
	if (!is_dir($workDir) && !@mkdir($workDir, 0700, true)) {
		$error = 'Cannot create the work folder';
		return false;
	}
	$lock = @fopen($workDir . '/update.lock', 'c');
	if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
		$error = 'Another update is running';
		return false;
	}
	$zipFile = $workDir . '/download-' . bin2hex(random_bytes(12)) . '.zip';
	$staging = dirname($moduleDir) . '/.peppolpeppyrus-new-' . bin2hex(random_bytes(12));
	try {
		peppolUpdateCleanLeftovers(dirname($moduleDir));
		// Read again under the lock: another administrator may have updated the module meanwhile
		if (peppolUpdateDescriptorVersion($moduleDir . '/core/modules/modPeppolpeppyrus.class.php') !== (string) $installed) {
			$error = 'The installed version has changed, check again';
			return false;
		}
		$fh = @fopen($zipFile, 'xb');
		if (!$fh || fwrite($fh, $data) !== strlen($data)) {
			if ($fh) {
				fclose($fh);
			}
			$error = 'Cannot write the download';
			return false;
		}
		fclose($fh);
		@chmod($zipFile, 0600);
		// The file on disk must still be the signed one
		if (!peppolUpdateVerifySignature($tag, hash_file('sha256', $zipFile), $sig, $publicKey)) {
			$error = 'Invalid signature, update refused';
			return false;
		}
		if (!peppolUpdateCheckZip($zipFile, $version, $error)) {
			return false;
		}
		if (!peppolUpdateExtract($zipFile, $staging, $error)) {
			return false;
		}
		$backup = $workDir . '/backup-' . preg_replace('/[^0-9A-Za-z.\-]/', '', (string) $installed) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
		if (!peppolUpdateBackup($moduleDir, $backup)) {
			$error = 'Cannot back up the installed version';
			return false;
		}
		peppolUpdatePruneBackups($workDir, PEPPOL_UPDATE_KEEP_BACKUPS);
		return peppolUpdateSwap($staging . '/' . PEPPOL_UPDATE_MODULE, $moduleDir, $error);
	} finally {
		if (is_file($zipFile)) {
			@unlink($zipFile);
		}
		if (file_exists($staging) || is_link($staging)) {
			peppolUpdateDeleteTree($staging);
		}
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}
