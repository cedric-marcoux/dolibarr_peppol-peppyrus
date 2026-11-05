<?php
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Is Dolibarr module enabled
 *
 * @param string $module module name to check
 * @return int
 */
if (!function_exists('isModEnabled')) {
	/**
	 * Backport of isModEnabled() for old Dolibarr versions
	 *
	 * @param string $module Module name
	 * @return bool true if the module is enabled
	 */
	function isModEnabled($module)
	{
		global $conf;
		return !empty($conf->$module->enabled);
	}
}

/**
 * Backport of getDolGlobalString() for old Dolibarr versions
 *
 * @param string $key Property name
 * @param string $default Value when the setting is empty
 * @return string Value of the setting
 */
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
