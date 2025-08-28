<?
	namespace ThriveData\ThrivePHP;
	
	class Commander
	{
		static $commands = [];
		
		static function init()
		{
			$file = PATH_ROOT.'/commands.php';
			if (is_readable($file)):
				require($file);
			endif;
		}
		
		static function register(Command $command)
		{
			printf("registering command %s\n", $command->name);
			self::$commands[$command->name] = $command;
		}
		
		static function start()
		{
			if (isset($_SERVER['argv'][1])):
				self::call($_SERVER['argv'][1]);
			endif;
		}
		
		static function call(string $name)
		{
			$argv = $_SERVER['argv'];
			foreach (self::$commands as $key => $command):
				if ($name == $key):
					return ($command->callback)($name, $argv);
				endif;
			endforeach;
			
			throw new Exception('command not found');
		}
	}
?>
