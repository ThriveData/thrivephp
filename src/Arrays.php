<?
	namespace ThriveData\ThrivePHP;

	class Arrays
	{
		static function flatten($data, $prefix='')
		{
			if(is_null($data)) return [];
			
			$result = [];
			foreach($data as $key => $value):
				if(is_array($value)):
					$result = $result + self::flatten($value, $prefix.$key.'.');
				else:
					$result[$prefix.$key] = $value;
				endif;
			endforeach;
			return $result;
		}
		
		static function keypath($data, $path)
		{
			return array_reduce(
				explode('.', $path),
				fn($carry, $item) => $carry[$item] ?? null,
				$data
			);
		}
	}