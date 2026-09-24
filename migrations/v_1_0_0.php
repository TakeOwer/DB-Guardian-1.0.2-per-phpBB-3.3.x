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

class v_1_0_0 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['dbguardian_version']) && version_compare($this->config['dbguardian_version'], '1.0.0', '>=');
	}

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	public function update_data()
	{
		return [
			['config.add', ['dbguardian_version', '1.0.0']],

			['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_DBGUARDIAN_TITLE']],
			['module.add', ['acp', 'ACP_DBGUARDIAN_TITLE', [
				'module_basename' => '\salvocortesiano\dbguardian\acp\main_module',
				'modes'           => ['status', 'settings', 'log'],
			]]],
		];
	}
}
