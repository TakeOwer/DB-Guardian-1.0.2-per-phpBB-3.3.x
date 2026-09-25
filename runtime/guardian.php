<?php
/**
 *
 * DB Guardian. An extension for the phpBB Forum Software package.
 *
 * Bootstrap loaded through PHP's auto_prepend_file directive (.user.ini),
 * before phpBB itself. It must never break a request: every failure is silent.
 *
 * This file is copied by the extension into store/dbguardian/. Do not edit the
 * copy: it is overwritten whenever the settings are saved in the ACP.
 *
 * @copyright (c) 2026 Salvo Cortesiano - https://netshadows.de
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || defined('DBGUARDIAN_LOADED'))
{
	return;
}

define('DBGUARDIAN_LOADED', '1.0.4');

$dbguardian_chain = (function ()
{
	$dir = __DIR__;
	$cfg = null;

	try
	{
		if (is_file($dir . '/config.php'))
		{
			$cfg = @include $dir . '/config.php';
		}

		if (is_array($cfg) && !empty($cfg['enabled']) && is_file($dir . '/guardian_core.php'))
		{
			require_once $dir . '/guardian_core.php';

			if (class_exists('DbGuardianCore', false))
			{
				DbGuardianCore::boot($dir, $cfg);
			}
		}
	}
	catch (\Throwable $e)
	{
		// The guardian must stay invisible when it cannot run.
	}

	return (is_array($cfg) && !empty($cfg['chain_prepend']) && is_string($cfg['chain_prepend'])) ? $cfg['chain_prepend'] : '';
})();

// Keep any auto_prepend_file that was active before the guardian was installed.
if ($dbguardian_chain !== '' && is_file($dbguardian_chain))
{
	include_once $dbguardian_chain;
}
unset($dbguardian_chain);
