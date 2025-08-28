<?
	namespace ThriveData\ThrivePHP;
	
	/**
	 * Canonical access to the HTTP request.
	 */
	class Request
	{
		/** Host in the web server's HTTP header. */
		static $host;
		
		/** Method in the web server's HTTP header (GET, POST, etc.). */
		static $method;
		
		/** Request URL in the server's HTTP header. */
		static $url;
		
		/** The query for the of the URL. */
		static $data;
		
		/** The contents of the Content-Type header. */
		static $type;
		
		/** The content of the body. */
		static $content;
		
		/** Request body content deocded as JSON if content type is `application/json`. */
		static $json;
		
		static function init()
		{
			self::$host = $_SERVER['HTTP_HOST'];
			self::$method = $_SERVER['REQUEST_METHOD'];
			self::$url = $_SERVER['PATH_INFO'];
			self::$data = $_REQUEST;
			self::$type = $_SERVER['CONTENT_TYPE'];
			self::$content = file_get_contents('php://input');
			
			if (strtolower(self::$type) == 'application/json'):
				self::$json = json_decode(self::$content);
			endif;
		}
	}