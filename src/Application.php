<?
	namespace ThriveData\ThrivePHP;
	
	/**
	 * Base class for applications using ThrivePHP framework. This is a static singleton class.
	 */
	class Application
	{
		/**
		 * Setup application. Called by front controller.
		 */
		static function init()
		{
			if (!defined('PATH_ROOT')):
				self::setPathRoot();
			endif;
				
			Settings::init();
			Log::init();
		}
		
		/**
		 * Setup application filesystem root path.
		 *
		 * Root path is stored as a defined() constant. If it is not yet defined
		 * then it is determined by using ReflectionClass on Composer's ClassLoader class
		 * filename to calculate the root path relative to it.
		 *
		 * @throws RootPathUnknown
		 */
		static function setPathRoot(?string $path = null)
		{
			if (is_null($path)):
				$path = self::pathRoot();
			endif;
			
			if (is_null($path))
                throw new RootPathUnknown('could not determine root directory');
			
			if (!file_exists($path))
				throw new RootPathUknown('root path does not exist');
			
			if (!is_readable($path))
				throw new RootPathUnknown('root path is not readable');
			
            define('PATH_ROOT', $path);
			
            return $path;
		}
		 
		/**
		 * Setup application filesystem root path.
		 *
		 * Root path is stored as a defined() constant. If it is not yet defined
		 * then it is determined by using ReflectionClass on Composer's ClassLoader class
		 * filename to calculate the root path relative to it.
		 *
		 * @throws RootPathUnknown
		 */
		static function pathRoot()
		{
            if (defined('PATH_ROOT')):
                $path = PATH_ROOT;
            elseif (class_exists('\Composer\AutoLoad\ClassLoader')):
                $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
                $path = dirname($reflection->getFileName(), 3);
            endif;
			
            if (is_null($path))
                throw new RootPathUnknown('could not determine root directory from PATH_ROOT or Composer');
			
			return $path;
		}
		
		/**
		 * Get path to the `local` directory. The local directory overrides corresponding items in the system.
		 *
		 * For example if the local directory contains a `settings.json` file, it's contents will
		 * override the system settings.json file. This is useful for changing settings for development purposes.
		 */
		static function pathLocal()
		{
			if (self::PATH_LOCAL):
				return self::PATH_LOCAL;
			endif;
			
			return PATH_ROOT.'/local';
		}
		
		/**
		 * Entry point for the application. Called by front controller.
		 *
		 * If the application is being run from command line it will start the Commander class,
		 * if it is being run from a web server it starts the Router class.
		 */
		static function start()
		{
			if (php_sapi_name() == 'cli'):
				Commander::init();
				Commander::start();
			else:
				Router::init();
				Router::start();
			endif;
		}
	}
	
	class ApplicationException extends Exception {}
	class AutoloadException extends Exception {}
	class RootPathUnknown extends ApplicationException {}

?>
