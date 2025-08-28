<?
	namespace ThriveData\ThrivePHP;
	
	class Log
	{
		// user: person using a feature and doing an operation
		// operator: responsible for the live operation and ongoing maintenance of the feature
		// manager: feature owner, project manager, or person with responsibility for success of, or oversight over, the feature
		// designer: person responsible for the design, implementation, and build, of the feature
			
		// system: the entire system, its subsystems and features
		// feature: an aspect of the system that generally functions independentally of other features/subsystems
		// operation: the thing that is being done, a process, function call, etc., that starts or stops either by a user, timer, or trigger
			
		// same info that a debugger might produce
		const TRACE = 1;
		
		// diagnostic info only significant to devs
		const DEBUG = 2;
		
		// system normal, feature normal, operation normal, behavior expected: operation completed, state change, config assumptions, info that might be useful if needed for verification later
		const INFO = 4;
		
		// system normal, feature normal, operation normal, behavior unexpected/not ideal: deprecations, performance degraded, repeated notices might indicate a bitter problem, no intervention
		const NOTICE = 8;
		
		// system normal, feature normal, operation degraded: an operation entered an abnormal state but can continue, may need intervention
		const WARNING = 16;
		
		// system stable, feature degraded, operation failure: an operation entered an abnormal state and cannot continue, requires intervention
		const ERROR = 32;
		
		// system degraded, feature failure, operation failure, immediate intervention
		const CRITICAL = 64;
		
		// system failure, features failure, operations failure, immediate intervention
		const FATAL = 128;
		
		
		static function init()
		{
			error_reporting(E_ALL);
			
			set_exception_handler('\ThriveData\ThrivePHP\Log::exception');
			set_error_handler('\ThriveData\ThrivePHP\Log::errorHandler');
			register_shutdown_function('\ThriveData\ThrivePHP\Log::shutdown');
		}
		
		static function backtrace_format(array $backtrace)
		{
			$fmt = '';
			foreach($backtrace as $idx => $frame):
				if ($idx > 0) $fmt .= "\n";
				$fmt .= sprintf("#%s | %s:%s | %s%s%s", $idx, $frame['file'], $frame['line'], $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
			endforeach;
			return $fmt;
		}
		
		static function debug($message, $backtrace=null)
		{
			self::log(self::DEBUG, $message, $backtrace ?? debug_backtrace());
		}

		static function info($message)
		{
			self::log(self::INFO, $message, debug_backtrace());
		}
		
		static function notice($message)
		{
			self::log(self::NOTICE, $message, debug_backtrace());
		}

		static function warning($message)
		{
			self::log(self::WARNING, $message, debug_backtrace());
		}

		static function error($message)
		{
			self::log(self::ERROR, $message, debug_backtrace());
		}
		
		static function critical($message)
		{
			self::log(self::CRITICAL, $message, debug_backtrace());
		}
		
		static function fatal($message)
		{
			self::log(self::FATAL, $message, debug_backtrace());
		}
		
		static function log($severity, $message, $backtrace)
		{
			$threshold = match(Settings::fetch('log.level') ?? 'info') {
				'trace' => self::TRACE,
				'debug' => self::DEBUG,
				'info' => self::INFO,
				'notice' => self::NOTICE,
				'warning' => self::WARNING,
				'error' => self::ERROR,
				'critical' => self::CRITICAL,
				'fatal' => self::FATAL,
			};
			
			if ($severity >= $threshold):
				$prefix = match($severity) {
					self::TRACE => 'TRACE',
					self::DEBUG => 'DEBUG',
					self::INFO => 'INFO',
					self::NOTICE => 'NOTICE',
					self::WARNING => 'WARNING',
					self::ERROR => 'ERROR',
					self::CRITICAL => 'CRITICAL',
					self::FATAL => 'FATAL',
				};
				
				$message = sprintf("%s: %s", $prefix, $message);
				
				$bt = Settings::fetch('log.trace') ?? false;
				if ($bt):
					$message .= sprintf("\n%s", self::backtrace_format($backtrace));
				endif;
					
				if (php_sapi_name() == 'cli'):
					printf("%s\n", $message);
				endif;
				
				error_log($message);
			endif;
		}
		
		static function email($subject, $message)
		{
		}
		
		static function exception($e, ?bool $display=null)
		{
			$display = ini_get('display_errors');
			$log = ini_get('log_errors');
			$email = Settings::fetch('log.email.send');
			
			if ($log):
				error_log($e);
			endif;
			
			if ($display):
				if (php_sapi_name() == 'cli'):
					print($e);
				else:
					printf('<pre style="margin: 0; padding: 15px; color: hsl(354, 61%%, 43%%); background: hsl(355, 70%%, 91%%); border: 1px solid hsl(354, 71%%, 81%%); font-family: monospace;">%s</pre>', $e);
				endif;
			endif;
			
			if ($email):
				$subject = $e->getMessage();
				$message = $e;
				self::email($subject, $message);
			endif;
		}

		static function errorHandler($severity=null, $message=null, $file=null, $line=null, $context=null)
		{
			// the error was suppressed with @-operator
			if (error_reporting() === 0):
				return false;
			endif;
			
			switch($severity):
				case E_ERROR:               throw new ErrorException ($message, 0, $severity, $file, $line);
				case E_WARNING:             throw new WarningException ($message, 0, $severity, $file, $line);
				case E_PARSE:               throw new ParseException ($message, 0, $severity, $file, $line);
				case E_NOTICE:              throw new NoticeException ($message, 0, $severity, $file, $line);
				case E_CORE_ERROR:          throw new CoreErrorException ($message, 0, $severity, $file, $line);
				case E_CORE_WARNING:        throw new CoreWarningException ($message, 0, $severity, $file, $line);
				case E_COMPILE_ERROR:       throw new CompileErrorException ($message, 0, $severity, $file, $line);
				case E_COMPILE_WARNING:     throw new CoreWarningException ($message, 0, $severity, $file, $line);
				case E_USER_ERROR:          throw new UserErrorException ($message, 0, $severity, $file, $line);
				case E_USER_WARNING:        throw new UserWarningException ($message, 0, $severity, $file, $line);
				case E_USER_NOTICE:         throw new UserNoticeException ($message, 0, $severity, $file, $line);
				case E_RECOVERABLE_ERROR:   throw new RecoverableErrorException ($message, 0, $severity, $file, $line);
				case E_DEPRECATED:          throw new DeprecatedException ($message, 0, $severity, $file, $line);
				case E_USER_DEPRECATED:     throw new UserDeprecatedException ($message, 0, $severity, $file, $line);
			endswitch;
			
			throw new \ErrorException($message, 0, $severity, $file, $line);
		}

		static function shutdown()
		{
			$error = error_get_last();
			if ($error !== null):
				throw new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
			endif;
		}
		
		static function dump($data, $print=false, $indent=1, $first=true, $html=null)
		{
			if (is_null($html)):
				if (php_sapi_name() == 'cli'):
					$html = false;
				else:
					$html = true;
				endif;
			endif;
			
			$retval = '';
			$prefix = \str_repeat("    ", $indent);
			if (\is_numeric($data)) $retval .= "Number: $data";
			elseif (\is_string($data)) $retval .= "String: '$data'";
			elseif (\is_null($data)) $retval .= "NULL";
			elseif ($data===true) $retval .= "TRUE";
			elseif ($data===false) $retval .= "FALSE";
			elseif (is_array($data)) {
				$retval .= "Array (".count($data).')';
				$indent++;
				foreach($data AS $key => $value) {
					$retval .= sprintf("\n%s[%s] = ", $prefix, $key);
					$retval .= self::dump($value, indent: $indent, first: false);
				}
			}
			elseif (is_object($data)) {
				$retval .= "Object (".get_class($data).")";
				$indent++;
				foreach((array)$data AS $key => $value) {
					$retval .= sprintf("\n%s%s \u{2192} ", $prefix, $key);
					$retval .= self::dump($value, indent: $indent, first: false);
				}
			}
			
			if ($print) {
				if ($first) $retval .= "\n";
				if ($first && $html) printf('<pre><div style="border: 1px solid rgb(241, 174, 181); color: rgb(88, 21, 28); background: rgb(248, 215, 218); padding: 1rem;">%s</div></pre>', $retval);
				if ($first && !$html) printf('%s', $retval);
			} else {
				return $retval;
			}
		}
	}

	class ErrorException                extends \ErrorException {}
	class WarningException              extends ErrorException {}
	class ParseException                extends ErrorException {}
	class NoticeException               extends ErrorException {}
	class CoreErrorException            extends ErrorException {}
	class CoreWarningException          extends ErrorException {}
	class CompileErrorException         extends ErrorException {}
	class CompileWarningException       extends ErrorException {}
	class UserErrorException            extends ErrorException {}
	class UserWarningException          extends ErrorException {}
	class UserNoticeException           extends ErrorException {}
	class StrictException               extends ErrorException {}
	class RecoverableErrorException     extends ErrorException {}
	class DeprecatedException           extends ErrorException {}
	class UserDeprecatedException       extends ErrorException {}
