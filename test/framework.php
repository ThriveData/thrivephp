<?php
	// Run with short_open_tag=1 and FRAMEWORK_TEST_DSN pointing to an empty disposable database.
	// Reuse the existing test autoloader and assertions.
	require __DIR__.'/email-bootstrap.php';

	use ThriveData\ThrivePHP\{DatabaseConnection, DatabaseForeignKeyViolation, Settings};

	$dsn = getenv('FRAMEWORK_TEST_DSN');
	check((bool) $dsn, 'Set FRAMEWORK_TEST_DSN to an empty disposable PostgreSQL database.');
	Settings::$data = [
		'pgsql' => ['connections' => ['default' => $dsn]],
		'log' => ['level' => 'fatal'],
	];

	$connection = new DatabaseConnection($dsn);
	$connection->query('CREATE TEMP TABLE test_parent (id integer PRIMARY KEY)');
	$connection->query('CREATE TEMP TABLE test_child (parent_id integer REFERENCES test_parent)');
	try {
		$connection->query('INSERT INTO test_child (parent_id) VALUES ($1)', 1);
		throw new RuntimeException('Expected a foreign-key violation');
	} catch (DatabaseForeignKeyViolation $e) {
		check($e->sqlstate === '23503' && $e->table === 'test_child', 'Foreign-key diagnostics were lost');
	}

	print "Framework integration tests passed.\n";
