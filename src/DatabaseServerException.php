<?
	namespace ThriveData\ThrivePHP;
	
	class DatabaseServerException extends DatabaseException
	{
		public function __construct(
			public $severity,
			public $sqlstate,
			public $message,
			public $detail,
			public $hint,
			public $position,
			public $context,
			public $schema,
			public $table,
			public $column,
			public $datatype,
			public $constraint,
			public $condition,
			?\throwable $previous=null,
		) {
			parent::__construct(message: $message, previous: $previous);
		}
		
		public function __toString()
		{
			return
				sprintf("%s: %s\n", get_called_class(), $this->message).
				sprintf("%s: %s\n", $this->severity, $this->condition).
				($this->detail ? sprintf("DETAIL: %s\n", $this->detail) : null).
				($this->hint ? sprintf("HINT: %s\n", $this->hint) : null).
				($this->context ? sprintf("CONTEXT: %s\n", $this->context) : null).
				($this->schema ? sprintf("SCHEMA: %s\n", $this->schema) : null).
				($this->table ? sprintf("TABLE: %s\n", $this->table) : null).
				($this->column ? sprintf("COLUMN: %s\n", $this->column) : null).
				($this->constraint ? sprintf("CONSTRAINT: %s\n", $this->constraint) : null).
				"Stack trace:\n".$this->getTraceAsString()."\n"
				;
		}
	}
?>