<?
	namespace ThriveData\ThrivePHP;
	
	class Settings
	{
		static $data = [];
				
		static function init(string ...$files)
		{
			if (count($files) == 0):
				$files[] = PATH_ROOT.'/settings.json';
				$files[] = PATH_ROOT.'/local/settings.json';
			endif;
				
			foreach($files as $f):
				self::load(file: $f, silent: true);
			endforeach;
		}
		
		static function load(string $file, bool $silent)
		{
			if (is_readable($file)):
				$data = json_decode(json: file_get_contents($file), associative: true, flags: JSON_THROW_ON_ERROR);
				if ($data):
					self::$data = array_merge(self::$data, $data);
				endif;
			elseif (!$silent):
				throw new SettingsException('file not found');
			endif;
		}
		
		static function fetch($path)
		{
			return array_reduce(
				explode('.', $path),
				fn($carry, $item) => $carry[$item] ?? null,
				self::$data
			);
		}
		
		static function get($path)
		{
			return array_reduce(
				explode('.', $path),
				fn($carry, $item) => $carry[$item] ?? throw new SettingsException('config keypath does not exist [keypath='.$path.']'),
				self::$data
			);
		}
	}

	class SettingsException extends Exception {}

?>
