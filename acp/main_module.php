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

class main_module
{
	const FORM_KEY = 'salvocortesiano_dbguardian';
	const CATEGORIES = ['db_connect', 'db_query', 'php_fatal', 'app'];

	public $u_action;
	public $tpl_name;
	public $page_title;

	/** @var \phpbb\config\config */
	protected $config;
	/** @var \phpbb\language\language */
	protected $language;
	/** @var \phpbb\request\request */
	protected $request;
	/** @var \phpbb\template\template */
	protected $template;
	/** @var \phpbb\user */
	protected $user;
	/** @var \phpbb\pagination */
	protected $pagination;
	/** @var \salvocortesiano\dbguardian\core\manager */
	protected $manager;

	public function main($id, $mode)
	{
		global $phpbb_container;

		$this->config = $phpbb_container->get('config');
		$this->language = $phpbb_container->get('language');
		$this->request = $phpbb_container->get('request');
		$this->template = $phpbb_container->get('template');
		$this->user = $phpbb_container->get('user');
		$this->pagination = $phpbb_container->get('pagination');
		$this->manager = $phpbb_container->get('salvocortesiano.dbguardian.manager');

		$this->language->add_lang('dbguardian_acp', 'salvocortesiano/dbguardian');

		// After an update of the extension files, bring the copy in store/dbguardian/ up to date.
		if (is_file($this->manager->config_path()) && $this->manager->runtime_outdated())
		{
			$this->manager->deploy();
		}

		if (!$this->manager->load_core())
		{
			trigger_error($this->language->lang('DBGUARDIAN_CORE_MISSING'), E_USER_WARNING);
		}

		add_form_key(self::FORM_KEY);

		switch ($mode)
		{
			case 'settings':
				$this->settings();
			break;

			case 'log':
				$this->log();
			break;

			case 'monitor':
				$this->monitor();
			break;

			default:
				$this->status();
			break;
		}

		$this->assign_badges();
	}

	// ------------------------------------------------------------------
	// Status and installation
	// ------------------------------------------------------------------

	protected function status()
	{
		$this->tpl_name = 'acp_dbguardian_status';
		$this->page_title = 'ACP_DBGUARDIAN_STATUS';

		$action = $this->request->variable('action', '');

		if ($action === 'preview')
		{
			if (!check_link_hash($this->request->variable('hash', ''), 'dbguardian_preview'))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}
			$html = $this->manager->preview($this->request->variable('cat', 'db_connect'), $this->request->variable('lang', 'it'));
			header('Content-Type: text/html; charset=UTF-8');
			header('X-Robots-Tag: noindex');
			echo $html;
			garbage_collection();
			exit_handler();
		}

		foreach (['install', 'redeploy', 'remove', 'test_mail', 'debug_on', 'debug_off', 'new_key', 'reset_state'] as $post_action)
		{
			if ($this->request->is_set_post('dbg_' . $post_action))
			{
				if (!check_form_key(self::FORM_KEY))
				{
					trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
				}
				$this->run_action($post_action);
			}
		}

		$st = $this->manager->status();
		$cfg = $this->manager->load();
		$summary = $this->manager->log_summary();
		\DbGuardianCore::configure($this->manager->store_dir(), $cfg);
		$recipients = \DbGuardianCore::recipients();

		if ($st['loaded'])
		{
			$state = 'active';
		}
		else if ($st['block_present'])
		{
			$state = 'pending';
		}
		else
		{
			$state = 'inactive';
		}
		if (!$cfg['enabled'])
		{
			$state = 'disabled';
		}

		// The live test must reach this very installation, not the address typed in the settings.
		$board_url = generate_board_url() . '/';
		$live_ok = $st['loaded'] && $cfg['enabled'];
		foreach (self::CATEGORIES as $cat)
		{
			$this->template->assign_block_vars('previews', [
				'LABEL'  => $this->language->lang('DBGUARDIAN_CAT_' . strtoupper($cat)),
				'U_IT'   => $this->u_action . '&amp;action=preview&amp;cat=' . $cat . '&amp;lang=it&amp;hash=' . generate_link_hash('dbguardian_preview'),
				'U_EN'   => $this->u_action . '&amp;action=preview&amp;cat=' . $cat . '&amp;lang=en&amp;hash=' . generate_link_hash('dbguardian_preview'),
				'U_LIVE' => $board_url . 'index.php?dbguardian_test=' . rawurlencode($cfg['debug_key']) . '&amp;cat=' . $cat,
			]);
		}

		$debug_on = $this->request->variable('dbguardian_debug', '', false, \phpbb\request\request_interface::COOKIE) === $cfg['debug_key'];

		$this->template->assign_vars([
			'U_ACTION'            => $this->u_action,
			'DBG_STATE'           => $state,
			'DBG_STATE_TEXT'      => $this->language->lang('DBGUARDIAN_STATE_' . strtoupper($state), $st['cache_ttl']),
			'DBG_SAPI'            => $st['sapi'],
			'DBG_SAPI_OK'         => $st['sapi_ok'],
			'DBG_SAPI_LSAPI'      => $st['sapi_lsapi'],
			'DBG_INI_NAME'        => $st['user_ini_name'],
			'DBG_CACHE_TTL'       => $st['cache_ttl'],
			'DBG_ACTIVE_PREPEND'  => $st['active_prepend'],
			'DBG_OURS'            => $st['ours'],
			'DBG_BLOCK_PRESENT'   => $st['block_present'],
			'DBG_INI_PATH'        => $st['ini_path'],
			'DBG_INI_WRITABLE'    => $st['ini_writable'],
			'DBG_STORE_DIR'       => $st['store_dir'],
			'DBG_STORE_WRITABLE'  => $st['store_writable'],
			'DBG_DEPLOYED'        => $st['deployed'],
			'DBG_OUTDATED'        => $st['outdated'],
			'DBG_GUARDIAN_PATH'   => $st['guardian_path'],
			'DBG_MAIL_AVAILABLE'  => $st['mail_available'],
			'DBG_TRANSPORT'       => $cfg['mail_transport'] === 'smtp' ? 'SMTP ' . $cfg['smtp_host'] . ':' . $cfg['smtp_port'] : 'PHP mail()',
			'DBG_RECIPIENTS'      => implode(', ', $recipients),
			'DBG_NO_RECIPIENTS'   => $recipients === [],
			'DBG_CHAIN'           => $cfg['chain_prepend'],
			'DBG_HTACCESS_LINE'   => 'php_value auto_prepend_file "' . $st['guardian_path'] . '"',
			'DBG_EVENTS_30'       => $summary['last_30'],
			'DBG_LAST_EVENT'      => $summary['last'] ? $this->user->format_date($summary['last']) : '',
			'DBG_DOWN_OPEN'       => $summary['down_open'],
			'DBG_DEBUG_ON'        => $debug_on,
			'DBG_ENABLED'         => (bool) $cfg['enabled'],
			'S_LIVE_AVAILABLE'    => $live_ok,
			'DBG_LIVE_REASON'     => $this->language->lang('DBGUARDIAN_LIVE_REASON_' . strtoupper($state), $st['cache_ttl']),
		]);
	}

	protected function run_action($action)
	{
		$back = adm_back_link($this->u_action);

		switch ($action)
		{
			case 'install':
				$errors = $this->manager->deploy(true);
				if ($errors)
				{
					trigger_error($this->errors_text($errors) . $back, E_USER_WARNING);
				}
				list($ok, $key, $detail) = $this->manager->install_prepend();
				$ttl = (int) ini_get('user_ini.cache_ttl');
				trigger_error($this->language->lang('DBGUARDIAN_' . $key, $detail, $ttl) . $back, $ok ? E_USER_NOTICE : E_USER_WARNING);
			break;

			case 'remove':
				list($ok, $key, $detail) = $this->manager->remove_prepend();
				if ($ok)
				{
					$this->manager->deploy(false);
				}
				trigger_error($this->language->lang('DBGUARDIAN_' . $key, $detail, (int) ini_get('user_ini.cache_ttl')) . $back, $ok ? E_USER_NOTICE : E_USER_WARNING);
			break;

			case 'redeploy':
				$errors = $this->manager->deploy();
				trigger_error(($errors ? $this->errors_text($errors) : $this->language->lang('DBGUARDIAN_RUNTIME_UPDATED')) . $back, $errors ? E_USER_WARNING : E_USER_NOTICE);
			break;

			case 'test_mail':
				list($ok, $error) = $this->manager->send_test_mail();
				trigger_error(($ok ? $this->language->lang('DBGUARDIAN_TEST_MAIL_OK') : $this->language->lang('DBGUARDIAN_TEST_MAIL_FAIL', $error)) . $back, $ok ? E_USER_NOTICE : E_USER_WARNING);
			break;

			case 'debug_on':
			case 'debug_off':
				$cfg = $this->manager->load();
				$secure = $this->request->is_secure();
				$on = $action === 'debug_on';
				setcookie('dbguardian_debug', $on ? $cfg['debug_key'] : '', [
					'expires'  => $on ? time() + 30 * 86400 : time() - 3600,
					'path'     => '/',
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Lax',
				]);
				trigger_error($this->language->lang($on ? 'DBGUARDIAN_DEBUG_ENABLED' : 'DBGUARDIAN_DEBUG_DISABLED') . $back);
			break;

			case 'new_key':
				$cfg = $this->manager->load();
				$cfg['debug_key'] = bin2hex(random_bytes(16));
				$this->manager->save($cfg);
				trigger_error($this->language->lang('DBGUARDIAN_KEY_RENEWED') . $back);
			break;

			case 'reset_state':
				$this->manager->reset_state();
				trigger_error($this->language->lang('DBGUARDIAN_STATE_RESET') . $back);
			break;
		}
	}

	protected function errors_text(array $errors)
	{
		$lines = [];
		foreach ($errors as $error)
		{
			$lines[] = $this->language->lang('DBGUARDIAN_ERR_' . $error, $this->manager->store_dir());
		}
		return implode('<br>', $lines);
	}

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	protected function settings()
	{
		$this->tpl_name = 'acp_dbguardian_settings';
		$this->page_title = 'ACP_DBGUARDIAN_SETTINGS';

		$cfg = $this->manager->load();
		$errors = [];

		if ($this->request->is_set_post('import_smtp'))
		{
			if (!check_form_key(self::FORM_KEY))
			{
				trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}
			if (empty($this->config['smtp_host']))
			{
				trigger_error($this->language->lang('DBGUARDIAN_SMTP_NONE') . adm_back_link($this->u_action), E_USER_WARNING);
			}
			$this->manager->save(array_merge($cfg, $this->manager->phpbb_smtp()));
			trigger_error($this->language->lang('DBGUARDIAN_SMTP_IMPORTED') . adm_back_link($this->u_action));
		}

		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key(self::FORM_KEY))
			{
				$errors[] = $this->language->lang('FORM_INVALID');
			}

			$new = $this->read_form($cfg);
			$errors = array_merge($errors, $this->validate($new));

			if (!$errors)
			{
				if (!$this->manager->save($new))
				{
					trigger_error($this->language->lang('DBGUARDIAN_ERR_CONFIG_WRITE_FAILED', $this->manager->store_dir()) . adm_back_link($this->u_action), E_USER_WARNING);
				}
				$deploy = $this->manager->deploy();
				$this->manager->prune_logs($new['log_months']);
				$message = $this->language->lang('DBGUARDIAN_SETTINGS_SAVED');
				if ($deploy)
				{
					$message .= '<br>' . $this->errors_text($deploy);
				}
				trigger_error($message . adm_back_link($this->u_action), $deploy ? E_USER_WARNING : E_USER_NOTICE);
			}
			$cfg = $new;
		}

		foreach (self::CATEGORIES as $cat)
		{
			$this->template->assign_block_vars('notify', [
				'VALUE'   => $cat,
				'LABEL'   => $this->language->lang('DBGUARDIAN_CAT_' . strtoupper($cat)),
				'EXPLAIN' => $this->language->lang('DBGUARDIAN_CAT_' . strtoupper($cat) . '_EXPLAIN'),
				'CHECKED' => in_array($cat, $cfg['notify'], true),
			]);
		}

		$this->template->assign_vars([
			'U_ACTION'          => $this->u_action,
			'S_ERROR'           => (bool) $errors,
			'ERROR_MSG'         => implode('<br>', $errors),
			'S_PHPBB_SMTP'      => !empty($this->config['smtp_host']),
			'PHPBB_SMTP_HOST'   => (string) $this->config['smtp_host'],

			'ENABLED'           => (bool) $cfg['enabled'],
			'BOARD_NAME'        => $cfg['board_name'],
			'BOARD_URL'         => $cfg['board_url'],
			'CONTACT_EMAIL'     => $cfg['contact_email'],
			'PAGE_LANGUAGE'     => $cfg['page_language'],
			'TIMEZONE'          => $cfg['timezone'],
			'RETRY_SECONDS'     => $cfg['retry_seconds'],
			'STATUS_URL'        => $cfg['status_url'],
			'LOGO_URL'          => $cfg['logo_url'],
			'ACCENT_COLOR'      => $cfg['accent_color'],
			'CUSTOM_MESSAGE'    => $cfg['custom_message'],

			'INTERCEPT'         => $cfg['intercept'],
			'CATCH_FATAL'       => (bool) $cfg['catch_fatal'],
			'EXCLUDE_PATHS'     => implode("\n", $cfg['exclude_paths']),

			'ADMIN_EMAILS'      => $cfg['admin_emails'],
			'NOTIFY_RECOVERY'   => (bool) $cfg['notify_recovery'],
			'THROTTLE_MINUTES'  => $cfg['throttle_minutes'],

			'MAIL_TRANSPORT'    => $cfg['mail_transport'],
			'MAIL_FROM'         => $cfg['mail_from'],
			'MAIL_FROM_NAME'    => $cfg['mail_from_name'],
			'MAIL_F_PARAM'      => (bool) $cfg['mail_f_param'],
			'SMTP_HOST'         => $cfg['smtp_host'],
			'SMTP_PORT'         => $cfg['smtp_port'],
			'SMTP_SECURITY'     => $cfg['smtp_security'],
			'SMTP_USER'         => $cfg['smtp_user'],
			'SMTP_PASS_SET'     => $cfg['smtp_pass'] !== '',
			'SMTP_VERIFY_PEER'  => (bool) $cfg['smtp_verify_peer'],

			'LOG_MONTHS'        => $cfg['log_months'],
			'CONFIG_PATH'       => $this->manager->config_path(),
		]);
	}

	protected function read_form(array $cfg)
	{
		$r = $this->request;
		$new = $cfg;

		$new['enabled']          = $r->variable('enabled', 0) === 1;
		$new['board_name']       = trim($r->variable('board_name', '', true));
		$new['board_url']        = trim($r->variable('board_url', ''));
		$new['contact_email']    = trim($r->variable('contact_email', ''));
		$new['page_language']    = $r->variable('page_language', 'auto');
		$new['timezone']         = trim($r->variable('timezone', 'Europe/Rome'));
		$new['retry_seconds']    = $r->variable('retry_seconds', 60);
		$new['status_url']       = trim($r->variable('status_url', ''));
		$new['logo_url']         = trim($r->variable('logo_url', ''));
		$new['accent_color']     = trim($r->variable('accent_color', '#22577a'));
		$new['custom_message']   = trim($r->variable('custom_message', '', true));

		$new['intercept']        = $r->variable('intercept', 'all') === 'db' ? 'db' : 'all';
		$new['catch_fatal']      = $r->variable('catch_fatal', 0) === 1;
		$paths = preg_split('/\R/', $r->variable('exclude_paths', '', true));
		$new['exclude_paths']    = array_values(array_filter(array_map('trim', $paths), 'strlen'));

		$new['admin_emails']     = trim($r->variable('admin_emails', ''));
		$new['notify']           = array_values(array_intersect(self::CATEGORIES, $r->variable('notify', [''])));
		$new['notify_recovery']  = $r->variable('notify_recovery', 0) === 1;
		$new['throttle_minutes'] = $r->variable('throttle_minutes', 30);

		$new['mail_transport']   = $r->variable('mail_transport', 'mail') === 'smtp' ? 'smtp' : 'mail';
		$new['mail_from']        = trim($r->variable('mail_from', ''));
		$new['mail_from_name']   = trim($r->variable('mail_from_name', '', true));
		$new['mail_f_param']     = $r->variable('mail_f_param', 0) === 1;
		$new['smtp_host']        = trim($r->variable('smtp_host', ''));
		$new['smtp_port']        = $r->variable('smtp_port', 587);
		$security                = $r->variable('smtp_security', 'tls');
		$new['smtp_security']    = in_array($security, ['none', 'ssl', 'tls'], true) ? $security : 'tls';
		$new['smtp_user']        = trim($r->variable('smtp_user', '', true));
		$new['smtp_verify_peer'] = $r->variable('smtp_verify_peer', 0) === 1;
		$pass = $r->variable('smtp_pass', '', true);
		if ($r->variable('smtp_pass_clear', 0) === 1)
		{
			$new['smtp_pass'] = '';
		}
		else if ($pass !== '')
		{
			$new['smtp_pass'] = htmlspecialchars_decode($pass, ENT_COMPAT);
		}

		$new['log_months']       = $r->variable('log_months', 6);

		// Text coming from the request is HTML-escaped by phpBB: the runtime escapes on output itself.
		foreach (['board_name', 'custom_message', 'mail_from_name', 'smtp_user'] as $key)
		{
			$new[$key] = htmlspecialchars_decode($new[$key], ENT_COMPAT);
		}
		return $new;
	}

	protected function validate(array $cfg)
	{
		$errors = [];
		$lang = $this->language;

		if ($cfg['board_name'] === '')
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_BOARD_NAME');
		}
		if ($cfg['board_url'] !== '' && !preg_match('#^https?://#i', $cfg['board_url']))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_URL', $lang->lang('DBGUARDIAN_BOARD_URL'));
		}
		if ($cfg['status_url'] !== '' && !preg_match('#^https?://#i', $cfg['status_url']))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_URL', $lang->lang('DBGUARDIAN_STATUS_URL'));
		}
		if ($cfg['logo_url'] !== '' && !preg_match('#^(https?://|/)#i', $cfg['logo_url']))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_LOGO');
		}
		if ($cfg['contact_email'] !== '' && !filter_var($cfg['contact_email'], FILTER_VALIDATE_EMAIL))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_EMAIL', $cfg['contact_email']);
		}
		foreach (preg_split('/[\s,;]+/', $cfg['admin_emails'], -1, PREG_SPLIT_NO_EMPTY) as $addr)
		{
			if (!filter_var($addr, FILTER_VALIDATE_EMAIL))
			{
				$errors[] = $lang->lang('DBGUARDIAN_ERR_EMAIL', $addr);
			}
		}
		if ($cfg['notify'] && trim($cfg['admin_emails']) === '')
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_NO_RECIPIENTS');
		}
		if ($cfg['mail_from'] !== '' && !filter_var($cfg['mail_from'], FILTER_VALIDATE_EMAIL))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_EMAIL', $cfg['mail_from']);
		}
		if (!in_array($cfg['timezone'], \DateTimeZone::listIdentifiers(), true))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_TIMEZONE', $cfg['timezone']);
		}
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cfg['accent_color']))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_COLOR');
		}
		if ($cfg['retry_seconds'] < 0 || $cfg['retry_seconds'] > 3600)
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_RANGE', $lang->lang('DBGUARDIAN_RETRY_SECONDS'), 0, 3600);
		}
		if ($cfg['throttle_minutes'] < 1 || $cfg['throttle_minutes'] > 1440)
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_RANGE', $lang->lang('DBGUARDIAN_THROTTLE'), 1, 1440);
		}
		if ($cfg['log_months'] < 1 || $cfg['log_months'] > 36)
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_RANGE', $lang->lang('DBGUARDIAN_LOG_MONTHS'), 1, 36);
		}
		if ($cfg['mail_transport'] === 'smtp')
		{
			if ($cfg['smtp_host'] === '')
			{
				$errors[] = $lang->lang('DBGUARDIAN_ERR_SMTP_HOST');
			}
			if ($cfg['smtp_port'] < 1 || $cfg['smtp_port'] > 65535)
			{
				$errors[] = $lang->lang('DBGUARDIAN_ERR_RANGE', $lang->lang('DBGUARDIAN_SMTP_PORT'), 1, 65535);
			}
		}
		return $errors;
	}

	// ------------------------------------------------------------------
	// External monitoring
	// ------------------------------------------------------------------

	protected function monitor()
	{
		$this->tpl_name = 'acp_dbguardian_monitor';
		$this->page_title = 'ACP_DBGUARDIAN_MONITOR';

		$cfg = $this->manager->load();
		$back = adm_back_link($this->u_action);
		$errors = [];
		$probe = null;

		if ($this->request->variable('action', '') === 'download')
		{
			if (!check_link_hash($this->request->variable('hash', ''), 'dbguardian_watchdog'))
			{
				trigger_error($this->language->lang('FORM_INVALID') . $back, E_USER_WARNING);
			}
			$script = $this->manager->watchdog_script($cfg);
			if ($script === false)
			{
				trigger_error($this->language->lang('DBGUARDIAN_WD_TEMPLATE_MISSING') . $back, E_USER_WARNING);
			}
			header('Content-Type: text/x-python; charset=UTF-8');
			header('Content-Disposition: attachment; filename="dbguardian_watchdog.py"');
			header('Content-Length: ' . strlen($script));
			header('Cache-Control: no-store');
			echo $script;
			garbage_collection();
			exit_handler();
		}

		foreach (['dbg_health_key', 'dbg_health_probe', 'dbg_monitor_save'] as $post_action)
		{
			if ($this->request->is_set_post($post_action) && !check_form_key(self::FORM_KEY))
			{
				trigger_error($this->language->lang('FORM_INVALID') . $back, E_USER_WARNING);
			}
		}

		if ($this->request->is_set_post('dbg_health_key'))
		{
			$cfg['health_key'] = bin2hex(random_bytes(16));
			$this->manager->save($cfg);
			trigger_error($this->language->lang('DBGUARDIAN_WD_KEY_RENEWED') . $back);
		}

		if ($this->request->is_set_post('dbg_health_probe'))
		{
			$probe = $this->manager->health_probe();
		}

		if ($this->request->is_set_post('dbg_monitor_save'))
		{
			$new = $this->read_monitor_form($cfg);
			$errors = $this->validate_monitor($new);
			if (!$errors)
			{
				if (!$this->manager->save($new))
				{
					trigger_error($this->language->lang('DBGUARDIAN_ERR_CONFIG_WRITE_FAILED', $this->manager->store_dir()) . $back, E_USER_WARNING);
				}
				trigger_error($this->language->lang('DBGUARDIAN_WD_SAVED') . $back);
			}
			$cfg = $new;
		}

		$w = $this->manager->watchdog_settings($cfg);
		$last = $this->manager->last_health_request();
		$last_time = isset($last['time']) ? (int) $last['time'] : 0;
		$stale_after = max(5, 3 * (int) $w['interval']) * 60;
		$script_path = '/opt/dbguardian/dbguardian_watchdog.py';

		$this->template->assign_vars([
			'U_ACTION'            => $this->u_action,
			'S_ERROR'             => (bool) $errors,
			'ERROR_MSG'           => implode('<br>', $errors),
			'S_GUARDIAN_LOADED'   => $this->manager->status()['loaded'],

			'HEALTH_ENABLED'      => (bool) $cfg['health_enabled'],
			'HEALTH_URL'          => $this->manager->health_url($cfg),
			'U_DOWNLOAD'          => $this->u_action . '&amp;action=download&amp;hash=' . generate_link_hash('dbguardian_watchdog'),
			'S_READY'             => (bool) $cfg['health_enabled'] && (trim($w['recipients']) !== '' && $w['smtp_host'] !== '' || $w['telegram_token'] !== ''),

			'S_LAST'              => $last_time > 0,
			'LAST_TIME'           => $last_time ? $this->user->format_date($last_time) : '',
			'LAST_AGO'            => $last_time ? $this->ago(time() - $last_time) : '',
			'LAST_STALE'          => $last_time > 0 && time() - $last_time > $stale_after,
			'LAST_IP'             => isset($last['ip']) ? $last['ip'] : '',
			'LAST_UA'             => isset($last['ua']) ? $last['ua'] : '',
			'LAST_DB'             => isset($last['db']) ? $last['db'] : '',
			'LAST_IS_WATCHDOG'    => isset($last['ua']) && strpos($last['ua'], 'DBGuardian-Watchdog') === 0,

			'S_PROBE'             => $probe !== null,
			'PROBE_OK'            => $probe !== null && $probe['ok'],
			'PROBE_DB'            => $probe !== null ? $probe['db'] : '',
			'PROBE_MS'            => $probe !== null ? $probe['db_ms'] : 0,
			'PROBE_ERROR'         => $probe !== null && $probe['db_error'] === 'RELOAD' ? $this->language->lang('DBGUARDIAN_WD_PROBE_RELOAD') : ($probe !== null && $probe['db_error'] !== '' ? ($probe['db_code'] ? $probe['db_code'] . ': ' : '') . $probe['db_error'] : ''),

			'WD_INTERVAL'         => (int) $w['interval'],
			'WD_THRESHOLD'        => (int) $w['threshold'],
			'WD_TIMEOUT'          => (int) $w['timeout'],
			'WD_REALERT'          => (int) $w['realert_minutes'],
			'WD_SSL_DAYS'         => (int) $w['ssl_warn_days'],
			'WD_RECIPIENTS'       => $w['recipients'],
			'WD_SMTP_HOST'        => $w['smtp_host'],
			'WD_SMTP_PORT'        => (int) $w['smtp_port'],
			'WD_SMTP_SECURITY'    => $w['smtp_security'],
			'WD_SMTP_USER'        => $w['smtp_user'],
			'WD_SMTP_PASS_SET'    => $w['smtp_pass'] !== '',
			'WD_SMTP_FROM'        => $w['smtp_from'],
			'WD_SMTP_FROM_NAME'   => $w['smtp_from_name'],
			'WD_SMTP_VERIFY'      => (bool) $w['smtp_verify'],
			'WD_TG_TOKEN_SET'     => $w['telegram_token'] !== '',
			'WD_TG_CHAT'          => $w['telegram_chat'],

			'WD_SCRIPT_PATH'      => $script_path,
			'WD_CRON_LINE'        => $this->manager->cron_line($cfg, $script_path),
			'WD_KEYWORD'          => '"ok":true',
		]);
	}

	protected function read_monitor_form(array $cfg)
	{
		$r = $this->request;
		$new = $cfg;
		$w = $this->manager->watchdog_settings($cfg);

		$new['health_enabled'] = $r->variable('health_enabled', 0) === 1;
		$w['interval']         = $r->variable('wd_interval', 1);
		$w['threshold']        = $r->variable('wd_threshold', 2);
		$w['timeout']          = $r->variable('wd_timeout', 20);
		$w['realert_minutes']  = $r->variable('wd_realert', 60);
		$w['ssl_warn_days']    = $r->variable('wd_ssl_days', 14);
		$w['recipients']       = trim($r->variable('wd_recipients', ''));
		$w['smtp_host']        = trim($r->variable('wd_smtp_host', ''));
		$w['smtp_port']        = $r->variable('wd_smtp_port', 465);
		$security              = $r->variable('wd_smtp_security', 'ssl');
		$w['smtp_security']    = in_array($security, ['none', 'ssl', 'tls'], true) ? $security : 'ssl';
		$w['smtp_user']        = htmlspecialchars_decode(trim($r->variable('wd_smtp_user', '', true)), ENT_COMPAT);
		$w['smtp_from']        = trim($r->variable('wd_smtp_from', ''));
		$w['smtp_from_name']   = htmlspecialchars_decode(trim($r->variable('wd_smtp_from_name', '', true)), ENT_COMPAT);
		$w['smtp_verify']      = $r->variable('wd_smtp_verify', 0) === 1;
		$w['telegram_chat']    = trim($r->variable('wd_tg_chat', ''));

		$pass = $r->variable('wd_smtp_pass', '', true);
		if ($r->variable('wd_smtp_pass_clear', 0) === 1)
		{
			$w['smtp_pass'] = '';
		}
		else if ($pass !== '')
		{
			$w['smtp_pass'] = htmlspecialchars_decode($pass, ENT_COMPAT);
		}

		$token = trim($r->variable('wd_tg_token', ''));
		if ($r->variable('wd_tg_token_clear', 0) === 1)
		{
			$w['telegram_token'] = '';
		}
		else if ($token !== '')
		{
			$w['telegram_token'] = $token;
		}

		$new['watchdog'] = $w;
		return $new;
	}

	protected function validate_monitor(array $cfg)
	{
		$errors = [];
		$lang = $this->language;
		$w = $cfg['watchdog'];

		$ranges = [
			['interval', 'DBGUARDIAN_WD_INTERVAL', 1, 15],
			['threshold', 'DBGUARDIAN_WD_THRESHOLD', 1, 5],
			['timeout', 'DBGUARDIAN_WD_TIMEOUT', 5, 60],
			['realert_minutes', 'DBGUARDIAN_WD_REALERT', 15, 1440],
			['ssl_warn_days', 'DBGUARDIAN_WD_SSL_DAYS', 0, 60],
		];
		foreach ($ranges as $range)
		{
			if ($w[$range[0]] < $range[2] || $w[$range[0]] > $range[3])
			{
				$errors[] = $lang->lang('DBGUARDIAN_ERR_RANGE', $lang->lang($range[1]), $range[2], $range[3]);
			}
		}
		foreach (preg_split('/[\s,;]+/', $w['recipients'], -1, PREG_SPLIT_NO_EMPTY) as $addr)
		{
			if (!filter_var($addr, FILTER_VALIDATE_EMAIL))
			{
				$errors[] = $lang->lang('DBGUARDIAN_ERR_EMAIL', $addr);
			}
		}
		if ($w['smtp_from'] !== '' && !filter_var($w['smtp_from'], FILTER_VALIDATE_EMAIL))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_EMAIL', $w['smtp_from']);
		}
		if ($w['smtp_host'] !== '' && ($w['smtp_port'] < 1 || $w['smtp_port'] > 65535))
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_RANGE', $lang->lang('DBGUARDIAN_SMTP_PORT'), 1, 65535);
		}
		if ($w['smtp_host'] !== '' && trim($w['recipients']) === '')
		{
			$errors[] = $lang->lang('DBGUARDIAN_ERR_NO_RECIPIENTS');
		}
		if ($w['telegram_token'] !== '' && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $w['telegram_token']))
		{
			$errors[] = $lang->lang('DBGUARDIAN_WD_ERR_TOKEN');
		}
		if (($w['telegram_token'] !== '') !== ($w['telegram_chat'] !== ''))
		{
			$errors[] = $lang->lang('DBGUARDIAN_WD_ERR_TELEGRAM_PAIR');
		}
		if ($w['telegram_chat'] !== '' && !preg_match('/^(-?\d+|@[A-Za-z0-9_]{5,})$/', $w['telegram_chat']))
		{
			$errors[] = $lang->lang('DBGUARDIAN_WD_ERR_CHAT');
		}
		if ($cfg['health_enabled'] && $w['smtp_host'] === '' && $w['telegram_token'] === '')
		{
			$errors[] = $lang->lang('DBGUARDIAN_WD_ERR_NO_CHANNEL');
		}
		return $errors;
	}

	/**
	 * The hint for the administrator, from the language files of the board.
	 */
	protected function hint_text(array $inc)
	{
		$key = 'DBGUARDIAN_HINT_' . (int) $inc['code'];
		if ((int) $inc['code'] && $this->language->is_set($key))
		{
			return $this->language->lang($key);
		}
		$cause = \DbGuardianCore::cause($inc);
		if ($cause === 'memory' || $cause === 'timeout')
		{
			return $this->language->lang('DBGUARDIAN_HINT_' . strtoupper($cause));
		}
		return $inc['category'] === 'db_connect' ? $this->language->lang('DBGUARDIAN_HINT_DB_CONNECT') : '';
	}

	protected function duration($seconds)
	{
		$seconds = max(0, (int) $seconds);
		if ($seconds < 5400)
		{
			return $this->language->lang('DBGUARDIAN_WD_DURATION_MINUTES', max(1, (int) round($seconds / 60)));
		}
		return $this->language->lang('DBGUARDIAN_WD_DURATION_HOURS', (int) round($seconds / 3600));
	}

	protected function ago($seconds)
	{
		$seconds = max(0, (int) $seconds);
		if ($seconds < 90)
		{
			return $this->language->lang('DBGUARDIAN_WD_AGO_SECONDS', $seconds);
		}
		if ($seconds < 5400)
		{
			return $this->language->lang('DBGUARDIAN_WD_AGO_MINUTES', (int) round($seconds / 60));
		}
		if ($seconds < 172800)
		{
			return $this->language->lang('DBGUARDIAN_WD_AGO_HOURS', (int) round($seconds / 3600));
		}
		return $this->language->lang('DBGUARDIAN_WD_AGO_DAYS', (int) round($seconds / 86400));
	}

	// ------------------------------------------------------------------
	// Event log
	// ------------------------------------------------------------------

	protected function log()
	{
		$this->tpl_name = 'acp_dbguardian_log';
		$this->page_title = 'ACP_DBGUARDIAN_LOG';

		$category = $this->request->variable('cat', '');
		if ($category !== '' && !in_array($category, array_merge(self::CATEGORIES, ['recovery']), true))
		{
			$category = '';
		}

		if ($this->request->is_set_post('clear_log'))
		{
			if (confirm_box(true))
			{
				$this->manager->clear_log();
				trigger_error($this->language->lang('DBGUARDIAN_LOG_CLEARED') . adm_back_link($this->u_action));
			}
			else
			{
				confirm_box(false, $this->language->lang('DBGUARDIAN_LOG_CLEAR_CONFIRM'), build_hidden_fields(['clear_log' => 1]));
			}
		}

		$per_page = 25;
		$start = max(0, $this->request->variable('start', 0));
		list($rows, $total) = $this->manager->read_log($start, $per_page, $category);
		if ($start > 0 && !$rows && $total > 0)
		{
			$start = 0;
			list($rows, $total) = $this->manager->read_log($start, $per_page, $category);
		}

		foreach ($rows as $row)
		{
			$inc = array_merge(['category' => '', 'code' => 0, 'message' => '', 'title' => '', 'ref' => '', 'time' => 0,
				'url' => '', 'ip' => '', 'ua' => '', 'method' => '', 'file' => '', 'line' => 0, 'sql' => '', 'trace' => '',
				'repeats' => 0, 'suppressed' => 0, 'mail' => '', 'source' => ''], $row);
			$is_recovery = $inc['category'] === 'recovery';
			$mail = (string) $inc['mail'];
			$mail_state = strpos($mail, 'failed') === 0 ? 'failed' : ($mail === '' ? 'off' : $mail);

			$this->template->assign_block_vars('events', [
				'TIME'        => $this->user->format_date((int) $inc['time']),
				'REF'         => $inc['ref'],
				'CATEGORY'    => $inc['category'],
				'CAT_LABEL'   => $this->language->is_set('DBGUARDIAN_CAT_' . strtoupper($inc['category'])) ? $this->language->lang('DBGUARDIAN_CAT_' . strtoupper($inc['category'])) : $inc['category'],
				'CODE'        => $is_recovery ? '' : \DbGuardianCore::error_code($inc),
				'MESSAGE'     => ($is_recovery && !empty($row['down_since'])) ? $this->language->lang('DBGUARDIAN_RECOVERY_MESSAGE', $this->user->format_date((int) $row['down_since']), $this->duration((int) $inc['time'] - (int) $row['down_since']), (int) (isset($row['down_count']) ? $row['down_count'] : 0)) : $inc['message'],
				'TITLE'       => $inc['title'],
				'URL'         => $inc['url'],
				'IP'          => $inc['ip'],
				'UA'          => $inc['ua'],
				'FILE'        => $inc['file'] !== '' ? $inc['file'] . ':' . $inc['line'] : '',
				'SQL'         => $inc['sql'],
				'TRACE'       => $inc['trace'],
				'HINT'        => $is_recovery ? '' : $this->hint_text($inc),
				'REPEATS'     => (int) $inc['repeats'],
				'MAIL_STATE'  => $mail_state,
				'MAIL_TEXT'   => $this->language->lang('DBGUARDIAN_MAIL_' . strtoupper($mail_state)),
				'MAIL_ERROR'  => $mail_state === 'failed' ? substr($mail, 8) : '',
			]);
		}

		$base = $this->u_action . ($category !== '' ? '&amp;cat=' . $category : '');
		$this->pagination->generate_template_pagination($base, 'pagination', 'start', $total, $per_page, $start);

		foreach (array_merge(self::CATEGORIES, ['recovery']) as $cat)
		{
			$this->template->assign_block_vars('filters', [
				'VALUE'    => $cat,
				'LABEL'    => $this->language->lang('DBGUARDIAN_CAT_' . strtoupper($cat)),
				'SELECTED' => $cat === $category,
			]);
		}

		$this->template->assign_vars([
			'U_ACTION'     => $this->u_action,
			'TOTAL_EVENTS' => $total,
			'S_FILTER'     => $category !== '',
		]);
	}

	// ------------------------------------------------------------------
	// Badges and credits
	// ------------------------------------------------------------------

	protected function assign_badges()
	{
		global $phpbb_root_path;

		$meta = @json_decode((string) @file_get_contents($phpbb_root_path . 'ext/salvocortesiano/dbguardian/composer.json'), true);
		$meta = is_array($meta) ? $meta : [];
		$author = isset($meta['authors'][0]) ? $meta['authors'][0] : [];
		$file_version = isset($meta['version']) ? (string) $meta['version'] : '';
		$db_version = (string) $this->config['dbguardian_version'];
		$st = $this->manager->status();

		$this->template->assign_vars([
			'DBG_EXT_NAME'       => isset($meta['extra']['display-name']) ? $meta['extra']['display-name'] : 'DB Guardian',
			'DBG_EXT_VERSION'    => $file_version !== '' ? $file_version : $db_version,
			'DBG_EXT_MISMATCH'   => $file_version !== '' && $db_version !== '' && version_compare($file_version, $db_version, '>'),
			'DBG_DB_VERSION'     => $db_version,
			'DBG_PHPBB_VERSION'  => (string) $this->config['version'],
			'DBG_PHP_VERSION'    => PHP_VERSION,
			'DBG_LICENSE'        => isset($meta['license']) ? preg_replace('/-only$/', '', $meta['license']) : 'GPL-2.0',
			'DBG_BADGE_ACTIVE'   => $st['loaded'],
			'DBG_AUTHOR'         => isset($author['name']) ? $author['name'] : 'Salvo Cortesiano',
			'DBG_AUTHOR_URL'     => isset($author['homepage']) ? $author['homepage'] : 'https://netshadows.de',
			'DBG_SUPPORT'        => isset($author['email']) ? $author['email'] : 'info@netshadows.de',
		]);
	}
}
