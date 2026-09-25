<?php
/**
 *
 * DB Guardian. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano - https://netshadows.de
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_DBGUARDIAN_TITLE'    => 'DB Guardian',
	'ACP_DBGUARDIAN_STATUS'   => 'Stato e prove',
	'ACP_DBGUARDIAN_SETTINGS' => 'Impostazioni',
	'ACP_DBGUARDIAN_MONITOR'  => 'Monitoraggio esterno',
	'ACP_DBGUARDIAN_LOG'      => 'Registro eventi',
]);
