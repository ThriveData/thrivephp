<?
	namespace ThriveData\ThrivePHP;
	
	/**
	 * Connection to the database.
	 */
	class DatabaseConnection
	{
		private $connection;
		
		/**
		 * Start and setup connection to the database.
		 *
		 * The database connection parameters are retrieved from `settings.json` and the key `pgsql.connections.default`. (See `Settings` class.)
		 *
		 * The database GUC `intervalstyle` is set to `iso_8601` to be more compatible with date parsing.
		 *
		 * Some custom GUC parameters are set which are accessible by database queries:
		 * - 
		 */
		public function __construct($dsn = null)
		{
			if(is_null($dsn)):
				$dsn = Settings::get('pgsql.connections.default');
			endif;
			
			$this->connection = pg_connect($dsn);
			
			$settings = [
				'intervalstyle' => 'iso_8601',
			];
			
			if (isset($_SESSION['user']['id'])):
				pg_query_params($this->connection, "SELECT set_config('user.id', $1, false)", [$_SESSION['user']['id']]);
				$settings['app.state']['user']['id'] = $_SESSION['user']['id'];
			endif;
			if (isset($_COOKIE['timezone'])):
				pg_query_params($this->connection, "SELECT set_config('user.device_timezone', $1, false)", [$_COOKIE['timezone']]);
				$settings['app.state']['device']['timezone'] = $_COOKIE['timezone'];
			endif;
			if (isset($_SERVER['REMOTE_ADDR'])):
				pg_query_params($this->connection, "SELECT set_config('user.device_addr', $1, false)", [$_SERVER['REMOTE_ADDR']]);
				$settings['app.state']['device']['ipaddr'] = $_SERVER['REMOTE_ADDR'];
			endif;
			if (isset($_COOKIE['crumb_device'])):
				$settings['app.state']['device']['crumb'] = $_COOKIE['crumb_device'];
			endif;
			if (isset($_COOKIE['crumb_user'])):
				$settings['app.state']['user']['crumb'] = $_COOKIE['crumb_user'];
			endif;
			if (isset($_SERVER['HTTP_USER_AGENT'])):
				$settings['app.state']['device']['browser'] = $_SERVER['HTTP_USER_AGENT'];
			endif;
			pg_query_params(
				$this->connection,
				"SELECT set_config(t2.key, t2.value, false) FROM (VALUES($1::json)) AS t1(settings), json_each_text(t1.settings) AS t2",
				[json_encode($settings)]
			);
		}
		
		public function query($query, ...$params)
		{
			return self::send($query, ...$params);
		}
		
		public function send($query, ...$params)
		{
			if (pg_connection_busy($this->connection)):
				throw new DatabaseException('connection is busy');
			endif;
			
			$result = pg_get_result($this->connection);
			if ($result !== false):
				throw new DatabaseException('results on connection');
			endif;
			
			$status = pg_send_query_params($this->connection, $query, $params);
			if (!$status):
				throw new DatabaseException('Could not send query.');
			endif;
			
			$result = false;
			while ($check = pg_get_result($this->connection)):
				$result = $check;
			endwhile;
			
			if ($result === false):
				throw new DatabaseException('Could not get query result.');
			endif;
			
			$error = pg_result_error($result);
			
			if ($error):
				$fields = [
					'severity' => pg_result_error_field($result, PGSQL_DIAG_SEVERITY),
					'sqlstate' => pg_result_error_field($result, PGSQL_DIAG_SQLSTATE),
					'message' => pg_result_error_field($result, PGSQL_DIAG_MESSAGE_PRIMARY),
					'detail' => pg_result_error_field($result, PGSQL_DIAG_MESSAGE_DETAIL),
					'hint' => pg_result_error_field($result, PGSQL_DIAG_MESSAGE_HINT),
					'position' => pg_result_error_field($result, PGSQL_DIAG_STATEMENT_POSITION),
					'context' => pg_result_error_field($result, PGSQL_DIAG_CONTEXT),
					'schema' => pg_result_error_field($result, PGSQL_DIAG_SCHEMA_NAME),
					'table' => pg_result_error_field($result, PGSQL_DIAG_TABLE_NAME),
					'column' => pg_result_error_field($result, PGSQL_DIAG_COLUMN_NAME),
					'datatype' => pg_result_error_field($result, PGSQL_DIAG_DATATYPE_NAME),
					'constraint' => pg_result_error_field($result, PGSQL_DIAG_CONSTRAINT_NAME),
				];
				$fields['condition'] = DatabaseCondition::$codes[$fields['sqlstate']] ?? $fields['sqlstate'];
				
				$cancel = pg_cancel_query($this->connection);
				if ($cancel === false):
					throw new DatabaseException('Could not cancel query after error.');
				endif;
				
				// this prevents "Cannot set connection to blocking mode"
				$close = pg_close($this->connection);
				if ($close == false):
					throw new DatabaseException('Could not close connection after error.');
				endif;
				
				switch ($fields['condition']):
					case 'data_exception':
						throw new DatabaseDataException(...$fields); break;
					case 'datatype_mismatch':   // 42804
						throw new DatabaseDatatypeMismatch(...$fields); break;
					case 'not_null_violation':   // 23502
						throw new DatabaseNotNullViolation(...$fields); break;
					case 'undefined_column':
						throw new DatabaseUndefinedColumn(...$fields); break;
					case 'unique_violation':
						throw new DatabaseUniqueViolation(...$fields); break;
					case 'check_violation':
						throw new DatabaseCheckViolation(...$fields); break;
					case 'exclusion_violation':
						throw new DatabaseExclusionViolation(...$fields); break;
					case 'invalid_text_representation':
						throw new DatabaseInvalidTextRepresentation(...$fields); break;
					default:
						throw new DatabaseServerException(...$fields);
				endswitch;
			endif;
			
			return new DatabaseResult($result);
		}

		public function update($table, $data, $condition)
		{
			return pg_update($this->connection, $table, $data, $condition);
		}
		
		public function insert($table, $data)
		{
			foreach($data as &$d):
				if (is_bool($d)):
					if ($d === true):
						$d = 't';
					elseif ($d === false):
						$d = 'f';
					endif;
				elseif ($d instanceof DateTime):
					$d = $d->format(DateTime::ISO8601);
				endif;
			endforeach;
			
			for($i=1; $i <= count($data); $i++) { $placeholders[] = '$'.$i; }
			
			$query = 
				sprintf(
					'INSERT INTO %s (%s) VALUES (%s) RETURNING *',
					$table,
					implode(',', array_keys($data)),
					implode(',', $placeholders)
				);
			return $this->query($query, ...array_values($data))->single();
		}
	}
?>