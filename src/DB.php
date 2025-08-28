<?
	namespace ThriveData\ThrivePHP;
	
	/**
	 * PostgreSQL database interface.
	 */
	class DB
	{
		/**
		 * Queries the database.
		 */
		static function query($query, ...$params)
		{
			$c = new DatabaseConnection();
			return $c->query($query, ...$params);
		}
		
		static function send($query, ...$params)
		{
			$c = new DatabaseConnection();
			return $c->send($query, ...$params);
		}
		
		/**
		 * Updates `$table` with `$data` that matches `$condition`.
		 */
		static function update($table, $data, $condition)
		{
			$c = new DatabaseConnection();
			return $c->update($table, $data, $condition);
		}
		
		/**
		 * Inserts `$data` into `$table`.
		 */
		static function insert($table, $data)
		{
			$c = new DatabaseConnection();
			return $c->insert($table, $data);
		}
	}
	
?>
