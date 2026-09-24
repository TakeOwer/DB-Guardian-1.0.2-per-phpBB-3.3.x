<?php
/**
 *
 * DB Guardian. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano - https://netshadows.de
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\dbguardian;

class ext extends \phpbb\extension\base
{
	public function is_enableable()
	{
		return version_compare(PHP_VERSION, '7.4.0', '>=')
			&& phpbb_version_compare($this->container->get('config')['version'], '3.3.0', '>=');
	}

	/**
	 * After the migrations: copy the runtime into store/ and activate it in .user.ini.
	 */
	public function enable_step($old_state)
	{
		$result = parent::enable_step($old_state === 'dbguardian_deployed' ? false : $old_state);

		if ($result === false && $old_state !== 'dbguardian_deployed')
		{
			$manager = $this->manager();
			$manager->deploy(true);
			$manager->install_prepend();
			return 'dbguardian_deployed';
		}
		return $result;
	}

	/**
	 * Remove the .user.ini block and switch the runtime off. The runtime files stay in
	 * store/dbguardian/ because PHP may keep the old .user.ini in cache for a few minutes:
	 * a missing auto_prepend_file would stop the whole forum.
	 */
	public function disable_step($old_state)
	{
		if ($old_state === false)
		{
			$manager = $this->manager();
			$manager->remove_prepend();
			if (is_file($manager->config_path()))
			{
				$manager->deploy(false);
			}
			return 'dbguardian_disabled';
		}
		return parent::disable_step($old_state === 'dbguardian_disabled' ? false : $old_state);
	}

	public function purge_step($old_state)
	{
		if ($old_state === false)
		{
			$manager = $this->manager();
			$manager->remove_prepend();
			$manager->clear_log();
			$manager->reset_state();
			return 'dbguardian_purged';
		}
		return parent::purge_step($old_state === 'dbguardian_purged' ? false : $old_state);
	}

	/**
	 * The extension services are not in the container yet while it is being enabled.
	 */
	protected function manager()
	{
		return new \salvocortesiano\dbguardian\core\manager(
			$this->container->get('config'),
			$this->container->getParameter('core.root_path'),
			$this->container->getParameter('core.php_ext')
		);
	}
}
