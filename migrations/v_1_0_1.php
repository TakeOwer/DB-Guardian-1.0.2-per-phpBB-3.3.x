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
 * 1.0.1: the guardian no longer reads the superglobals after phpBB has started.
 */
class v_1_0_1 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['dbguardian_version']) && version_compare($this->config['dbguardian_version'], '1.0.1', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\dbguardian\migrations\v_1_0_0'];
	}

	public function update_data()
	{
		return [
			['config.update', ['dbguardian_version', '1.0.1']],
		];
	}
}
