<?php
/**
 *
 * DB Guardian. An extension for the phpBB Forum Software package.
 *
 * Runtime engine: intercepts phpBB "General Error" pages, uncaught exceptions
 * and PHP fatal errors, replaces them with a service page, keeps a log and
 * alerts the administrators by e-mail. It never touches the database.
 *
 * This file is copied by the extension into store/dbguardian/. Do not edit the
 * copy: it is overwritten whenever the settings are saved in the ACP.
 *
 * @copyright (c) 2026 Salvo Cortesiano - https://netshadows.de
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!class_exists('DbGuardianCore', false))
{

final class DbGuardianCore
{
	const VERSION = '1.0.1';
	const CHUNK = 1048576;

	/** MySQL/MariaDB error codes meaning "the database cannot be reached". */
	const CONNECT_CODES = [1040, 1044, 1045, 1049, 1053, 1129, 1130, 1203, 1226, 2002, 2003, 2005, 2006, 2013, 2054];

	private static $dir = '';
	private static $cfg = [];
	private static $level = 0;
	private static $incident = null;
	private static $done = false;
	private static $booted = false;
	private static $recovery_checked = false;
	private static $reserve = null;

	/**
	 * Copies of $_SERVER, $_GET and $_COOKIE taken before phpBB starts. phpBB replaces the
	 * superglobals with objects that raise a "General Error" on every read, so the guardian
	 * must never touch them after boot.
	 */
	private static $server = [];
	private static $query = [];
	private static $cookies = [];

	// ------------------------------------------------------------------
	// Configuration
	// ------------------------------------------------------------------

	public static function defaults()
	{
		return [
			'enabled'          => true,
			'board_name'       => 'phpBB',
			'board_url'        => '',
			'contact_email'    => '',
			'admin_emails'     => '',
			'intercept'        => 'all',
			'catch_fatal'      => true,
			'notify'           => ['db_connect', 'db_query', 'php_fatal'],
			'notify_recovery'  => true,
			'throttle_minutes' => 30,
			'retry_seconds'    => 60,
			'status_url'       => '',
			'logo_url'         => '',
			'accent_color'     => '#22577a',
			'custom_message'   => '',
			'page_language'    => 'auto',
			'timezone'         => 'Europe/Rome',
			'exclude_paths'    => ['/install/'],
			'debug_key'        => '',
			'mail_transport'   => 'mail',
			'mail_from'        => '',
			'mail_from_name'   => 'DB Guardian',
			'mail_f_param'     => false,
			'smtp_host'        => '',
			'smtp_port'        => 587,
			'smtp_security'    => 'tls',
			'smtp_user'        => '',
			'smtp_pass'        => '',
			'smtp_verify_peer' => true,
			'chain_prepend'    => '',
			'log_months'       => 6,
		];
	}

	public static function normalize(array $cfg)
	{
		$cfg = array_merge(self::defaults(), $cfg);
		$cfg['notify'] = is_array($cfg['notify']) ? $cfg['notify'] : [];
		$cfg['exclude_paths'] = is_array($cfg['exclude_paths']) ? $cfg['exclude_paths'] : [];
		$cfg['throttle_minutes'] = max(1, (int) $cfg['throttle_minutes']);
		$cfg['retry_seconds'] = max(0, (int) $cfg['retry_seconds']);
		$cfg['smtp_port'] = (int) $cfg['smtp_port'];
		if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $cfg['accent_color']))
		{
			$cfg['accent_color'] = '#22577a';
		}
		return $cfg;
	}

	// ------------------------------------------------------------------
	// Boot and handlers
	// ------------------------------------------------------------------

	public static function boot($dir, array $cfg)
	{
		if (self::$booted)
		{
			return;
		}
		self::$booted = true;
		self::$dir = rtrim($dir, '/\\');
		self::$cfg = self::normalize($cfg);
		self::$server = is_array($_SERVER) ? $_SERVER : [];
		self::$query = is_array($_GET) ? $_GET : [];
		self::$cookies = is_array($_COOKIE) ? $_COOKIE : [];

		$script = self::srv('SCRIPT_NAME');
		foreach (self::$cfg['exclude_paths'] as $path)
		{
			if ($path !== '' && strpos($script, (string) $path) !== false)
			{
				return;
			}
		}

		// Memory kept aside so that the page can still be built after "Allowed memory size exhausted".
		self::$reserve = str_repeat(' ', 131072);

		ob_start([__CLASS__, 'output_handler'], self::CHUNK);
		self::$level = ob_get_level();

		if (!empty(self::$cfg['catch_fatal']))
		{
			set_exception_handler([__CLASS__, 'on_exception']);

			// PHP would print fatal errors straight to the visitor, before the service page.
			// They still reach the server error log, and the details are shown through the debug cookie.
			@ini_set('display_errors', '0');
		}
		register_shutdown_function([__CLASS__, 'on_shutdown']);

		// Live check from the outside: /?dbguardian_test=KEY[&cat=db_query]
		$test = isset(self::$query['dbguardian_test']) && is_string(self::$query['dbguardian_test']) ? self::$query['dbguardian_test'] : '';
		if ($test !== '' && self::is_key($test))
		{
			$cat = isset(self::$query['cat']) && is_string(self::$query['cat']) ? self::$query['cat'] : 'db_connect';
			self::$incident = self::sample_incident($cat);
			self::prepare_response();
			exit;
		}
	}

	public static function on_exception($e)
	{
		try
		{
			@error_log('PHP Fatal error:  Uncaught ' . $e);
			if (self::$incident === null)
			{
				self::$incident = self::from_throwable($e);
			}
			self::prepare_response();
		}
		catch (\Throwable $x)
		{
			// Nothing else can be done safely.
		}
	}

	public static function on_shutdown()
	{
		self::$reserve = null;
		try
		{
			if (self::$done)
			{
				return;
			}

			if (self::$incident === null)
			{
				$err = error_get_last();
				$fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
				if (empty(self::$cfg['catch_fatal']) || !is_array($err) || !in_array($err['type'], $fatal, true))
				{
					return;
				}
				self::$incident = self::from_fatal($err);
				self::prepare_response();
			}

			// Our buffer was removed by the application: print the page directly.
			if (ob_get_level() < self::$level)
			{
				echo self::finalize();
			}
		}
		catch (\Throwable $x)
		{
		}
	}

	public static function output_handler($buffer, $phase)
	{
		try
		{
			if (self::$done)
			{
				return '';
			}
			if ($phase & PHP_OUTPUT_HANDLER_CLEAN)
			{
				return $buffer;
			}

			if (self::$incident === null && is_string($buffer) && $buffer !== '')
			{
				$found = self::detect_error_page($buffer);
				if ($found !== null)
				{
					self::$incident = $found;
				}
			}

			if (self::$incident !== null)
			{
				return self::finalize();
			}

			if (($phase & PHP_OUTPUT_HANDLER_FINAL) && !self::$recovery_checked)
			{
				self::$recovery_checked = true;
				self::check_recovery();
			}
		}
		catch (\Throwable $e)
		{
		}

		return $buffer;
	}

	private static function prepare_response()
	{
		while (ob_get_level() > self::$level)
		{
			if (!@ob_end_clean())
			{
				break;
			}
		}
		if (ob_get_level() === self::$level)
		{
			@ob_clean();
		}
		self::send_headers();
	}

	private static function send_headers()
	{
		if (headers_sent())
		{
			return;
		}
		@header_remove('Content-Encoding');
		@header_remove('Content-Length');
		@header_remove('Content-Disposition');
		// A full status line: after a fatal error PHP has already set "500 Internal Server Error".
		$proto = preg_match('#^HTTP/\d(\.\d)?$#', self::srv('SERVER_PROTOCOL')) ? self::srv('SERVER_PROTOCOL') : 'HTTP/1.1';
		header($proto . ' 503 Service Unavailable', true, 503);
		header('Content-Type: text/html; charset=UTF-8');
		header('Retry-After: ' . max(30, (int) self::$cfg['retry_seconds']));
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('X-Robots-Tag: noindex, nofollow');
	}

	private static function finalize()
	{
		if (self::$done)
		{
			return '';
		}
		self::$done = true;

		$inc = self::$incident;
		if (empty($inc['test']))
		{
			try
			{
				$inc = self::record($inc);
			}
			catch (\Throwable $e)
			{
			}
		}
		self::send_headers();

		return self::render_page($inc);
	}

	// ------------------------------------------------------------------
	// Classification
	// ------------------------------------------------------------------

	private static function detect_error_page($buffer)
	{
		$html = $buffer;
		if (strncmp($buffer, "\x1f\x8b", 2) === 0)
		{
			if (strlen($buffer) > 524288 || !function_exists('gzdecode'))
			{
				return null;
			}
			$html = @gzdecode($buffer);
			if (!is_string($html))
			{
				return null;
			}
		}

		if (strpos($html, '<body id="errorpage">') === false)
		{
			return null;
		}

		$title = preg_match('#<h1>(.*?)</h1>#s', $html, $m) ? self::plain($m[1]) : 'General Error';
		$start = strpos($html, '</h1>');
		$end = strpos($html, '<div id="page-footer">');
		$body = ($start !== false && $end !== false && $end > $start) ? substr($html, $start + 5, $end - $start - 5) : '';

		$inc = self::from_phpbb_message($title, $body);
		if (self::$cfg['intercept'] === 'db' && strpos($inc['category'], 'db_') !== 0)
		{
			return null;
		}
		return $inc;
	}

	public static function from_phpbb_message($title, $body_html)
	{
		$trace = '';
		$sql = '';
		$pos = stripos($body_html, 'BACKTRACE');
		if ($pos !== false)
		{
			$trace = self::plain(substr($body_html, $pos + 9));
			$body_html = substr($body_html, 0, $pos);
		}

		$inc = self::blank('app');
		$inc['title'] = $title;
		$inc['source'] = 'phpbb';

		if (preg_match('#SQL ERROR \[\s*([^\]]*?)\s*\](?:\s|<br\s*/?>)*(.*?)\s\[(\d*)\]#si', $body_html, $m))
		{
			$code = $m[3] === '' ? 0 : (int) $m[3];
			$message = self::plain($m[2]);
			if (preg_match('#<br\s*/?>\s*<br\s*/?>\s*SQL\s*<br\s*/?>\s*<br\s*/?>(.*)$#si', $body_html, $s))
			{
				$sql = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $s[1])), ENT_QUOTES, 'UTF-8'));
			}
			$inc['category'] = self::is_connect_error($code, $message) ? 'db_connect' : 'db_query';
			$inc['code'] = $code;
			$inc['message'] = $message;
			$inc['driver'] = self::plain($m[1]);
		}
		else
		{
			$message = self::plain($body_html);
			$message = trim(str_ireplace(['Please notify the board administrator or webmaster:'], '', $message));
			$inc['category'] = self::is_connect_error(0, $message) ? 'db_connect' : 'app';
			$inc['message'] = $message;
		}

		$inc['sql'] = self::cut($sql, 0, 4000);
		$inc['trace'] = self::cut($trace, 0, 4000);
		return $inc;
	}

	public static function from_throwable($e)
	{
		$class = get_class($e);
		$code = $e->getCode();
		$message = (string) $e->getMessage();
		$trace = $e->getTraceAsString();
		$is_db = ($e instanceof \mysqli_sql_exception) || ($e instanceof \PDOException) || stripos($class, 'Doctrine\\DBAL') !== false;

		if ($e instanceof \PDOException && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1]))
		{
			$code = (int) $e->errorInfo[1];
		}
		$code = is_numeric($code) ? (int) $code : 0;

		$inc = self::blank('php_fatal');
		$inc['source'] = 'exception';
		$inc['title'] = $class;
		$inc['message'] = $message;
		$inc['file'] = self::short_path($e->getFile());
		$inc['line'] = (int) $e->getLine();
		$inc['trace'] = self::cut(self::short_path($trace), 0, 4000);

		if ($is_db)
		{
			$connecting = strpos($trace, 'mysqli_real_connect') !== false || strpos($trace, 'sql_connect') !== false || strpos($trace, 'PDO->__construct') !== false;
			$inc['category'] = ($connecting || self::is_connect_error($code, $message)) ? 'db_connect' : 'db_query';
			$inc['code'] = $code;
		}
		return $inc;
	}

	public static function from_fatal(array $err)
	{
		$inc = self::blank('php_fatal');
		$inc['source'] = 'fatal';
		$inc['title'] = 'PHP Fatal error';
		$inc['message'] = self::short_path((string) $err['message']);
		$inc['file'] = self::short_path((string) $err['file']);
		$inc['line'] = (int) $err['line'];

		if (stripos($inc['message'], 'mysqli_sql_exception') !== false || stripos($inc['message'], 'PDOException') !== false)
		{
			$inc['category'] = self::is_connect_error(0, $inc['message']) || stripos($inc['message'], 'connect') !== false ? 'db_connect' : 'db_query';
		}
		return $inc;
	}

	public static function is_connect_error($code, $message)
	{
		if ($code && in_array((int) $code, self::CONNECT_CODES, true))
		{
			return true;
		}
		$patterns = ['Failed to establish a connection', 'Could not connect', 'mysqli_connect function does not exist',
			'Failed to initialize MySQLi', 'Connection refused', 'Too many connections', 'max_user_connections',
			'Access denied for user', 'Unknown database', 'MySQL server has gone away', 'Lost connection to MySQL',
			'php_network_getaddresses', 'No such file or directory'];
		foreach ($patterns as $p)
		{
			if (stripos($message, $p) !== false)
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Groups an incident into a cause that can be explained to a visitor.
	 */
	public static function cause(array $inc)
	{
		$c = (int) $inc['code'];
		$m = (string) $inc['message'];
		if ($inc['category'] === 'php_fatal')
		{
			if (stripos($m, 'Allowed memory size') !== false)
			{
				return 'memory';
			}
			if (stripos($m, 'Maximum execution time') !== false)
			{
				return 'timeout';
			}
			return 'php';
		}
		if ($inc['category'] === 'app')
		{
			return 'app';
		}
		if (in_array($c, [1040, 1203, 1226, 1129], true) || stripos($m, 'Too many connections') !== false || stripos($m, 'max_user_connections') !== false)
		{
			return 'overload';
		}
		if (in_array($c, [2002, 2003, 2005, 2006, 2013, 1053, 2054], true) || stripos($m, 'gone away') !== false || stripos($m, 'Connection refused') !== false)
		{
			return 'unreachable';
		}
		if (in_array($c, [1044, 1045, 1049, 1130], true) || stripos($m, 'Access denied') !== false)
		{
			return 'access';
		}
		if (in_array($c, [1146, 1054], true))
		{
			return 'missing';
		}
		if (in_array($c, [126, 127, 144, 145, 1034, 1035, 1194, 1195], true))
		{
			return 'damaged';
		}
		if (in_array($c, [1205, 1213], true))
		{
			return 'busy';
		}
		if (in_array($c, [28, 1021, 1030, 1114], true))
		{
			return 'space';
		}
		return $inc['category'] === 'db_connect' ? 'unreachable' : 'db';
	}

	public static function error_code(array $inc)
	{
		$code = (int) $inc['code'];
		switch ($inc['category'])
		{
			case 'db_connect':
				return $code ? 'DB-' . $code : 'DB-CONN';
			case 'db_query':
				return $code ? 'SQL-' . $code : 'SQL-ERR';
			case 'php_fatal':
				$cause = self::cause($inc);
				return $cause === 'memory' ? 'PHP-MEMORY' : ($cause === 'timeout' ? 'PHP-TIMEOUT' : 'PHP-FATAL');
			default:
				return 'PHPBB-GENERAL';
		}
	}

	private static function blank($category)
	{
		return [
			'category' => $category, 'code' => 0, 'title' => '', 'message' => '', 'driver' => '', 'sql' => '',
			'file' => '', 'line' => 0, 'trace' => '', 'source' => '', 'test' => false,
			'ref' => '', 'time' => time(), 'notified' => false, 'mail' => 'off',
		];
	}

	public static function sample_incident($category)
	{
		$samples = [
			'db_connect' => [1203, "User 'forum_user' has exceeded the 'max_user_connections' resource (current value: 20)"],
			'db_query'   => [1146, "Table 'forum.phpbb_example' doesn't exist"],
			'php_fatal'  => [0, 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)'],
			'app'        => [0, 'NO_STYLE_DATA'],
		];
		$category = isset($samples[$category]) ? $category : 'db_connect';
		$inc = self::blank($category);
		$inc['code'] = $samples[$category][0];
		$inc['message'] = $samples[$category][1];
		$inc['title'] = 'Test';
		$inc['source'] = 'test';
		$inc['test'] = true;
		$inc['ref'] = 'TEST-' . date('ymd');
		$inc['notified'] = true;
		return $inc;
	}

	// ------------------------------------------------------------------
	// Recording, throttling and alerts
	// ------------------------------------------------------------------

	private static function record(array $inc)
	{
		$now = time();
		$inc['time'] = $now;
		$inc['ref'] = date('ymd', $now) . '-' . strtoupper(bin2hex(random_bytes(3)));
		$inc['url'] = self::current_url();
		$inc['method'] = substr(self::srv('REQUEST_METHOD'), 0, 10);
		$inc['ip'] = self::masked_ip();
		$inc['ua'] = self::cut(self::srv('HTTP_USER_AGENT'), 0, 200);

		$sig = $inc['category'] . '|' . $inc['code'];
		$notify = in_array($inc['category'], self::$cfg['notify'], true) && self::recipients() !== [];
		$window = self::$cfg['throttle_minutes'] * 60;
		$send = false;
		$down = null;

		$lock = self::lock();
		$state = self::read_state();
		$s = isset($state['sig'][$sig]) ? $state['sig'][$sig] : ['last_log' => 0, 'repeats' => 0, 'last_mail' => 0, 'mail_ok' => false, 'suppressed' => 0];

		$write_log = ($now - (int) $s['last_log']) >= 60;
		$inc['repeats'] = 0;
		if ($write_log)
		{
			$inc['repeats'] = (int) $s['repeats'];
			$s['repeats'] = 0;
			$s['last_log'] = $now;
		}
		else
		{
			$s['repeats']++;
		}

		if ($inc['category'] === 'db_connect')
		{
			if (empty($state['down']))
			{
				$state['down'] = ['since' => $now, 'count' => 0, 'alerted' => false, 'ref' => $inc['ref'], 'code' => $inc['code']];
			}
			$state['down']['count']++;
			$state['down']['last'] = $now;
			$down = $state['down'];
			@touch(self::$dir . '/state/down.flag');
		}

		$inc['suppressed'] = 0;
		if ($notify)
		{
			// After a failed delivery try again sooner (at most every 5 minutes).
			if (empty($s['mail_ok']) && (int) $s['last_mail'] > 0)
			{
				$window = min($window, 300);
			}
			if (($now - (int) $s['last_mail']) >= $window)
			{
				$send = true;
				$inc['suppressed'] = (int) $s['suppressed'];
				$s['suppressed'] = 0;
				$s['last_mail'] = $now;
			}
			else
			{
				$s['suppressed']++;
			}
		}

		$state['sig'][$sig] = $s;
		self::write_state($state);
		self::unlock($lock);

		if ($send)
		{
			$result = self::send_alert($inc, $down);
			$inc['mail'] = $result[0] ? 'sent' : 'failed: ' . $result[1];
			$inc['notified'] = $result[0];

			$lock = self::lock();
			$state = self::read_state();
			if (isset($state['sig'][$sig]))
			{
				$state['sig'][$sig]['mail_ok'] = $result[0];
			}
			if ($result[0] && $inc['category'] === 'db_connect' && !empty($state['down']))
			{
				$state['down']['alerted'] = true;
			}
			self::write_state($state);
			self::unlock($lock);
			$write_log = true;
		}
		else
		{
			$inc['mail'] = $notify ? 'throttled' : 'off';
			$inc['notified'] = $notify && !empty($s['mail_ok']);
		}

		if ($write_log)
		{
			self::log($inc);
		}
		return $inc;
	}

	private static function check_recovery()
	{
		$flag = self::$dir . '/state/down.flag';
		if (!is_file($flag))
		{
			return;
		}
		$code = http_response_code();
		if (!is_int($code) || $code >= 400)
		{
			return;
		}
		$claim = $flag . '.' . getmypid() . '.' . mt_rand();
		if (!@rename($flag, $claim))
		{
			return;
		}
		@unlink($claim);

		$lock = self::lock();
		$state = self::read_state();
		$down = isset($state['down']) ? $state['down'] : null;
		unset($state['down']);
		self::write_state($state);
		self::unlock($lock);

		if (!is_array($down))
		{
			return;
		}

		$now = time();
		$entry = self::blank('recovery');
		$entry['time'] = $now;
		$entry['ref'] = (string) $down['ref'];
		$entry['code'] = (int) $down['code'];
		$entry['title'] = 'Recovery';
		$entry['message'] = sprintf('Fuori servizio dal %s per %s, %d richieste fallite', self::format_time((int) $down['since']), self::duration($now - (int) $down['since']), (int) $down['count']);
		$entry['url'] = self::current_url();
		$entry['mail'] = 'off';

		if (!empty(self::$cfg['notify_recovery']) && !empty($down['alerted']))
		{
			$r = self::send_recovery($down, $now);
			$entry['mail'] = $r[0] ? 'sent' : 'failed: ' . $r[1];
		}
		self::log($entry);
	}

	private static function send_alert(array $inc, $down)
	{
		$board = self::$cfg['board_name'];
		$code = self::error_code($inc);
		$subjects = [
			'db_connect' => 'Forum non raggiungibile: database non disponibile (%s)',
			'db_query'   => 'Errore del database sul forum (%s)',
			'php_fatal'  => 'Errore fatale PHP sul forum (%s)',
			'app'        => 'Errore generale sul forum (%s)',
		];
		$subject = '[' . $board . '] ' . sprintf(isset($subjects[$inc['category']]) ? $subjects[$inc['category']] : $subjects['app'], $code);

		$rows = [
			'Codice'        => $code,
			'Riferimento'   => $inc['ref'],
			'Data e ora'    => self::format_time($inc['time']),
			'Tipo'          => self::category_label($inc['category']),
			'Messaggio'     => $inc['message'],
			'Pagina'        => $inc['method'] . ' ' . $inc['url'],
			'Visitatore'    => $inc['ip'] . ($inc['ua'] !== '' ? ' - ' . $inc['ua'] : ''),
			'Server'        => (function_exists('gethostname') ? gethostname() : '') . ' - PHP ' . PHP_VERSION,
		];
		if ($inc['file'] !== '')
		{
			$rows['File'] = $inc['file'] . ':' . $inc['line'];
		}
		if (!empty($inc['suppressed']))
		{
			$rows['Ripetizioni'] = sprintf('%d errori simili dopo l\'ultimo avviso', $inc['suppressed']);
		}
		if (is_array($down))
		{
			$rows['Fuori servizio da'] = self::format_time((int) $down['since']) . ' (' . self::duration(time() - (int) $down['since']) . ', ' . (int) $down['count'] . ' richieste fallite)';
		}

		$intro = 'DB Guardian ha intercettato un errore sul forum ' . $board . '.';
		$hint = self::admin_hint($inc);
		$extra = '';
		if ($inc['sql'] !== '')
		{
			$extra .= "SQL:\n" . $inc['sql'] . "\n\n";
		}
		if ($inc['trace'] !== '')
		{
			$extra .= "Traccia:\n" . $inc['trace'] . "\n\n";
		}
		$outro = sprintf('Il prossimo avviso per questo stesso errore partirà solo dopo %d minuti.', self::$cfg['throttle_minutes']);
		if ($inc['category'] === 'db_connect' && !empty(self::$cfg['notify_recovery']))
		{
			$outro .= ' Riceverai un\'e-mail appena il forum tornerà raggiungibile.';
		}

		return self::send_mail(self::recipients(), $subject, self::mail_text($intro, $rows, $hint, $extra, $outro), self::mail_html($intro, $rows, $hint, $extra, $outro, '#b54708'));
	}

	private static function send_recovery(array $down, $now)
	{
		$board = self::$cfg['board_name'];
		$subject = '[' . $board . '] Forum di nuovo raggiungibile';
		$rows = [
			'Tornato online'     => self::format_time($now),
			'Fuori servizio da'  => self::format_time((int) $down['since']),
			'Durata'             => self::duration($now - (int) $down['since']),
			'Richieste fallite'  => (string) (int) $down['count'],
			'Primo riferimento'  => (string) $down['ref'],
			'Primo codice'       => (int) $down['code'] ? 'DB-' . (int) $down['code'] : 'DB-CONN',
		];
		$intro = 'Il forum ' . $board . ' risponde di nuovo correttamente: il collegamento al database è stato ripristinato.';
		$outro = 'Il riepilogo completo è nel registro eventi di DB Guardian, nel pannello di amministrazione.';
		return self::send_mail(self::recipients(), $subject, self::mail_text($intro, $rows, '', '', $outro), self::mail_html($intro, $rows, '', '', $outro, '#1f7a4d'));
	}

	public static function admin_hint(array $inc)
	{
		$c = (int) $inc['code'];
		$hints = [
			1040 => 'Il server MySQL ha raggiunto max_connections: traffico eccessivo, bot aggressivi o connessioni che restano aperte.',
			1203 => 'Superato il limite max_user_connections dell\'utente del database: è un limite del piano di hosting. Contatta il provider se si ripete.',
			1226 => 'L\'utente del database ha esaurito una risorsa (max_questions, max_updates o max_connections_per_hour) imposta dal provider.',
			1129 => 'L\'host è stato bloccato da MySQL per troppi errori di connessione: serve un FLUSH HOSTS da parte del provider.',
			1044 => 'L\'utente del database non ha i permessi sul database indicato in config.php.',
			1045 => 'Credenziali rifiutate: controlla utente e password in config.php o se la password del database è stata cambiata.',
			1049 => 'Il database indicato in config.php non esiste.',
			1130 => 'L\'host del forum non è autorizzato a collegarsi al server MySQL.',
			1053 => 'Il server MySQL è in fase di arresto o di riavvio.',
			2002 => 'Il server MySQL non è attivo o il socket locale non è raggiungibile.',
			2003 => 'Il server MySQL non risponde sulla porta configurata.',
			2005 => 'Il nome host del database in config.php non viene risolto.',
			2006 => 'La connessione è caduta (MySQL server has gone away): timeout, riavvio o pacchetto troppo grande.',
			2013 => 'Connessione persa durante la richiesta: riavvio o sovraccarico del server MySQL.',
			1146 => 'Tabella mancante: aggiornamento o migrazione di un\'estensione interrotta, o estensione rimossa senza disinstallarla.',
			1054 => 'Colonna sconosciuta: la migrazione di un\'estensione o di phpBB non è stata completata.',
			126  => 'Indice di una tabella danneggiato: esegui REPAIR TABLE da phpMyAdmin.',
			144  => 'Tabella danneggiata: esegui REPAIR TABLE da phpMyAdmin.',
			145  => 'Tabella segnata come danneggiata: esegui REPAIR TABLE da phpMyAdmin.',
			1194 => 'Tabella danneggiata: esegui REPAIR TABLE da phpMyAdmin.',
			1195 => 'Tabella danneggiata e riparazione automatica fallita: esegui REPAIR TABLE.',
			1205 => 'Timeout di attesa di un lock: un\'altra operazione teneva occupata la tabella.',
			1213 => 'Deadlock tra due operazioni: di solito è occasionale.',
			28   => 'Spazio su disco esaurito sul server del database.',
			1021 => 'Spazio su disco esaurito sul server del database.',
			1114 => 'Tabella piena: spazio o quota del database esauriti.',
		];
		if (isset($hints[$c]))
		{
			return $hints[$c];
		}
		$cause = self::cause($inc);
		if ($cause === 'memory')
		{
			return 'Memoria PHP esaurita: aumenta memory_limit o individua la pagina o l\'estensione che la consuma.';
		}
		if ($cause === 'timeout')
		{
			return 'Tempo massimo di esecuzione superato: controlla la pagina indicata e le estensioni coinvolte.';
		}
		if ($inc['category'] === 'db_connect')
		{
			return 'Il forum non riesce a collegarsi al database: verifica lo stato di MySQL dal pannello DirectAdmin o con il provider.';
		}
		return '';
	}

	public static function recipients()
	{
		$list = preg_split('/[\s,;]+/', (string) self::$cfg['admin_emails'], -1, PREG_SPLIT_NO_EMPTY);
		$out = [];
		foreach ($list as $addr)
		{
			if (filter_var($addr, FILTER_VALIDATE_EMAIL))
			{
				$out[] = $addr;
			}
		}
		return array_values(array_unique($out));
	}

	// ------------------------------------------------------------------
	// Mail (PHP mail() or built-in SMTP client, no database needed)
	// ------------------------------------------------------------------

	/**
	 * Used by the ACP too: loads a configuration without booting the handlers.
	 */
	public static function configure($dir, array $cfg)
	{
		self::$dir = rtrim($dir, '/\\');
		self::$cfg = self::normalize($cfg);
	}

	public static function send_mail(array $to, $subject, $text, $html)
	{
		if ($to === [])
		{
			return [false, 'nessun destinatario valido'];
		}
		$cfg = self::$cfg;
		$from = filter_var($cfg['mail_from'], FILTER_VALIDATE_EMAIL) ? $cfg['mail_from'] : $to[0];
		$name = self::header_safe($cfg['mail_from_name'] !== '' ? $cfg['mail_from_name'] : 'DB Guardian');
		$subject = self::header_safe($subject);
		$boundary = 'dbg_' . bin2hex(random_bytes(12));
		$host = parse_url((string) $cfg['board_url'], PHP_URL_HOST);
		$host = $host ? $host : (function_exists('gethostname') ? gethostname() : 'localhost');

		$headers = [
			'From: ' . self::encode_header($name) . ' <' . $from . '>',
			'Date: ' . date('r'),
			'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $host . '>',
			'MIME-Version: 1.0',
			'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
			'X-Mailer: DB Guardian ' . self::VERSION,
			'Auto-Submitted: auto-generated',
			'X-Priority: 1',
		];
		$body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			. chunk_split(base64_encode($text))
			. "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
			. chunk_split(base64_encode($html))
			. "--$boundary--\r\n";
		$enc_subject = self::encode_header($subject);

		if ($cfg['mail_transport'] === 'smtp' && $cfg['smtp_host'] !== '')
		{
			$all = array_merge(['To: ' . implode(', ', $to), 'Subject: ' . $enc_subject], $headers);
			return self::smtp_send($from, $to, implode("\r\n", $all), $body);
		}

		if (!function_exists('mail'))
		{
			return [false, 'la funzione mail() non è disponibile'];
		}
		$params = (!empty($cfg['mail_f_param']) && filter_var($from, FILTER_VALIDATE_EMAIL)) ? '-f' . $from : '';
		$ok = $params !== ''
			? @mail(implode(', ', $to), $enc_subject, $body, implode("\r\n", $headers), $params)
			: @mail(implode(', ', $to), $enc_subject, $body, implode("\r\n", $headers));
		if ($ok)
		{
			return [true, ''];
		}
		$last = error_get_last();
		return [false, 'mail() ha restituito false' . (is_array($last) ? ': ' . $last['message'] : '')];
	}

	private static function smtp_send($from, array $to, $headers, $body)
	{
		$cfg = self::$cfg;
		$host = preg_replace('#^(ssl|tls)://#i', '', trim($cfg['smtp_host']));
		$port = $cfg['smtp_port'] ?: ($cfg['smtp_security'] === 'ssl' ? 465 : 587);
		$ctx = stream_context_create(['ssl' => [
			'verify_peer'       => (bool) $cfg['smtp_verify_peer'],
			'verify_peer_name'  => (bool) $cfg['smtp_verify_peer'],
			'allow_self_signed' => !$cfg['smtp_verify_peer'],
			'SNI_enabled'       => true,
			'peer_name'         => $host,
		]]);
		$remote = ($cfg['smtp_security'] === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client($remote, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
		if (!$fp)
		{
			return [false, "connessione a $remote fallita: $errstr ($errno)"];
		}
		stream_set_timeout($fp, 10);

		try
		{
			$helo = parse_url((string) $cfg['board_url'], PHP_URL_HOST);
			$helo = $helo ? $helo : 'localhost';
			self::smtp_expect($fp, null, 220);
			$ehlo = self::smtp_expect($fp, 'EHLO ' . $helo, 250);

			if ($cfg['smtp_security'] === 'tls')
			{
				self::smtp_expect($fp, 'STARTTLS', 220);
				$method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
				if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT'))
				{
					$method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
				}
				if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT'))
				{
					$method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
				}
				if (!@stream_socket_enable_crypto($fp, true, $method))
				{
					throw new \RuntimeException('negoziazione TLS fallita');
				}
				$ehlo = self::smtp_expect($fp, 'EHLO ' . $helo, 250);
			}

			if ($cfg['smtp_user'] !== '')
			{
				if (stripos($ehlo, 'PLAIN') !== false && stripos($ehlo, 'LOGIN') === false)
				{
					self::smtp_expect($fp, 'AUTH PLAIN ' . base64_encode("\0" . $cfg['smtp_user'] . "\0" . $cfg['smtp_pass']), 235);
				}
				else
				{
					self::smtp_expect($fp, 'AUTH LOGIN', 334);
					self::smtp_expect($fp, base64_encode($cfg['smtp_user']), 334, true);
					self::smtp_expect($fp, base64_encode($cfg['smtp_pass']), 235, true);
				}
			}

			self::smtp_expect($fp, 'MAIL FROM:<' . $from . '>', 250);
			foreach ($to as $rcpt)
			{
				self::smtp_expect($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
			}
			self::smtp_expect($fp, 'DATA', 354);
			$data = $headers . "\r\n\r\n" . $body;
			$data = preg_replace("/(?<!\r)\n/", "\r\n", $data);
			$data = preg_replace('/^\./m', '..', $data);
			fwrite($fp, $data . "\r\n.\r\n");
			self::smtp_expect($fp, null, 250);
			@fwrite($fp, "QUIT\r\n");
			@fclose($fp);
			return [true, ''];
		}
		catch (\Throwable $e)
		{
			@fwrite($fp, "QUIT\r\n");
			@fclose($fp);
			return [false, $e->getMessage()];
		}
	}

	private static function smtp_expect($fp, $command, $codes, $secret = false)
	{
		if ($command !== null)
		{
			fwrite($fp, $command . "\r\n");
		}
		$codes = (array) $codes;
		$reply = '';
		while (($line = fgets($fp, 1024)) !== false)
		{
			$reply .= $line;
			if (strlen($line) < 4 || $line[3] === ' ')
			{
				break;
			}
		}
		$code = (int) substr($reply, 0, 3);
		if (!in_array($code, $codes, true))
		{
			$shown = $command === null ? '(risposta del server)' : ($secret ? '(credenziali)' : strtok($command, ' '));
			throw new \RuntimeException(sprintf('SMTP %s: risposta %s', $shown, $reply === '' ? 'vuota o timeout' : trim($reply)));
		}
		return $reply;
	}

	private static function mail_text($intro, array $rows, $hint, $extra, $outro)
	{
		$t = $intro . "\n\n";
		foreach ($rows as $k => $v)
		{
			$t .= str_pad($k . ':', 20) . $v . "\n";
		}
		if ($hint !== '')
		{
			$t .= "\nCosa controllare: " . $hint . "\n";
		}
		if ($extra !== '')
		{
			$t .= "\n" . $extra;
		}
		return $t . "\n" . $outro . "\n\n-- \nDB Guardian " . self::VERSION . "\n";
	}

	private static function mail_html($intro, array $rows, $hint, $extra, $outro, $color)
	{
		$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
		$h = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;color:#1f2433;max-width:640px">';
		$h .= '<div style="border-left:5px solid ' . $color . ';padding:4px 0 4px 14px;margin-bottom:16px"><p style="margin:0;font-size:16px">' . $e($intro) . '</p></div>';
		$h .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%">';
		foreach ($rows as $k => $v)
		{
			$h .= '<tr><td style="border-bottom:1px solid #e3e5ec;color:#5a6072;white-space:nowrap;vertical-align:top">' . $e($k) . '</td><td style="border-bottom:1px solid #e3e5ec;word-break:break-word">' . nl2br($e($v)) . '</td></tr>';
		}
		$h .= '</table>';
		if ($hint !== '')
		{
			$h .= '<p style="background:#fff7e6;border:1px solid #f1d49b;padding:10px 12px;margin:16px 0"><strong>Cosa controllare:</strong> ' . $e($hint) . '</p>';
		}
		if ($extra !== '')
		{
			$h .= '<pre style="background:#f3f4f8;padding:10px 12px;font-size:12px;white-space:pre-wrap;word-break:break-word">' . $e($extra) . '</pre>';
		}
		$h .= '<p style="color:#5a6072">' . $e($outro) . '</p><p style="color:#8a8fa3;font-size:12px">DB Guardian ' . self::VERSION . '</p></div>';
		return $h;
	}

	// ------------------------------------------------------------------
	// Service page
	// ------------------------------------------------------------------

	private static function strings($lang)
	{
		if ($lang === 'en')
		{
			return [
				'lang' => 'en',
				'title' => [
					'db_connect' => 'The forum is temporarily out of service',
					'db_query'   => 'This page could not be loaded',
					'php_fatal'  => 'The forum ran into an internal error',
					'app'        => 'A general error occurred',
				],
				'lead' => [
					'db_connect' => 'The forum cannot reach its database, where posts, members and settings are kept. No data has been lost: as soon as the connection is back, everything picks up where it left off.',
					'db_query'   => 'A request to the forum database did not succeed. The problem is on the server, not on your device.',
					'php_fatal'  => 'Something stopped while the page was being prepared. The problem is on the server, not on your device.',
					'app'        => 'The forum stopped the request because of an unexpected error.',
				],
				'cause' => [
					'overload'    => 'The database server reached its limit of simultaneous connections. This happens during traffic peaks and usually clears up by itself within a few minutes.',
					'unreachable' => 'The database server is not answering: it may be restarting or under maintenance by the hosting provider.',
					'access'      => 'The forum is not allowed to access its database. It is a configuration problem the administrator has to fix.',
					'missing'     => 'A table or field the forum needs is missing from the database, usually after an unfinished update.',
					'damaged'     => 'A database table is damaged and needs to be repaired.',
					'busy'        => 'The database was busy with another operation. Trying again in a moment should work.',
					'space'       => 'The database server ran out of space or had a storage error.',
					'db'          => 'The database returned an unexpected error.',
					'memory'      => 'The page needed more memory than the server allows.',
					'timeout'     => 'The page took too long to be generated.',
					'php'         => 'A forum component raised an error that stopped it.',
					'app'         => 'The forum reported this message:',
				],
				'what' => 'What happened',
				'code' => 'Error code',
				'ref' => 'Reference',
				'when' => 'Date and time',
				'notified' => 'The administrator has already been notified automatically.',
				'not_notified' => 'If the problem continues, let the administrator know and quote the reference below.',
				'retry' => 'Try again',
				'contact' => 'Write to the administrator',
				'status' => 'Service status',
				'countdown' => 'Next automatic attempt in %s seconds.',
				'stop' => 'Stop',
				'stopped' => 'Automatic attempts stopped.',
				'mail_subject' => 'Forum unavailable - ref. %s',
				'mail_body' => "Hello,\nthe forum shows an error.\n\nError code: %s\nReference: %s\nDate and time: %s\nPage: %s\n",
				'details' => 'Technical details (visible only to you)',
				'test' => 'Test page: nothing has been logged or sent.',
			];
		}
		return [
			'lang' => 'it',
			'title' => [
				'db_connect' => 'Il forum è momentaneamente fuori servizio',
				'db_query'   => 'Questa pagina non può essere caricata',
				'php_fatal'  => 'Il forum ha incontrato un errore interno',
				'app'        => 'Si è verificato un errore generale',
			],
			'lead' => [
				'db_connect' => 'Il forum non riesce a collegarsi al suo database, dove sono custoditi messaggi, utenti e impostazioni. Nessun dato è andato perso: appena il collegamento torna disponibile, tutto riprende da dove si era fermato.',
				'db_query'   => 'Una richiesta al database del forum non è andata a buon fine. Il problema riguarda il server, non il tuo dispositivo.',
				'php_fatal'  => 'Qualcosa si è interrotto mentre la pagina veniva preparata. Il problema riguarda il server, non il tuo dispositivo.',
				'app'        => 'Il forum ha interrotto la richiesta per un errore imprevisto.',
			],
			'cause' => [
				'overload'    => 'Il server del database ha raggiunto il numero massimo di connessioni contemporanee. Succede nei momenti di traffico intenso e di solito si risolve da solo in pochi minuti.',
				'unreachable' => 'Il server del database non risponde: potrebbe essere in riavvio o in manutenzione da parte del fornitore del servizio.',
				'access'      => 'Il forum non è autorizzato ad accedere al suo database. È un problema di configurazione che l’amministratore deve correggere.',
				'missing'     => 'Nel database manca una tabella o un campo necessario al forum, di solito dopo un aggiornamento non completato.',
				'damaged'     => 'Una tabella del database risulta danneggiata e va riparata.',
				'busy'        => 'Il database era occupato da un’altra operazione. Riprovando tra poco dovrebbe funzionare.',
				'space'       => 'Il server del database ha esaurito lo spazio o ha avuto un errore di archiviazione.',
				'db'          => 'Il database ha restituito un errore imprevisto.',
				'memory'      => 'La pagina ha richiesto più memoria di quella consentita dal server.',
				'timeout'     => 'La pagina ha impiegato troppo tempo per essere generata.',
				'php'         => 'Un componente del forum ha generato un errore che ne ha interrotto il funzionamento.',
				'app'         => 'Il forum ha segnalato questo messaggio:',
			],
			'what' => 'Cosa è successo',
			'code' => 'Codice errore',
			'ref' => 'Riferimento',
			'when' => 'Data e ora',
			'notified' => 'L’amministratore è già stato avvisato automaticamente.',
			'not_notified' => 'Se il problema continua, avvisa l’amministratore indicando il riferimento qui sotto.',
			'retry' => 'Riprova ora',
			'contact' => 'Scrivi all’amministratore',
			'status' => 'Stato del servizio',
			'countdown' => 'Nuovo tentativo automatico tra %s secondi.',
			'stop' => 'Ferma',
			'stopped' => 'Tentativi automatici fermati.',
			'mail_subject' => 'Forum non raggiungibile - rif. %s',
			'mail_body' => "Ciao,\nil forum mostra un errore.\n\nCodice errore: %s\nRiferimento: %s\nData e ora: %s\nPagina: %s\n",
			'details' => 'Dettagli tecnici (visibili solo a te)',
			'test' => 'Pagina di prova: non è stato registrato né inviato nulla.',
		];
	}

	private static function page_language()
	{
		$pref = self::$cfg['page_language'];
		if ($pref === 'it' || $pref === 'en')
		{
			return $pref;
		}
		$accept = strtolower(self::srv('HTTP_ACCEPT_LANGUAGE'));
		$it = preg_match('/(^|,)\s*it\b/', $accept, $m1, PREG_OFFSET_CAPTURE) ? $m1[0][1] : -1;
		$en = preg_match('/(^|,)\s*en\b/', $accept, $m2, PREG_OFFSET_CAPTURE) ? $m2[0][1] : -1;
		if ($en >= 0 && ($it < 0 || $en < $it))
		{
			return 'en';
		}
		return 'it';
	}

	public static function render_page(array $inc, $lang = null)
	{
		$cfg = self::$cfg;
		$L = self::strings($lang !== null ? $lang : self::page_language());
		$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

		$cat = isset($L['title'][$inc['category']]) ? $inc['category'] : 'app';
		$cause = self::cause($inc);
		$code = self::error_code($inc);
		$when = self::format_time($inc['time'] ? $inc['time'] : time());
		$ref = $inc['ref'] !== '' ? $inc['ref'] : '-';
		$board = $cfg['board_name'];
		$contact = filter_var($cfg['contact_email'], FILTER_VALIDATE_EMAIL) ? $cfg['contact_email'] : '';
		$retry = (int) $cfg['retry_seconds'];
		$accent = $cfg['accent_color'];
		$url = isset($inc['url']) && $inc['url'] !== '' ? $inc['url'] : self::current_url();

		$cause_html = '<p>' . $e($L['cause'][$cause]) . '</p>';
		if ($cause === 'app' && $inc['message'] !== '')
		{
			$cause_html .= '<p class="quote">' . $e(self::cut($inc['message'], 0, 300)) . '</p>';
		}

		$actions = '<a class="btn primary" href="' . $e($url) . '" id="dbg-retry">' . $e($L['retry']) . '</a>';
		if ($contact !== '')
		{
			$mailto = 'mailto:' . $contact . '?subject=' . rawurlencode(sprintf($L['mail_subject'], $ref))
				. '&body=' . rawurlencode(sprintf($L['mail_body'], $code, $ref, $when, $url));
			$actions .= '<a class="btn" href="' . $e($mailto) . '">' . $e($L['contact']) . '</a>';
		}
		if ($cfg['status_url'] !== '' && preg_match('#^https?://#i', $cfg['status_url']))
		{
			$actions .= '<a class="btn" href="' . $e($cfg['status_url']) . '" rel="noopener">' . $e($L['status']) . '</a>';
		}

		$countdown = '';
		$script = '';
		if ($retry > 0 && empty($inc['test']))
		{
			$countdown = '<p class="countdown" id="dbg-countdown" aria-live="polite">'
				. sprintf($e($L['countdown']), '<span id="dbg-sec">' . $retry . '</span>')
				. ' <button type="button" id="dbg-stop">' . $e($L['stop']) . '</button></p>';
			$script = '<script>(function(){var s=' . $retry . ',el=document.getElementById("dbg-sec"),box=document.getElementById("dbg-countdown"),t=setInterval(function(){s--;if(el)el.textContent=s;if(s<=0){clearInterval(t);location.reload();}},1000);'
				. 'document.getElementById("dbg-stop").addEventListener("click",function(){clearInterval(t);box.textContent=' . json_encode($L['stopped'], JSON_UNESCAPED_UNICODE) . ';});})();</script>';
		}

		$details = '';
		if (!empty($inc['test']) || self::is_debug_viewer())
		{
			$rows = [
				'Categoria' => self::category_label($inc['category']),
				'Origine'   => $inc['source'],
				'Titolo'    => $inc['title'],
				'Messaggio' => $inc['message'],
			];
			if ($inc['file'] !== '')
			{
				$rows['File'] = $inc['file'] . ':' . $inc['line'];
			}
			if ($inc['sql'] !== '')
			{
				$rows['SQL'] = $inc['sql'];
			}
			if ($inc['trace'] !== '')
			{
				$rows['Traccia'] = $inc['trace'];
			}
			if (isset($inc['mail']))
			{
				$rows['E-mail'] = $inc['mail'];
			}
			$hint = self::admin_hint($inc);
			if ($hint !== '')
			{
				$rows['Cosa controllare'] = $hint;
			}
			$details = '<details class="tech"' . (!empty($inc['test']) ? '' : ' open') . '><summary>' . $e($L['details']) . '</summary><dl>';
			foreach ($rows as $k => $v)
			{
				$details .= '<dt>' . $e($k) . '</dt><dd><pre>' . $e($v) . '</pre></dd>';
			}
			$details .= '</dl></details>';
		}

		$test = !empty($inc['test']) ? '<p class="testnote">' . $e($L['test']) . '</p>' : '';
		$logo = ($cfg['logo_url'] !== '' && preg_match('#^(https?://|/)#i', $cfg['logo_url'])) ? '<img class="logo" src="' . $e($cfg['logo_url']) . '" alt="' . $e($board) . '">' : '<span class="boardname">' . $e($board) . '</span>';
		$custom = trim((string) $cfg['custom_message']) !== '' ? '<p class="custom">' . nl2br($e($cfg['custom_message'])) . '</p>' : '';
		$notice = !empty($inc['notified']) ? $L['notified'] : $L['not_notified'];

		return '<!DOCTYPE html>
<html lang="' . $L['lang'] . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>' . $e($L['title'][$cat]) . ' - ' . $e($board) . '</title>
<style>
:root{--bg:#e6e8ef;--surface:#f6f7fa;--ink:#1f2433;--muted:#565c70;--line:#cfd3de;--shade:#5d5f96;--disc:#3a4157;--disc-top:#59617c;--lamp-off:#8b90a3;--accent:' . $accent . ';--on-accent:#ffffff}
@media (prefers-color-scheme:dark){:root{--bg:#141722;--surface:#1b1f2c;--ink:#e6e7ee;--muted:#a3a8ba;--line:#2f3547;--shade:#05060b;--disc:#606a88;--disc-top:#838daa;--lamp-off:#4a4f60}}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;min-height:100vh;background:var(--bg);color:var(--ink);font:1rem/1.6 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;display:flex;flex-direction:column;padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left)}
header{padding:1.25rem clamp(1.25rem,5vw,3rem)}
.boardname{font-family:"Iowan Old Style","Palatino Linotype","Book Antiqua",Palatino,Georgia,serif;font-size:1.15rem;font-weight:600;letter-spacing:.01em}
.logo{max-height:48px;max-width:240px}
main{flex:1;display:grid;grid-template-columns:minmax(12rem,20rem) minmax(0,36rem);gap:clamp(1.5rem,5vw,4rem);align-items:center;justify-content:center;padding:1rem clamp(1.25rem,5vw,3rem) 3rem}
.art svg{width:100%;height:auto;display:block}
h1{font-family:"Iowan Old Style","Palatino Linotype","Book Antiqua",Palatino,Georgia,serif;font-weight:600;font-size:clamp(1.85rem,4.2vw,2.75rem);line-height:1.12;margin:0 0 1rem;text-wrap:balance;letter-spacing:-.01em}
.lead{font-size:1.08rem;margin:0 0 1.5rem;max-width:34rem}
h2{font-size:1rem;margin:0 0 .35rem}
.what p{margin:0 0 .5rem;color:var(--muted);max-width:34rem}
.what .quote{color:var(--ink);border-left:3px solid var(--line);padding-left:.75rem;overflow-wrap:anywhere}
.notice{margin:1.25rem 0;padding:.75rem 1rem;background:var(--surface);border-left:4px solid var(--accent);border-radius:0 6px 6px 0}
dl.facts{display:grid;grid-template-columns:auto 1fr;gap:.35rem 1.25rem;margin:1.25rem 0;padding:0}
dl.facts dt{color:var(--muted)}
dl.facts dd{margin:0;font-variant-numeric:tabular-nums;font-weight:600;overflow-wrap:anywhere}
.actions{display:flex;flex-wrap:wrap;gap:.75rem;margin:1.5rem 0 .75rem}
.btn{display:inline-flex;align-items:center;min-height:44px;padding:.6rem 1.15rem;border:1.5px solid var(--accent);border-radius:8px;color:var(--accent);text-decoration:none;font-weight:600;background:transparent}
.btn.primary{background:var(--accent);color:var(--on-accent)}
.btn:hover{filter:brightness(1.08)}
.btn:focus-visible,#dbg-stop:focus-visible,summary:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
@media (prefers-color-scheme:dark){.btn{color:var(--ink)}}
.countdown{color:var(--muted);font-size:.93rem;margin:0}
#dbg-stop{font:inherit;color:var(--accent);background:none;border:0;padding:0 .25rem;text-decoration:underline;cursor:pointer}
@media (prefers-color-scheme:dark){#dbg-stop{color:var(--ink)}}
.custom{margin:1.25rem 0 0;color:var(--muted)}
.testnote{display:inline-block;margin:0 0 1rem;padding:.3rem .7rem;border:1px dashed var(--muted);border-radius:6px;font-size:.9rem;color:var(--muted)}
details.tech{margin-top:1.75rem;border:1px solid var(--line);border-radius:8px;background:var(--surface)}
details.tech summary{cursor:pointer;padding:.7rem 1rem;font-weight:600}
details.tech dl{margin:0;padding:0 1rem 1rem}
details.tech dt{color:var(--muted);font-size:.85rem;margin-top:.6rem}
details.tech dd{margin:0}
details.tech pre{margin:.15rem 0 0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:.82rem;font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
footer{padding:1rem clamp(1.25rem,5vw,3rem) 1.5rem;color:var(--muted);font-size:.85rem}
.lamp{fill:#e0a31a;animation:flicker 1.6s ease-out .3s forwards}
@keyframes flicker{0%{fill:#e0a31a}12%{fill:var(--lamp-off)}20%{fill:#e0a31a}32%{fill:var(--lamp-off)}40%{fill:#e0a31a}100%{fill:var(--lamp-off)}}
@media (prefers-reduced-motion:reduce){.lamp{animation:none;fill:var(--lamp-off)}}
@media (max-width:760px){main{grid-template-columns:1fr;align-items:start;gap:1rem;padding-top:0}.art{max-width:11rem}}
</style>
</head>
<body>
<header>' . $logo . '</header>
<main>
<div class="art" aria-hidden="true">' . self::illustration() . '</div>
<div class="text">
' . $test . '
<h1>' . $e($L['title'][$cat]) . '</h1>
<p class="lead">' . $e($L['lead'][$cat]) . '</p>
<section class="what"><h2>' . $e($L['what']) . '</h2>' . $cause_html . '</section>
<p class="notice">' . $e($notice) . '</p>
<dl class="facts"><dt>' . $e($L['code']) . '</dt><dd>' . $e($code) . '</dd><dt>' . $e($L['ref']) . '</dt><dd>' . $e($ref) . '</dd><dt>' . $e($L['when']) . '</dt><dd>' . $e($when) . '</dd></dl>
<div class="actions">' . $actions . '</div>
' . $countdown . $custom . $details . '
</div>
</main>
<footer>' . $e($board) . '</footer>
' . $script . '
</body>
</html>';
	}

	private static function illustration()
	{
		return '<svg viewBox="0 0 320 300" xmlns="http://www.w3.org/2000/svg" role="presentation">
<defs><linearGradient id="dbg-sh" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="var(--shade)" stop-opacity=".55"/><stop offset="1" stop-color="var(--shade)" stop-opacity="0"/></linearGradient></defs>
<path d="M60 236 L150 236 L316 290 L150 290 Z" fill="url(#dbg-sh)"/>
<line x1="8" y1="236" x2="312" y2="236" stroke="var(--line)" stroke-width="2"/>
<g>
<path d="M50 176 v44 a55 16 0 0 0 110 0 v-44" fill="var(--disc)"/>
<ellipse cx="105" cy="176" rx="55" ry="16" fill="var(--disc-top)"/>
<path d="M50 126 v44 a55 16 0 0 0 110 0 v-44" fill="var(--disc)"/>
<ellipse cx="105" cy="126" rx="55" ry="16" fill="var(--disc-top)"/>
<path d="M50 76 v44 a55 16 0 0 0 110 0 v-44" fill="var(--disc)"/>
<ellipse cx="105" cy="76" rx="55" ry="16" fill="var(--disc-top)"/>
<circle class="lamp" cx="136" cy="102" r="5"/>
<circle cx="120" cy="102" r="3" fill="var(--disc-top)"/>
<circle cx="120" cy="152" r="3" fill="var(--disc-top)"/>
<circle cx="120" cy="202" r="3" fill="var(--disc-top)"/>
</g>
<path d="M160 160 C 205 160, 200 212, 228 212" fill="none" stroke="var(--disc)" stroke-width="6" stroke-linecap="round"/>
<rect x="226" y="203" width="20" height="18" rx="3" fill="var(--disc)"/>
<rect x="246" y="206" width="8" height="4" fill="var(--disc)"/><rect x="246" y="214" width="8" height="4" fill="var(--disc)"/>
<rect x="282" y="196" width="18" height="32" rx="4" fill="none" stroke="var(--disc)" stroke-width="4"/>
<path d="M300 212 H316" stroke="var(--disc)" stroke-width="6" stroke-linecap="round"/>
<path d="M263 199 l5 -9 M268 212 h10 M263 225 l5 9" stroke="#e0a31a" stroke-width="3" stroke-linecap="round"/>
</svg>';
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	public static function category_label($cat)
	{
		$labels = [
			'db_connect' => 'Database non raggiungibile',
			'db_query'   => 'Errore SQL',
			'php_fatal'  => 'Errore fatale PHP',
			'app'        => 'Errore generale phpBB',
			'recovery'   => 'Forum di nuovo online',
		];
		return isset($labels[$cat]) ? $labels[$cat] : $cat;
	}

	private static function is_key($value)
	{
		$key = (string) self::$cfg['debug_key'];
		return strlen($key) >= 16 && hash_equals($key, $value);
	}

	private static function is_debug_viewer()
	{
		return isset(self::$cookies['dbguardian_debug']) && is_string(self::$cookies['dbguardian_debug']) && self::is_key(self::$cookies['dbguardian_debug']);
	}

	private static function lock()
	{
		self::ensure_dirs();
		$fh = @fopen(self::$dir . '/state/state.lock', 'c');
		if ($fh)
		{
			@flock($fh, LOCK_EX);
		}
		return $fh;
	}

	private static function unlock($fh)
	{
		if ($fh)
		{
			@flock($fh, LOCK_UN);
			@fclose($fh);
		}
	}

	private static function read_state()
	{
		$raw = @file_get_contents(self::$dir . '/state/state.json');
		$data = is_string($raw) ? json_decode($raw, true) : null;
		return is_array($data) ? $data : ['sig' => []];
	}

	private static function write_state(array $state)
	{
		$file = self::$dir . '/state/state.json';
		$tmp = $file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, json_encode($state)) !== false)
		{
			@rename($tmp, $file);
		}
	}

	private static function log(array $inc)
	{
		self::ensure_dirs();
		$keep = ['time', 'ref', 'category', 'code', 'title', 'message', 'driver', 'sql', 'file', 'line', 'trace', 'source',
			'url', 'method', 'ip', 'ua', 'repeats', 'suppressed', 'mail'];
		$row = [];
		foreach ($keep as $k)
		{
			if (isset($inc[$k]))
			{
				$row[$k] = is_string($inc[$k]) ? self::cut($inc[$k], 0, $k === 'trace' || $k === 'sql' ? 3000 : 1000) : $inc[$k];
			}
		}
		@file_put_contents(self::$dir . '/logs/' . date('Y-m') . '.log', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
	}

	private static function ensure_dirs()
	{
		foreach (['state', 'logs'] as $d)
		{
			if (!is_dir(self::$dir . '/' . $d))
			{
				@mkdir(self::$dir . '/' . $d, 0755, true);
			}
		}
	}

	private static function current_url()
	{
		$https = (self::srv('HTTPS') !== '' && self::srv('HTTPS') !== 'off')
			|| strtolower(self::srv('HTTP_X_FORWARDED_PROTO')) === 'https'
			|| (int) self::srv('SERVER_PORT') === 443;
		$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', self::srv('HTTP_HOST'));
		$uri = self::srv('REQUEST_URI', '/');
		if ($host === '' && isset(self::$cfg['board_url']) && self::$cfg['board_url'] !== '')
		{
			// Not a live request (ACP preview): point the retry button to the forum.
			return (string) self::$cfg['board_url'];
		}
		$uri = preg_replace('/([?&])dbguardian_test=[^&]*/', '$1', $uri);
		if ($host === '')
		{
			return self::cut($uri, 0, 300);
		}
		return self::cut(($https ? 'https://' : 'http://') . $host . $uri, 0, 300);
	}

	private static function masked_ip()
	{
		$ip = self::srv('REMOTE_ADDR');
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			return preg_replace('/\.\d+$/', '.0', $ip);
		}
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		{
			$bin = @inet_pton($ip);
			if ($bin !== false)
			{
				return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
			}
		}
		return '';
	}

	public static function format_time($ts)
	{
		try
		{
			$tz = new \DateTimeZone((string) self::$cfg['timezone']);
		}
		catch (\Throwable $e)
		{
			$tz = new \DateTimeZone('UTC');
		}
		$d = new \DateTime('@' . (int) $ts);
		$d->setTimezone($tz);
		return $d->format('d/m/Y H:i:s') . ' (' . $tz->getName() . ')';
	}

	public static function duration($seconds)
	{
		$seconds = max(0, (int) $seconds);
		if ($seconds < 60)
		{
			return $seconds . ' s';
		}
		$m = intdiv($seconds, 60);
		if ($m < 60)
		{
			return $m . ' min';
		}
		$h = intdiv($m, 60);
		return $h . ' h ' . ($m % 60) . ' min';
	}

	private static function short_path($text)
	{
		$root = realpath(dirname(dirname(self::$dir)));
		$text = (string) $text;
		return $root ? str_replace($root, '[ROOT]', $text) : $text;
	}

	private static function plain($html)
	{
		$text = preg_replace('#<br\s*/?>#i', "\n", (string) $html);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
		$text = preg_replace("/[ \t]+/", ' ', $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);
		$text = str_ireplace('An sql error occurred while fetching this page. Please contact an administrator if this problem persists.', '', $text);
		return trim($text);
	}

	/**
	 * A value of the request snapshot taken at boot. Empty outside a live request.
	 */
	private static function srv($key, $default = '')
	{
		return isset(self::$server[$key]) && is_scalar(self::$server[$key]) ? (string) self::$server[$key] : $default;
	}

	public static function cut($s, $start, $length)
	{
		return function_exists('mb_substr') ? mb_substr((string) $s, $start, $length, 'UTF-8') : substr((string) $s, $start, $length);
	}

	private static function header_safe($s)
	{
		return trim(str_replace(["\r", "\n"], ' ', (string) $s));
	}

	private static function encode_header($s)
	{
		return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
	}
}

}
