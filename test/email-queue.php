<?php
	// Replace only SMTP transport. Queue writes and reads use a real PostgreSQL database.
	namespace ThriveData\ThrivePHP\Email\SMTP {
		class Session
		{
			public static array $deliveries = [];
			public static bool $fail = false;
			public $response;

			public function __construct(public Config $config, public \ThriveData\ThrivePHP\Email\Message $message) {}

			public function send()
			{
				self::$deliveries[] = ['message' => $this->message, 'content' => $this->message->content()];
				$this->response = self::$fail ? '451 simulated temporary failure' : '250 accepted';
				if (self::$fail) throw new \ThriveData\ThrivePHP\Email\SendException($this->response);
			}
		}
	}

	namespace {
		require __DIR__.'/email-bootstrap.php';

		use ThriveData\ThrivePHP\{DB, Settings};
		use ThriveData\ThrivePHP\Email\{Email, Message, MIMEFile, Queue};
		use ThriveData\ThrivePHP\Email\SMTP\{Config, Session};

		$dsn = getenv('EMAIL_TEST_DSN');
		check((bool) $dsn, 'Set EMAIL_TEST_DSN to an empty test database with postgres/email.sql installed.');
		Settings::$data = [
			'pgsql' => ['connections' => ['default' => $dsn]],
			'email' => ['from' => 'Test sender', 'connections' => ['default' => [
				'host' => 'test.invalid', 'port' => 587, 'user' => 'sender@example.com', 'password' => 'test',
			]]],
			'log' => ['level' => 'fatal'],
		];
		check((int) DB::query('SELECT count(*) AS count FROM public.email')->single()->count === 0, 'Test database must have an empty email queue.');

		// Explicit SMTP configuration must be used without consulting the defaults.
		$explicit = new Email(to: 'one@example.com', from: 'sender@example.com', subject: 'Direct', plain: 'body');
		$config = new Config('explicit.invalid', 'user', 'password');
		$defaults = Settings::$data['email'];
		unset(Settings::$data['email']);
		$explicit->send($config);
		check($explicit->session->config === $config, 'Explicit SMTP config was ignored');
		Settings::$data['email'] = $defaults;
		Session::$deliveries = [];

		$bytes = "\x00\xff\x80".str_repeat("\r\nBinary\x00", 100);
		$path = tempnam(sys_get_temp_dir(), 'thrive-queue-');
		try {
			file_put_contents($path, $bytes);
			$email = new Email(
				to: 'one@example.com', cc: 'copy@example.com', bcc: 'hidden@example.com', reply: 'reply@example.com',
				subject: 'Round trip', plain: "plain\nbody", html: '<img src="cid:logo">',
				headers: ['X-Tracking-ID' => 'abc', 'X-Empty' => '', 'Message-ID' => '<roundtrip@example.com>'],
				files: [$path, new MIMEFile('empty.txt', '', 'text/plain')],
			);
			$email->message->attach('logo.png', 'image bytes', 'image/png', 'inline', ['Content-ID' => '<logo>', 'X-Part' => 'test']);
			$email->queue();
		} finally {
			unlink($path);
		}
		$row = DB::query('SELECT * FROM public.email')->single(json: 'array');
		$id = $row->id;
		check($row->headers == $email->message->headers, 'Stored message headers differ');
		$stored = DB::query("SELECT filename, encode(data, 'base64') AS data, headers FROM public.email_files WHERE email_id=$1 ORDER BY position", $id)->all();
		check(count($stored) === 3, 'Files were not stored');
		check(base64_decode($stored[0]->data) === $bytes && $stored[1]->data === '', 'bytea data differs');
		check($stored[2]->headers->{'Content-ID'} === '<logo>', 'Attachment headers were not stored');

		Session::$fail = true;
		Queue::send();
		$row = DB::query('SELECT *, attempted_next > attempted_last AS scheduled FROM public.email WHERE id=$1', $id)->single();
		check($row->sent === null && (int) $row->attempted_count === 1 && $row->scheduled, 'Failed send did not schedule a retry');
		check($row->server_response->message === '451 simulated temporary failure', 'Failure response was not saved');
		check(count(Session::$deliveries) === 1, 'Queue did not attempt delivery');
		$delivery = Session::$deliveries[0];
		check($delivery['message']->headers == $email->message->headers, 'Delivered headers differ');
		check($delivery['message']->rfcRecipientsArray() === $email->message->rfcRecipientsArray(), 'Envelope recipients differ');
		check($delivery['message']->reply->array() === $email->message->reply->array(), 'Reply contacts differ');
		foreach ($email->message->files as $i => $file):
			check($delivery['message']->files[$i]->array() == $file->array(), 'Delivered attachment metadata or contents differ');
		endforeach;
		check(attachmentBytes($delivery['content']) === ['image bytes', $bytes, ''], 'Delivered MIME attachment contents differ');
		Queue::send();
		check(count(Session::$deliveries) === 1, 'Queue retried before its scheduled time');
		DB::query('UPDATE public.email SET attempted_next=now() WHERE id=$1', $id);
		Session::$fail = false;
		Queue::send();
		$row = DB::query('SELECT * FROM public.email WHERE id=$1', $id)->single();
		check($row->sent !== null && $row->attempted_next === null && (int) $row->attempted_count === 2, 'Successful retry was not recorded');
		check($row->server_response->message === '250 accepted', 'Success response was not saved');
		check(attachmentBytes(Session::$deliveries[1]['content']) === attachmentBytes($delivery['content']), 'Retry changed attachment bytes');
		check(Session::$deliveries[1]['message']->headers == $delivery['message']->headers, 'Retry changed headers');
		Queue::send();
		check(count(Session::$deliveries) === 2, 'Sent email was delivered again');

		// A bad second file must also roll back the message, contacts, and first file.
		$counts = fn() => DB::query('SELECT (SELECT count(*) FROM public.email) AS messages, (SELECT count(*) FROM public.email_contacts) AS contacts, (SELECT count(*) FROM public.email_files) AS files')->single();
		$before = $counts();
		$files = [new MIMEFile('valid.bin', $bytes), new MIMEFile('bad.bin', '')];
		$payload = array_map(fn($f) => $f->array(), $files);
		$payload[1]['data'] = '!not base64!';
		raises(ThriveData\ThrivePHP\DatabaseException::class, fn() => DB::query(
			'CALL public.email($1::jsonb, $2::jsonb, $3::text, $4::text, NULL::text, $5::jsonb, $6::jsonb)',
			'{"address":"sender@example.com"}', '[{"type":"to","address":"one@example.com"}]',
			'Atomic failure', 'body', '{"X-Test":"test"}', json_encode($payload, JSON_THROW_ON_ERROR),
		));
		check($counts() == $before, 'Failed enqueue left partial rows');

		// Both original SQL signatures still work and now preserve their headers.
		DB::query(<<<'SQL'
			CALL public.email(
				sender := '{"address":"sender@example.com"}'::jsonb,
				contacts := '[{"type":"bcc","address":"hidden@example.com"}]'::jsonb,
				subject := 'Legacy JSON', html := '<p>HTML only</p>', headers := '{"X-Legacy":"json"}'::jsonb
			)
			SQL);
		DB::query(<<<'SQL'
			CALL public.email(
				sender := ('sender@example.com', 'Sender')::public.email_contact,
				recipients_to := ARRAY[('one@example.com', 'One')::public.email_contact],
				subject := 'Legacy typed', message_plain := 'body', headers := ARRAY['X-Legacy: typed', 'X-URL: https://example.com']
			)
			SQL);
		(new Email(to: 'one@example.com', subject: 'Plain', plain: 'No attachments'))->queue();
		Queue::send();
		check(count(Session::$deliveries) === 5, 'Messages without attachments failed delivery');
		$messages = [];
		foreach (Session::$deliveries as $item) $messages[$item['message']->subject] = $item['message'];
		check($messages['Legacy JSON']->headers['X-Legacy'] === 'json', 'Legacy JSON headers were lost');
		check($messages['Legacy JSON']->plain === null && $messages['Legacy JSON']->html->content === '<p>HTML only</p>', 'HTML-only message changed');
		check($messages['Legacy JSON']->rfcRecipientsArray() === ['<hidden@example.com>'], 'BCC-only delivery failed');
		check($messages['Legacy typed']->headers == ['X-URL' => 'https://example.com', 'X-Legacy' => 'typed'], 'Typed SQL headers were lost');
		check($messages['Plain']->headers === [] && $messages['Plain']->files === [], 'Empty headers or attachments failed');

		DB::query('DELETE FROM public.email WHERE id=$1', $id);
		check((int) DB::query('SELECT count(*) AS count FROM public.email_files WHERE email_id=$1', $id)->single()->count === 0, 'Deleting a message did not cascade to files');
		print "Email queue integration tests passed. SMTP transport was stubbed.\n";
	}
