<?
	namespace ThriveData\ThrivePHP;
	
	class DatabaseDateTime extends \DateTime
	{
		public function __toString()
		{
			return $this->format('c');
		}
	}
?>