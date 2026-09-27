<?php
	// Run with short_open_tag=1 and FRAMEWORK_TEST_DSN pointing to an empty disposable database.
	// Reuse the existing test autoloader and assertions.
	require __DIR__.'/email-bootstrap.php';

	use ThriveData\ThrivePHP\{ACL, DB, DatabaseConnection, DatabaseForeignKeyViolation, Log, NoAuth, Response, Session, Settings};

	$dsn = getenv('FRAMEWORK_TEST_DSN');
	check((bool) $dsn, 'Set FRAMEWORK_TEST_DSN to an empty disposable PostgreSQL database.');
	Settings::$data = [
		'pgsql' => ['connections' => ['default' => $dsn]],
		'log' => ['level' => 'fatal'],
	];

	// This file also acts as the router for the test's local HTTP server.
	if (PHP_SAPI === 'cli-server'):
		switch ($_GET['action'] ?? 'health'):
			case 'redirect':
				Response::redirect($_GET['url'], ...($_GET['values'] ?? []));
				break;
			case 'authenticate':
				$_SESSION = [];
				if (isset($_GET['session'])) $_SESSION['session']['id'] = $_GET['session'];
				if (isset($_GET['uri'])) $_SERVER['REQUEST_URI'] = $_GET['uri'];
				if (isset($_GET['missing_uri'])) unset($_SERVER['REQUEST_URI']);
				try {
					Session::authenticate(isset($_GET['no_redirect']) ? false : ($_GET['login'] ?? '/login'));
					print 'authenticated';
				} catch (NoAuth $e) {
					http_response_code(401);
					print $e->getMessage();
				}
				break;
			case 'failure':
				if (isset($_GET['code'])):
					Response::failure((int) $_GET['code'], ['X-Test' => 'failure']);
				else:
					Response::failure();
				endif;
				print json_encode(Response::$headers);
				break;
			default:
				print 'ready';
		endswitch;
		return;
	endif;

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
		CREATE TABLE public.users_sessions (id uuid PRIMARY KEY, expires_when timestamptz);
		INSERT INTO public.users_sessions VALUES
			('00000000-0000-0000-0000-000000000031', now() + interval '1 hour'),
			('00000000-0000-0000-0000-000000000032', now() - interval '1 hour');
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

	Settings::$data['log']['level'] = 'debug';
	ob_start();
	Log::trace('hidden', []);
	check(ob_get_clean() === '', 'Trace should be suppressed at debug level');
	Settings::$data['log']['level'] = 'trace';
	ob_start();
	Log::trace('visible', []);
	check(ob_get_clean() === "TRACE: visible\n", 'Trace should use the TRACE severity');
	$_SESSION = ['user' => ['id' => $user], 'permissions' => []];
	ob_start();
	ACL::check($key, ACL::select);
	$trace = ob_get_clean();
	check(str_contains($trace, 'found session permissions') && str_contains($trace, 'check failed'), 'ACL trace should explain a denial');
	Settings::$data['log']['level'] = 'fatal';
	$_SESSION = [];

	// Exercise real response headers and exit(), rather than mocking redirect().
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	check($socket !== false, 'Could not allocate an HTTP port');
	$address = stream_socket_get_name($socket, false);
	fclose($socket);
	$log = tmpfile();
	$server = proc_open([PHP_BINARY, '-d', 'short_open_tag=1', '-S', $address, __FILE__], [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes);
	check(is_resource($server), 'Could not start the PHP HTTP server');
	fclose($pipes[0]);
	$request = function (array $query) use ($address) {
		$headers = [];
		$curl = curl_init('http://'.$address.'/?'.http_build_query($query));
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
				if (str_contains($line, ':')):
					[$name, $value] = explode(':', $line, 2);
					$headers[strtolower($name)] = trim($value);
				endif;
				return strlen($line);
			},
		]);
		$body = curl_exec($curl);
		return ['code' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
	};
	try {
		$ready = false;
		for ($i = 0; $i < 100; $i++):
			if ($request([])['body'] === 'ready'):
				$ready = true;
				break;
			endif;
			usleep(50000);
		endfor;
		check($ready, 'HTTP server did not become ready');
		foreach ([
			['url' => '/search?q=hello%20world&next=%2Forders'],
			['url' => '/orders/%s?value=%d', 'values' => ['abc', 7]],
		] as $case):
			$result = $request(['action' => 'redirect'] + $case);
			$expected = isset($case['values']) ? '/orders/abc?value=7' : $case['url'];
			check($result['code'] === 302 && $result['headers']['location'] === $expected, 'Redirect changed the URL');
		endforeach;
		$active = '00000000-0000-0000-0000-000000000031';
		$expired = '00000000-0000-0000-0000-000000000032';
		$missing = '00000000-0000-0000-0000-000000000033';
		$uri = '/orders?q=hello%20world&sort=name';
		foreach ([null, $expired, $missing] as $session):
			foreach (['/login', '/signin?tenant=one', '/signin?tenant=one#form', '/signin?', '/signin?tenant=one&'] as $login):
				$result = $request(['action' => 'authenticate', 'session' => $session, 'login' => $login, 'uri' => $uri]);
				check($result['code'] === 302, 'Authentication failure did not redirect');
				$url = parse_url($result['headers']['location']);
				parse_str($url['query'], $query);
				check($query['referrer'] === $uri, 'Authentication lost the original request URI');
				check($url['path'] === parse_url($login, PHP_URL_PATH), 'Custom login path was lost');
				if (str_contains($login, 'tenant=')) check($query['tenant'] === 'one', 'Existing login query was lost');
				if (str_contains($login, '#')) check($url['fragment'] === 'form', 'Login fragment was lost');
			endforeach;
			$result = $request(['action' => 'authenticate', 'session' => $session, 'no_redirect' => 1]);
			check($result['code'] === 401 && !isset($result['headers']['location']), 'Disabled redirects should throw NoAuth');
			check($result['body'] === ($session === null ? 'session is not set' : 'could not update user session'), 'Authentication exception message differs');
		endforeach;
		$result = $request(['action' => 'authenticate', 'missing_uri' => 1]);
		check($result['headers']['location'] === '/login?referrer=%2F', 'Missing request URI should default to /');
		$result = $request(['action' => 'authenticate', 'session' => $active]);
		check($result['code'] === 200 && $result['body'] === 'authenticated', 'Valid session was rejected');
		check(DB::query('SELECT expires_when > now() + interval \'23 hours\' AS extended FROM public.users_sessions WHERE id=$1', $active)->single()->extended, 'Valid session was not extended');
		check($request(['action' => 'failure'])['code'] === 400, 'Default failure status differs');
		$result = $request(['action' => 'failure', 'code' => 422]);
		check($result['code'] === 422 && json_decode($result['body'], true) === ['X-Test' => 'failure'], 'Explicit failure status or stored headers differ');
	} finally {
		proc_terminate($server);
		proc_close($server);
		fclose($log);
	}

	print "Framework integration tests passed.\n";
