<?
	namespace ThriveData\ThrivePHP;

	/**
	 * Routes HTTP requests from a front controller to registered Route objects. This is a static singleton class.
	 *
	 * This class functions based on the Front Controller pattern, where a 
	 * webserver (such as nginx) is configured to proxy HTTP requests to a front contoller.
	 *
	 * The front controller passes the request from the webserver to Router::call().
	 *
	 * Router::call() searches the registered Route objects (via `Router::register()`) for a Route
	 * that matches the HTTP method, host, and URL.
	 *
	 * When a match is found, the callback given in the Route object is called,
	 * which is typically a method in a subclassed Page or API class.
	 *
	 * If a match is not found, a HTTP 404 response is returned.
	 */
	class Router
	{
		/** Routes registered via Router::register(). */
		static $routes = [];
		
		/**
		 * Setup static properties. Called by `Application::init()`.
		 */
		static function init()
		{
			Request::init();
			
			$file = PATH_ROOT.'/routes.php';
			if (is_readable($file)):
				Log::debug('found routes file');
				require($file);
			endif;
		}
		
		/**
		 * Register {@see Route} objects. Called in `routes.php` where routes are defined.
		 */
		static function register(Route $route)
		{
			self::$routes[$route->host][$route->url][$route->method] = $route;
		}
		
		/**
		 * Searches for a `Route` matching the HTTP request and calls its callback method.
		 * Called by Router::start().
		 */
		static function call(string $host, string $url, string $method)
		{
			Log::debug("routing | host={$host} | url={$url} | method={$method} | routes=".count(self::$routes), debug_backtrace());
			
			if (count(self::$routes) == 0):
				Log::debug('no routes', debug_backtrace());
			endif;
			
			foreach(self::$routes as $host_regex => $host_routes):
				if (preg_match($host_regex, $host, $host_matches)):
					foreach ($host_routes ?? [] as $url_regex => $method_routes):
						if (preg_match($url_regex, $url, $url_matches)):
							foreach ($method_routes ?? [] as $method_regex => $route):
								if (preg_match($method_regex, $method, $method_matches)):
									Log::debug(sprintf('found route | regex=%s', $route->url), debug_backtrace());
									try {
										return ($route->callback)(
											data: $_REQUEST,
											url: $url_matches, 
											method: $method_matches, 
											host: $host_matches, 
											route: $route
										);
									} catch (Throwable $e) {
										Log::exception($e);
									}
								endif;
							endforeach;
						endif;
					endforeach;
				endif;
			endforeach;
			
			Log::debug(sprintf('route not found (host=%s, url=%s)', $host, $url), debug_backtrace());
			http_response_code(404);
			print('404 not found');
		}
		
		/**
		 * Called by `Application::start()` to begin routing.
		 */
		static function start()
		{
			self::call(host: Request::$host, url: Request::$url, method: Request::$method);
		}
	}
	
?>
