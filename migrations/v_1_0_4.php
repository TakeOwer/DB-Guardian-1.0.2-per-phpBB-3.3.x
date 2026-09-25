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
 * 1.0.4: translates the module names that earlier versions left untranslated in the admin log
 * ("Modulo aggiunto » ACP_DBGUARDIAN_MONITOR").
 */
class v_1_0_4 extends \phpbb\db\migration\container_aware_migration
{
	public function effectively_installed()
	{
		return isset($this->config['dbguardian_version']) && version_compare($this->config['dbguardian_version'], '1.0.4', '>=');
	}

	public static function depends_on()
	{
		return ['\salvocortesiano\dbguardian\migrations\v_1_0_3'];
	}

	public function update_data()
	{
		return [
			['custom', [[$this, 'translate_admin_log']]],
			['config.update', ['dbguardian_version', '1.0.4']],
		];
	}

	public function translate_admin_log()
	{
		/** @var \phpbb\language\language $language */
		$language = $this->container->get('language');
		$language->add_lang('info_acp_dbguardian', 'salvocortesiano/dbguardian');

		$sql = 'SELECT log_id, log_data
			FROM ' . $this->table_prefix . "log
			WHERE log_operation IN ('LOG_MODULE_ADD', 'LOG_MODULE_REMOVED', 'LOG_MODULE_EDIT', 'LOG_MODULE_ENABLE', 'LOG_MODULE_DISABLE')
				AND log_data " . $this->db->sql_like_expression($this->db->get_any_char() . 'ACP_DBGUARDIAN_' . $this->db->get_any_char());
		$result = $this->db->sql_query($sql);
		$updates = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$data = @unserialize($row['log_data'], ['allowed_classes' => false]);
			if (!is_array($data))
			{
				continue;
			}
			$changed = false;
			foreach ($data as $i => $value)
			{
				if (is_string($value) && strpos($value, 'ACP_DBGUARDIAN_') === 0 && $language->is_set($value))
				{
					$data[$i] = $language->lang($value);
					$changed = true;
				}
			}
			if ($changed)
			{
				$updates[(int) $row['log_id']] = serialize($data);
			}
		}
		$this->db->sql_freeresult($result);

		foreach ($updates as $log_id => $log_data)
		{
			$sql = 'UPDATE ' . $this->table_prefix . "log
				SET log_data = '" . $this->db->sql_escape($log_data) . "'
				WHERE log_id = " . (int) $log_id;
			$this->db->sql_query($sql);
		}
	}
}
