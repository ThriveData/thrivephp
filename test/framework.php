<?php
	// Run with short_open_tag=1 and FRAMEWORK_TEST_DSN pointing to an empty disposable database.
	// Reuse the existing test autoloader and assertions.
	require __DIR__.'/email-bootstrap.php';

	use ThriveData\ThrivePHP\{ACL, DatabaseConnection, DatabaseForeignKeyViolation, Settings};

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

	// Minimal fixtures for the runtime queries, not a test of the provisioning scripts.
	$connection = pg_connect($dsn);
	check(pg_query($connection, <<<'SQL'
		CREATE TABLE public.users (id uuid PRIMARY KEY, login text, email text, name text, superuser boolean);
		CREATE TABLE public.roles (id uuid PRIMARY KEY, name text);
		CREATE TABLE public.users_roles (user_id uuid REFERENCES public.users, role_id uuid REFERENCES public.roles);
		CREATE TABLE public.permissions (id uuid PRIMARY KEY);
		CREATE TABLE public.roles_permissions (role_id uuid REFERENCES public.roles, key_id uuid REFERENCES public.permissions, permissions bit(6));
		INSERT INTO public.users VALUES
			('00000000-0000-0000-0000-000000000001', 'reader', 'reader@example.com', 'Reader', false),
			('00000000-0000-0000-0000-000000000002', 'admin', 'admin@example.com', 'Admin', true);
		INSERT INTO public.roles VALUES
			('00000000-0000-0000-0000-000000000011', 'Readers'),
			('00000000-0000-0000-0000-000000000012', 'Editors');
		INSERT INTO public.users_roles VALUES
			('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000011'),
			('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000012');
		INSERT INTO public.permissions VALUES ('00000000-0000-0000-0000-000000000021');
		INSERT INTO public.roles_permissions VALUES
			('00000000-0000-0000-0000-000000000011', '00000000-0000-0000-0000-000000000021', B'000001'),
			('00000000-0000-0000-0000-000000000012', '00000000-0000-0000-0000-000000000021', B'000010');
		SQL) !== false, 'Could not create database fixtures');
	pg_close($connection);

	$user = '00000000-0000-0000-0000-000000000001';
	$admin = '00000000-0000-0000-0000-000000000002';
	$key = '00000000-0000-0000-0000-000000000021';
	$_SESSION = ACL::session($user);
	check($_SESSION['user']['login'] === 'reader' && $_SESSION['user']['email'] === 'reader@example.com', 'Session user fields differ');
	check(count($_SESSION['roles']) === 2, 'Session roles were lost');
	check($_SESSION['permissions'][$key] === 3, 'Role grants must combine into an integer mask');
	check(!array_key_exists('acl', $_SESSION), 'Session should expose permissions rather than acl');
	check(ACL::check($key, ACL::select | ACL::update), 'Combined grants were denied');
	check(!ACL::check($key, ACL::delete), 'An ungranted permission was allowed');
	check(!ACL::check('unknown', ACL::select), 'An unknown key was allowed');
	check(ACL::check($key, ACL::delete, $admin), 'Checking another user incorrectly reused the current session');
	$_SESSION['permissions'] = [];
	check(!ACL::check($key, ACL::select), 'An empty permission cache should deny access');
	$_SESSION['user']['superuser'] = true;
	check(ACL::check('unknown', ACL::delete), 'A cached superuser was denied');
	$_SESSION = [];
	check(ACL::check($key, ACL::delete, $admin), 'Database superuser lookup failed');

	print "Framework integration tests passed.\n";
