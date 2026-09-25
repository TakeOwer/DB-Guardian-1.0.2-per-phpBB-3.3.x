<?php
/**
 *
 * DB Guardian. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano - https://netshadows.de
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\dbguardian\core;

/**
 * Deploys the runtime into store/dbguardian/, keeps its configuration file,
 * manages the auto_prepend_file block in .user.ini and reads the event log.
 *
 * The configuration lives in a PHP file and not in the database on purpose:
 * the guardian must work exactly when the database does not.
 */
class manager
{
	const BLOCK_BEGIN = '; BEGIN salvocortesiano/dbguardian - blocco gestito dall\'estensione, non modificare';
	const BLOCK_END = '; END salvocortesiano/dbguardian';
	const RUNTIME_FILES = ['guardian.php', 'guardian_core.php'];

	/** @var \phpbb\config\config */
	protected $config;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	public function __construct(\phpbb\config\config $config, $root_path, $php_ext)
	{
		$this->config = $config;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	// ------------------------------------------------------------------
	// Paths
	// ------------------------------------------------------------------

	public function board_root()
	{
		$real = realpath($this->root_path);
		return $real !== false ? rtrim($real, '/\\') : rtrim($this->root_path, '/\\');
	}

	public function store_dir()
	{
		return $this->board_root() . '/store/dbguardian';
	}

	public function source_dir()
	{
		return $this->board_root() . '/ext/salvocortesiano/dbguardian/runtime';
	}

	public function guardian_path()
	{
		return $this->store_dir() . '/guardian.php';
	}

	public function config_path()
	{
		return $this->store_dir() . '/config.php';
	}

	public function user_ini_path()
	{
		return $this->board_root() . '/.user.ini';
	}

	// ------------------------------------------------------------------
	// Configuration file
	// ------------------------------------------------------------------

	/**
	 * Settings suggested from the board configuration on first install.
	 */
	public function board_defaults()
	{
		$email = (string) $this->config['board_email'];
		$contact = (string) ($this->config['board_contact'] ?: $email);
		$url = function_exists('generate_board_url') ? generate_board_url() . '/' : '';

		$defaults = [
			'board_name'     => html_entity_decode((string) $this->config['sitename'], ENT_QUOTES, 'UTF-8'),
			'board_url'      => $url,
			'contact_email'  => $contact,
			'admin_emails'   => $email,
			'mail_from'      => $email,
			'mail_from_name' => html_entity_decode((string) $this->config['sitename'], ENT_QUOTES, 'UTF-8'),
			'debug_key'      => bin2hex(random_bytes(16)),
		];

		if (!empty($this->config['smtp_delivery']))
		{
			$defaults = array_merge($defaults, $this->phpbb_smtp());
		}
		return $defaults;
	}

	/**
	 * SMTP settings of phpBB converted to the guardian format.
	 */
	public function phpbb_smtp()
	{
		$host = trim((string) $this->config['smtp_host']);
		$port = (int) $this->config['smtp_port'];
		$security = 'none';
		if (preg_match('#^(ssl|tls)://#i', $host, $m))
		{
			$security = strtolower($m[1]) === 'ssl' ? 'ssl' : 'tls';
			$host = substr($host, strlen($m[0]));
		}
		else if ($port === 465)
		{
			$security = 'ssl';
		}
		else if ($port === 587)
		{
			$security = 'tls';
		}

		return [
			'mail_transport'   => 'smtp',
			'smtp_host'        => $host,
			'smtp_port'        => $port ?: 25,
			'smtp_security'    => $security,
			'smtp_user'        => (string) $this->config['smtp_username'],
			'smtp_pass'        => htmlspecialchars_decode((string) $this->config['smtp_password'], ENT_COMPAT),
			'smtp_verify_peer' => !empty($this->config['smtp_verify_peer']),
		];
	}

	public function load()
	{
		$this->load_core();
		$stored = null;
		if (is_file($this->config_path()))
		{
			$stored = @include $this->config_path();
		}
		if (!is_array($stored))
		{
			$stored = $this->board_defaults();
		}
		return \DbGuardianCore::normalize($stored);
	}

	public function save(array $cfg)
	{
		$this->ensure_store();
		$cfg = \DbGuardianCore::normalize($cfg);
		if (strlen((string) $cfg['debug_key']) < 16)
		{
			$cfg['debug_key'] = bin2hex(random_bytes(16));
		}
		if (strlen((string) $cfg['health_key']) < 16)
		{
			$cfg['health_key'] = bin2hex(random_bytes(16));
		}

		$php = "<?php\n// DB Guardian - configurazione generata dal pannello di amministrazione.\n"
			. "// Salvata in un file e non nel database: deve funzionare proprio quando il database non risponde.\n"
			. 'return ' . var_export($cfg, true) . ";\n";

		$file = $this->config_path();
		$tmp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, $php, LOCK_EX) === false || !@rename($tmp, $file))
		{
			@unlink($tmp);
			return false;
		}
		@chmod($file, 0640);
		$this->invalidate($file);
		return true;
	}

	// ------------------------------------------------------------------
	// Deploy
	// ------------------------------------------------------------------

	/**
	 * Copies the runtime and writes the configuration.
	 *
	 * @param bool|null $enabled true/false to switch the guardian, null to keep it
	 * @return string[] errors
	 */
	public function deploy($enabled = null)
	{
		$errors = [];
		if (!$this->ensure_store())
		{
			return ['STORE_NOT_WRITABLE'];
		}

		foreach (self::RUNTIME_FILES as $name)
		{
			$src = $this->source_dir() . '/' . $name;
			$dst = $this->store_dir() . '/' . $name;
			if (!is_file($src))
			{
				$errors[] = 'RUNTIME_SOURCE_MISSING';
				continue;
			}
			if (!is_file($dst) || md5_file($src) !== md5_file($dst))
			{
				$tmp = $dst . '.' . getmypid() . '.tmp';
				if (!@copy($src, $tmp) || !@rename($tmp, $dst))
				{
					@unlink($tmp);
					$errors[] = 'RUNTIME_COPY_FAILED';
					continue;
				}
				@chmod($dst, 0644);
				$this->invalidate($dst);
			}
		}

		$cfg = $this->load();
		if ($enabled !== null)
		{
			$cfg['enabled'] = (bool) $enabled;
		}
		if (!$this->save($cfg))
		{
			$errors[] = 'CONFIG_WRITE_FAILED';
		}
		return array_values(array_unique($errors));
	}

	public function deployed_version()
	{
		$file = $this->store_dir() . '/guardian_core.php';
		if (!is_file($file))
		{
			return '';
		}
		return preg_match("/const VERSION = '([^']+)'/", (string) @file_get_contents($file), $m) ? $m[1] : '';
	}

	public function runtime_outdated()
	{
		foreach (self::RUNTIME_FILES as $name)
		{
			$src = $this->source_dir() . '/' . $name;
			$dst = $this->store_dir() . '/' . $name;
			if (!is_file($dst) || (is_file($src) && md5_file($src) !== md5_file($dst)))
			{
				return true;
			}
		}
		return false;
	}

	protected function ensure_store()
	{
		$dir = $this->store_dir();
		foreach (['', '/state', '/logs'] as $sub)
		{
			if (!is_dir($dir . $sub) && !@mkdir($dir . $sub, 0755, true))
			{
				return false;
			}
		}
		$deny = "# DB Guardian: nessun accesso via web\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
		if (!is_file($dir . '/.htaccess'))
		{
			@file_put_contents($dir . '/.htaccess', $deny);
		}
		if (!is_file($dir . '/index.htm'))
		{
			@file_put_contents($dir . '/index.htm', '');
		}
		return is_writable($dir);
	}

	protected function invalidate($file)
	{
		if (function_exists('opcache_invalidate'))
		{
			@opcache_invalidate($file, true);
		}
	}

	// ------------------------------------------------------------------
	// .user.ini
	// ------------------------------------------------------------------

	/**
	 * @return array [bool success, string language key, string detail]
	 */
	public function install_prepend()
	{
		$ini = $this->user_ini_path();
		$current = is_file($ini) ? (string) @file_get_contents($ini) : '';
		$clean = $this->strip_block($current);

		if (preg_match('/^\s*auto_prepend_file\s*=\s*(.+)$/mi', $clean, $m))
		{
			return [false, 'PREPEND_CONFLICT', trim($m[1])];
		}

		// A prepend coming from php.ini keeps working: the guardian loads it after itself.
		$active = (string) ini_get('auto_prepend_file');
		$chain = '';
		if ($active !== '' && realpath($active) !== realpath($this->guardian_path()) && is_file($active))
		{
			$chain = realpath($active);
		}
		$cfg = $this->load();
		if ($cfg['chain_prepend'] !== $chain)
		{
			$cfg['chain_prepend'] = $chain;
			$this->save($cfg);
		}

		$block = self::BLOCK_BEGIN . "\n" . 'auto_prepend_file = "' . $this->guardian_path() . '"' . "\n" . self::BLOCK_END . "\n";
		$content = rtrim($clean) === '' ? $block : rtrim($clean) . "\n\n" . $block;

		if (!$this->write_ini($ini, $content))
		{
			return [false, 'USER_INI_NOT_WRITABLE', $ini];
		}
		return [true, 'PREPEND_INSTALLED', $ini];
	}

	/**
	 * @return array [bool success, string language key, string detail]
	 */
	public function remove_prepend()
	{
		$ini = $this->user_ini_path();
		if (!is_file($ini))
		{
			return [true, 'PREPEND_REMOVED', $ini];
		}
		$current = (string) @file_get_contents($ini);
		if (strpos($current, self::BLOCK_BEGIN) === false)
		{
			return [true, 'PREPEND_REMOVED', $ini];
		}
		$clean = rtrim($this->strip_block($current));
		$ok = $clean === '' ? @unlink($ini) : $this->write_ini($ini, $clean . "\n");
		return $ok ? [true, 'PREPEND_REMOVED', $ini] : [false, 'USER_INI_NOT_WRITABLE', $ini];
	}

	public function block_present()
	{
		$ini = $this->user_ini_path();
		return is_file($ini) && strpos((string) @file_get_contents($ini), self::BLOCK_BEGIN) !== false;
	}

	protected function strip_block($content)
	{
		$pattern = '/\n*' . preg_quote(self::BLOCK_BEGIN, '/') . '.*?' . preg_quote(self::BLOCK_END, '/') . '\n?/s';
		return (string) preg_replace($pattern, "\n", $content);
	}

	protected function write_ini($file, $content)
	{
		$tmp = $file . '.dbguardian.tmp';
		if (@file_put_contents($tmp, $content) === false)
		{
			return false;
		}
		@chmod($tmp, 0644);
		if (!@rename($tmp, $file))
		{
			@unlink($tmp);
			return false;
		}
		return true;
	}

	/**
	 * Everything the status page needs to explain whether the guardian is really running.
	 */
	public function status()
	{
		$sapi = PHP_SAPI;
		$ini_name = (string) ini_get('user_ini.filename');
		$active = (string) ini_get('auto_prepend_file');
		$loaded = defined('DBGUARDIAN_LOADED');
		$ours = $active !== '' && realpath($active) === realpath($this->guardian_path());

		return [
			'loaded'          => $loaded && $ours,
			'loaded_version'  => $loaded ? (string) constant('DBGUARDIAN_LOADED') : '',
			'sapi'            => $sapi,
			'sapi_ok'         => strpos($sapi, 'cgi') !== false || strpos($sapi, 'fpm') !== false || strpos($sapi, 'litespeed') !== false,
			'sapi_lsapi'      => strpos($sapi, 'litespeed') !== false,
			'user_ini_name'   => $ini_name,
			'cache_ttl'       => (int) ini_get('user_ini.cache_ttl'),
			'active_prepend'  => $active,
			'ours'            => $ours,
			'block_present'   => $this->block_present(),
			'ini_path'        => $this->user_ini_path(),
			'ini_writable'    => is_file($this->user_ini_path()) ? is_writable($this->user_ini_path()) : is_writable($this->board_root()),
			'store_dir'       => $this->store_dir(),
			'store_writable'  => is_dir($this->store_dir()) && is_writable($this->store_dir()),
			'deployed'        => $this->deployed_version(),
			'outdated'        => $this->runtime_outdated(),
			'guardian_path'   => $this->guardian_path(),
			'mail_available'  => function_exists('mail'),
		];
	}

	// ------------------------------------------------------------------
	// Preview and test e-mail
	// ------------------------------------------------------------------

	public function load_core()
	{
		if (class_exists('DbGuardianCore', false))
		{
			return true;
		}
		foreach ([$this->store_dir(), $this->source_dir()] as $dir)
		{
			if (is_file($dir . '/guardian_core.php'))
			{
				require_once $dir . '/guardian_core.php';
				return class_exists('DbGuardianCore', false);
			}
		}
		return false;
	}

	public function preview($category, $lang)
	{
		$cfg = $this->load();
		\DbGuardianCore::configure($this->store_dir(), $cfg);
		$inc = \DbGuardianCore::sample_incident($category);
		$inc['url'] = $cfg['board_url'] !== '' ? $cfg['board_url'] : (function_exists('generate_board_url') ? generate_board_url() . '/' : '/');
		return \DbGuardianCore::render_page($inc, $lang === 'en' ? 'en' : 'it');
	}

	/**
	 * @return array [bool, string error]
	 */
	public function send_test_mail()
	{
		$cfg = $this->load();
		\DbGuardianCore::configure($this->store_dir(), $cfg);
		$to = \DbGuardianCore::recipients();
		$board = $cfg['board_name'];
		$text = "Questa è un'e-mail di prova di DB Guardian per il forum $board.\n\n"
			. "Se la stai leggendo, gli avvisi automatici arriveranno anche quando il database del forum non risponde.\n\n"
			. 'Metodo di invio: ' . ($cfg['mail_transport'] === 'smtp' ? 'SMTP ' . $cfg['smtp_host'] . ':' . $cfg['smtp_port'] : 'funzione mail() di PHP') . "\n"
			. 'Data e ora: ' . \DbGuardianCore::format_time(time()) . "\n";
		$html = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;color:#1f2433">'
			. nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>';
		return \DbGuardianCore::send_mail($to, '[' . $board . '] E-mail di prova di DB Guardian', $text, $html);
	}

	// ------------------------------------------------------------------
	// External monitoring (health endpoint + watchdog)
	// ------------------------------------------------------------------

	public function watchdog_defaults()
	{
		return [
			'interval'        => 1,
			'threshold'       => 2,
			'timeout'         => 20,
			'realert_minutes' => 60,
			'ssl_warn_days'   => 14,
			'recipients'      => '',
			'smtp_host'       => '',
			'smtp_port'       => 465,
			'smtp_security'   => 'ssl',
			'smtp_user'       => '',
			'smtp_pass'       => '',
			'smtp_from'       => '',
			'smtp_from_name'  => 'DB Guardian watchdog',
			'smtp_verify'     => true,
			'telegram_token'  => '',
			'telegram_chat'   => '',
		];
	}

	public function watchdog_settings(array $cfg)
	{
		return array_merge($this->watchdog_defaults(), is_array($cfg['watchdog']) ? $cfg['watchdog'] : []);
	}

	/**
	 * Public address of the endpoint, built from this installation.
	 */
	public function health_url(array $cfg)
	{
		$base = function_exists('generate_board_url') ? generate_board_url() : rtrim((string) $cfg['board_url'], '/');
		return $base . '/index.php?dbguardian_health=' . rawurlencode((string) $cfg['health_key']);
	}

	/**
	 * The same check the endpoint runs, executed right now from the ACP.
	 */
	public function health_probe()
	{
		$cfg = $this->load();
		if (!method_exists('DbGuardianCore', 'health_data'))
		{
			// The previous version of the guardian is still loaded in this request.
			return ['ok' => false, 'db' => 'skip', 'db_ms' => 0, 'db_code' => 0, 'db_error' => 'RELOAD'];
		}
		\DbGuardianCore::configure($this->store_dir(), $cfg);
		return \DbGuardianCore::health_data();
	}

	/**
	 * Last request received on the endpoint (normally the watchdog).
	 */
	public function last_health_request()
	{
		$raw = @file_get_contents($this->store_dir() . '/state/health.json');
		$data = is_string($raw) ? json_decode($raw, true) : null;
		return is_array($data) ? $data : [];
	}

	/**
	 * The watchdog script with its settings filled in.
	 */
	public function watchdog_script(array $cfg)
	{
		$template = @file_get_contents($this->board_root() . '/ext/salvocortesiano/dbguardian/watchdog/dbguardian_watchdog.py');
		if (!is_string($template) || strpos($template, '__DBGUARDIAN_CONFIG__') === false)
		{
			return false;
		}
		$w = $this->watchdog_settings($cfg);
		$recipients = preg_split('/[\s,;]+/', (string) $w['recipients'], -1, PREG_SPLIT_NO_EMPTY);

		$settings = [
			'board_name'      => (string) $cfg['board_name'],
			'health_url'      => $this->health_url($cfg),
			'timezone'        => (string) $cfg['timezone'],
			'threshold'       => (int) $w['threshold'],
			'timeout'         => (int) $w['timeout'],
			'realert_minutes' => (int) $w['realert_minutes'],
			'ssl_warn_days'   => (int) $w['ssl_warn_days'],
			'recipients'      => array_values($recipients),
			'smtp'            => [
				'host'      => (string) $w['smtp_host'],
				'port'      => (int) $w['smtp_port'],
				'security'  => (string) $w['smtp_security'],
				'user'      => (string) $w['smtp_user'],
				'pass'      => (string) $w['smtp_pass'],
				'from'      => (string) $w['smtp_from'],
				'from_name' => (string) $w['smtp_from_name'],
				'verify'    => (bool) $w['smtp_verify'],
			],
			'telegram'        => [
				'token'   => (string) $w['telegram_token'],
				'chat_id' => (string) $w['telegram_chat'],
			],
		];

		// No apostrophes in the JSON (JSON_HEX_APOS): it sits inside a Python r'''...''' string.
		$json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS);
		return str_replace('__DBGUARDIAN_CONFIG__', $json, $template);
	}

	public function cron_line(array $cfg, $path)
	{
		$interval = (int) $this->watchdog_settings($cfg)['interval'];
		$when = $interval <= 1 ? '* * * * *' : '*/' . $interval . ' * * * *';
		return $when . ' /usr/bin/python3 ' . $path . ' >/dev/null 2>&1';
	}

	// ------------------------------------------------------------------
	// Event log
	// ------------------------------------------------------------------

	protected function log_files()
	{
		$files = glob($this->store_dir() . '/logs/*.log');
		if (!is_array($files))
		{
			return [];
		}
		rsort($files);
		return $files;
	}

	/**
	 * @return array [array rows newest first, int total]
	 */
	public function read_log($start, $limit, $category = '')
	{
		$rows = [];
		foreach ($this->log_files() as $file)
		{
			$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			if (!is_array($lines))
			{
				continue;
			}
			for ($i = count($lines) - 1; $i >= 0; $i--)
			{
				$row = json_decode($lines[$i], true);
				if (!is_array($row) || ($category !== '' && (isset($row['category']) ? $row['category'] : '') !== $category))
				{
					continue;
				}
				$rows[] = $row;
			}
		}
		usort($rows, function ($a, $b)
		{
			return (int) (isset($b['time']) ? $b['time'] : 0) - (int) (isset($a['time']) ? $a['time'] : 0);
		});
		return [array_slice($rows, $start, $limit), count($rows)];
	}

	public function log_summary()
	{
		$summary = ['total' => 0, 'last' => 0, 'last_30' => 0, 'down_open' => is_file($this->store_dir() . '/state/down.flag')];
		$limit = time() - 30 * 86400;
		foreach ($this->log_files() as $file)
		{
			$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			foreach (is_array($lines) ? $lines : [] as $line)
			{
				$row = json_decode($line, true);
				if (!is_array($row) || (isset($row['category']) && $row['category'] === 'recovery'))
				{
					continue;
				}
				$t = (int) (isset($row['time']) ? $row['time'] : 0);
				$n = 1 + (int) (isset($row['repeats']) ? $row['repeats'] : 0);
				$summary['total'] += $n;
				$summary['last'] = max($summary['last'], $t);
				if ($t >= $limit)
				{
					$summary['last_30'] += $n;
				}
			}
		}
		return $summary;
	}

	public function clear_log()
	{
		foreach ($this->log_files() as $file)
		{
			@unlink($file);
		}
	}

	public function reset_state()
	{
		foreach (['state.json', 'down.flag'] as $name)
		{
			@unlink($this->store_dir() . '/state/' . $name);
		}
	}

	public function prune_logs($months)
	{
		$months = max(1, (int) $months);
		$limit = date('Y-m', strtotime('-' . $months . ' months'));
		foreach ($this->log_files() as $file)
		{
			if (basename($file, '.log') < $limit)
			{
				@unlink($file);
			}
		}
	}
}
