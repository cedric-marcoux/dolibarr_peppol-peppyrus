<?php

/**
 * Is Dolibarr module enabled
 *
 * @param string $module module name to check
 * @return int
 */
if (!function_exists('isModEnabled')) {
	function isModEnabled($module)
	{
		global $conf;
		return !empty($conf->$module->enabled);
	}
}

function peppol_backport_getDolGlobalString($key, $default = '')
{
	if (function_exists('getDolGlobalString')) {
		if (((int) DOL_VERSION) < 15) {
			$res = getDolGlobalString($key);
			if (empty($res)) {
				$res = $default;
			}
			return $res;
		} else {
			/** @phpstan-ignore-next-line */
			return getDolGlobalString($key, $default);
		}
	}
	global $conf;
	// return $conf->global->$key ?? $default;
	return (string) (empty($conf->global->$key) ? $default : $conf->global->$key);
}
