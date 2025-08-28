<?
	namespace ThriveData\ThrivePHP;
	
	class DatabaseResult
	{
		private $result;
		private $types;
		
		public function __construct($result)
		{
			$this->result = $result;
			
			// get column types
			for($i=0; $i < pg_num_fields($result); $i++):
				$this->types[pg_field_name($result, $i)] = pg_field_type($result, $i);
			endfor;
		}
		
		public function rows()
		{
			return pg_num_rows($this->result);
		}
		
		public function seek($offset = 0)
		{
			return pg_result_seek($this->result, $offset);
		}
		
		public function fetch($convert=true, $columns='object', $json='object')
		{
			switch ($columns):
				case 'object':
					$row = pg_fetch_object($this->result);
					break;
				case 'array':
					$row = pg_fetch_assoc($this->result);
					break;
			endswitch;
			
			switch ($json):
				case 'object':
					$json_assoc = false;
					break;
				case 'array':
					$json_assoc = true;
					break;
			endswitch;
			
			if (!$row) return $row;
			
			// return value based on column data type
			if ($convert):
				foreach($row as $col => &$value):
					switch($this->types[$col]):
						case 'bool':
							if($value == 't'):
								$value = true;
							elseif($value == 'f'):
								$value = false;
							endif;
							break;
						case 'timestamp':
						case 'timestamptz':
						case 'date':
							if($value):
								$value = new DatabaseDateTime($value);
							endif;
							break;
						case 'interval':
							if($value):
								$value = new DatabaseDateInterval($value);
							endif;
							break;
						case 'json':
						case 'jsonb':
							if (!is_null($value)):
								$value = json_decode($value, $json_assoc);
							endif;
							break;
					endswitch;
				endforeach;
			endif;
			
			return $row;
		}
		
		public function single($convert=true, $columns='object', $json='object')
		{
			if ($this->rows() == 1):
				return $this->fetch(convert: $convert, columns: $columns, json: $json);
			elseif ($this->rows() == 0):
				throw new DatabaseNoResult('no result');
			elseif ($this->rows() > 1):
				throw new DatabaseManyResults('result is not single row');
			else:
				throw new DatabaseException('unknown exception');
			endif;
		}
		
		public function all($convert=true, $struct='object')
		{
			$all = [];
			$this->seek();
			while ($r = $this->fetch($convert, $struct)):
				$all[] = $r;
			endwhile;
			
			return $all;
		}
	}
?>