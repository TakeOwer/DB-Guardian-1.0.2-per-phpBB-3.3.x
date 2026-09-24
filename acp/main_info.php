<?php
/**
 *
 * DB Guardian. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano - https://netshadows.de
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\dbguardian\acp;

class main_info
{
	public function module()
	{
		return [
			'filename' => '\salvocortesiano\dbguardian\acp\main_module',
			'title'    => 'ACP_DBGUARDIAN_TITLE',
			'modes'    => [
				'status'   => ['title' => 'ACP_DBGUARDIAN_STATUS', 'auth' => 'ext_salvocortesiano/dbguardian && acl_a_board', 'cat' => ['ACP_DBGUARDIAN_TITLE']],
				'settings' => ['title' => 'ACP_DBGUARDIAN_SETTINGS', 'auth' => 'ext_salvocortesiano/dbguardian && acl_a_board', 'cat' => ['ACP_DBGUARDIAN_TITLE']],
				'log'      => ['title' => 'ACP_DBGUARDIAN_LOG', 'auth' => 'ext_salvocortesiano/dbguardian && acl_a_board', 'cat' => ['ACP_DBGUARDIAN_TITLE']],
			],
		];
	}
}
