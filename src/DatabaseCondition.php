<?
	namespace ThriveData\ThrivePHP;
	
	class DatabaseCondition
	{
		static $codes = [
			// data exception
			'22000' => 'data_exception',
			'2202E' => 'array_subscript_error',
			'22021' => 'division_by_zero',
			'22007' => 'invalid_datetime_format',
			'22007' => 'invalid_escape_character',
			'22004' => 'null_value_not_allowed',
			'22003' => 'numeric value_out_of_range',
			'22032' => 'invalid_json_text',
			
			// integrity constraint violations
			'23000' => 'integrity_constraint_violation',
			'23502' => 'not_null_violation',
			'23503' => 'foreign_key_violation',
			'23505' => 'unique_violation',
			'23514' => 'check_violation',
			'23P01' => 'exclusion_violation',
			'22P02' => 'invalid_text_representation',
			
			// syntax errors or access rule violations
			'42000' => 'syntax_error_or_access_rule_violation',
			'42601' => 'syntax_error',
			'42501' => 'insufficient_privilege',
			'42703' => 'undefined_column',
			'42804' => 'datatype_mismatch',
			'42P01' => 'undefined_table',
		];
	}
?>