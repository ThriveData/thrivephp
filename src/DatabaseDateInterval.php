<?
	namespace ThriveData\ThrivePHP;
	
	class DatabaseDateInterval extends \DateInterval
	{
		private $y, $m, $d, $h, $i, $s, $f;
		
		public function __construct($spec)
		{
			if(preg_match('/P(?:([0-9]+)Y)?(?:([0-9]+)M)?(?:([0-9]+)D)?(?:T(?:([0-9]+)H)?(?:([0-9]+)M)?(?:([0-9]+)(?:\.([0-9]+))?S)?)?/', $spec, $matches)):
				$this->y = (float)($matches[1] ?? 0);   // years
				$this->m = (float)($matches[2] ?? 0);   // months
				$this->d = (float)($matches[3] ?? 0);   // days
				$this->h = (float)($matches[4] ?? 0);   // hours
				$this->i = (float)($matches[5] ?? 0);   // minutes
				$this->s = (float)($matches[6] ?? 0);   // seconds
				$this->f = (float)('0.'.($matches[7] ?? 0));   // microseconds
			endif;
		}

		public function truncate($part=null, $precision=0)
		{
			$td = $this->s/60/60/24 + $this->i/60/24 + $this->h/24 + $this->d;
			$th = $this->s/60/60 + $this->i/60 + $this->h;
			$ti = $this->s/60 + $this->i;
			$y = $this->y;
			$m = $this->m;
			switch($part) {
				case 'd':
					$d = $td;
					$h = 0;
					$i = 0;
					$s = 0;
					break;
				case 'h':
					$d = $this->d;
					$h = $th;
					$i = 0;
					$s = 0;
					break;
				case 'i':
					$d = $this->d;
					$h = $this->h;
					$i = $ti;
					$s = 0;
					break;
				default:
					$d = $this->d;
					$h = $this->h;
					$i = $this->i;
					$s = $this->s;
			}			
			$str = '';
			//$str = ($this->invert ? '-' : '');
			if($y > 0) $str .= round($y, $precision).'y ';
			if($m > 0) $str .= round($m, $precision).'m ';
			if($d > 0) $str .= round($d, $precision).'d ';
			if($h > 0) $str .= round($h, $precision).'h ';
			if($i > 0) $str .= round($i, $precision).'m ';
			if($s > 0) $str .= round($s, $precision).'s ';
			return $str;
		}
		
		public function __toString()
		{
			return $this->truncate();
		}
	}
?>