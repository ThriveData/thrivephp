<?php
	namespace ThriveData\ThrivePHP\Email;

	use ThriveData\ThrivePHP\Email\SMTP\{Config, Session};
	use ThriveData\ThrivePHP\Settings;

	/** Compose an email, then send it directly or queue it for later delivery. */
	class Email
	{
		public Message $message;
		public Config $config;
		public Session $session;

		/** $files accepts file paths or MIMEFile instances. */
		public function __construct(
			Contact|ContactList|string|array $to,
			string $subject,
			string $plain,
			Contact|ContactList|string|array|null $cc = null,
			Contact|ContactList|string|array|null $bcc = null,
			Contact|string|null $from = null,
			Contact|ContactList|string|array|null $reply = null,
			?string $html = null,
			?array $headers = null,
			?array $files = null,
		) {
			$this->message = new Message();
			$this->message->to($to)->subject($subject)->plain($plain);
			$this->message->from($from ?? new Contact(
				address: Settings::get('email.connections.default.user'),
				name: Settings::get('email.from'),
			));
			if ($reply !== null) $this->message->reply($reply);
			if ($cc !== null) $this->message->cc($cc);
			if ($bcc !== null) $this->message->bcc($bcc);
			if ($html !== null) $this->message->html($html);
			if ($headers !== null) $this->message->headers = $headers;
			foreach ($files ?? [] as $file):
				if ($file instanceof MIMEFile):
					$this->message->files[] = $file;
				else:
					$this->message->file($file);
				endif;
			endforeach;
		}

		public function send(?Config $config = null)
		{
			$this->config = $config ?? new Config(
				host: Settings::get('email.connections.default.host'),
				port: Settings::get('email.connections.default.port'),
				user: Settings::get('email.connections.default.user'),
				password: Settings::get('email.connections.default.password'),
				secure: Settings::fetch('email.connections.default.secure') ?? 'tls',
			);
			$this->session = new Session(config: $this->config, message: $this->message);
			$this->session->send();
			return $this;
		}

		public function queue()
		{
			Queue::add($this->message);
			return $this;
		}
	}
