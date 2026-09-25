<?php
/**
 *
 * DB Guardian. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano - https://netshadows.de
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\dbguardian\migrations;

/**
 * 1.0.3: "External monitoring" tab (status endpoint and watchdog for another server).
 */
class v_1_0_3 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['dbguardian_version']) && version_compare($this->config['dbguardian_version'], '1.0.3', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\dbguardian\migrations\v_1_0_2'];
	}

	public function update_data()
	{
		return [
			['module.add', ['acp', 'ACP_DBGUARDIAN_TITLE', [
				'module_basename' => '\salvocortesiano\dbguardian\acp\main_module',
				'modes'           => ['monitor'],
			]]],
			['config.update', ['dbguardian_version', '1.0.3']],
		];
	}
}
