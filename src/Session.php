<?
	namespace ThriveData\ThrivePHP;
	
	class Session
	{
		static function start()
		{
			Log::debug(message: 'starting session');
				
			if(!isset($_SESSION)):
				\session_start();
			endif;
			
			setcookie('crumb_device', $_COOKIE['crumb_device'] ?? self::token(), time()+60*60*24*365, '/');
			
			if (isset($_COOKIE['crumb_user'])):
				setcookie('crumb_user', $_COOKIE['crumb_user'], time()+60*60*24*7, '/');
			endif;
		}
		
		static function clear()
		{
			Log::debug(message: 'clearing session');
			
			self::start();
			
			$_SESSION = array();
		}
		
		/** Redirect on authentication failure, or throw NoAuth when $redirect is false or null. */
		static function authenticate($redirect = '/login')
		{
			Log::debug('authenticating');
			
			self::start();
			
			if(!isset($_SESSION['session']['id'])):
				self::authenticationFailure($redirect, 'session is not set');
			endif;
			
			try {
				$session = DB::query(<<<SQL
					UPDATE public.users_sessions SET expires_when = now()+make_interval(hours := 24) WHERE id=$1 AND expires_when > now() RETURNING *
					SQL, $_SESSION['session']['id']
				)->single();
				Log::debug(sprintf('updated user session | id=[%s]', $session->id), debug_backtrace());
			} catch (\ThriveData\ThrivePHP\DatabaseNoResult $e) {
				Log::debug(
					sprintf('could not update user session | id=[%s] | message=[%s] ',
						$_SESSION['session']['id'],
						$e->getMessage()
					)
				);
				self::authenticationFailure($redirect, 'could not update user session');
			}
		}

		private static function authenticationFailure($redirect, string $message)
		{
			Log::debug($message);
			if ($redirect):
				$parts = explode('#', $redirect, 2);
				$url = $parts[0];
				$separator = str_contains($url, '?') ? '&' : '?';
				if (str_ends_with($url, '?') || str_ends_with($url, '&')):
					$separator = '';
				endif;
				$url .= $separator.'referrer='.urlencode($_SERVER['REQUEST_URI'] ?? '/');
				if (isset($parts[1])):
					$url .= '#'.$parts[1];
				endif;
				Response::redirect($url);
			endif;

			throw new NoAuth($message);
		}
		
		static function recent($class, $value)
		{
			self::start();
			
			if(!isset($_SESSION['recents'][$class]) || !is_array($_SESSION['recents'][$class])):
				$_SESSION['recents'][$class] = [];
			endif;
			array_unshift($_SESSION['recents'][$class], $value);
			$_SESSION['recents'][$class] = array_values(array_unique($_SESSION['recents'][$class]));
		}
		
		static function token($length=32)
		{
			$data = random_bytes(16);
			
			$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
			$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
			
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}

	}
?>
