<?php
	namespace ThriveData\ThrivePHP\Email;

	/** An email with multipart/mixed, related, and alternative MIME sections. */
	class Message
	{
		public array $files = [];

		public function __construct(
			public ?ContactList $to = null,
			public ?string $subject = null,
			public ?MIMEPart $plain = null,
			public ?MIMEPart $html = null,
			public ?Contact $from = null,
			public ?ContactList $cc = null,
			public ?ContactList $bcc = null,
			public ?ContactList $reply = null,
			public $headers = [],
		) {
			$this->to ??= new ContactList();
			$this->cc ??= new ContactList();
			$this->bcc ??= new ContactList();
			$this->reply ??= new ContactList();
		}

		private function contact($list, ContactList|Contact|string|array $contact)
		{
			if ($list === 'from'):
				$this->from = is_string($contact) ? new Contact($contact) : $contact;
			elseif ($contact instanceof ContactList):
				$this->{$list} = $contact;
			else:
				foreach (is_array($contact) ? $contact : [$contact] as $c):
					$this->{$list}->append(is_string($c) ? new Contact($c) : $c);
				endforeach;
			endif;
		}

		public function from(Contact|string $contact)
		{
			$this->contact('from', $contact);
			return $this;
		}

		public function reply(ContactList|Contact|string|array $contact)
		{
			$this->contact('reply', $contact);
			return $this;
		}

		public function to(ContactList|Contact|string|array $contact)
		{
			$this->contact('to', $contact);
			return $this;
		}

		public function cc(ContactList|Contact|string|array $contact)
		{
			$this->contact('cc', $contact);
			return $this;
		}

		public function bcc(ContactList|Contact|string|array $contact)
		{
			$this->contact('bcc', $contact);
			return $this;
		}

		public function subject(string $subject)
		{
			$this->subject = $subject;
			return $this;
		}

		public function plain($content)
		{
			$this->plain = $content === null ? null : new MIMEPart(
				type: 'text/plain',
				headers: ['Content-Transfer-Encoding' => '7bit'],
				content: str_replace("\n", "\r\n", str_replace("\r\n", "\n", $content)),
			);
			return $this;
		}

		public function html($content)
		{
			$this->html = $content === null ? null : new MIMEPart(
				type: 'text/html',
				headers: ['Content-Transfer-Encoding' => '7bit'],
				content: str_replace("\n", "\r\n", str_replace("\r\n", "\n", $content)),
			);
			return $this;
		}

		public function attach($filename, $data, $type = 'application/octet-stream', $disposition = 'attachment', $headers = null)
		{
			$this->files[] = new MIMEFile($filename, $data, $type, $disposition, $headers);
			return $this;
		}

		public function file($file, $disposition = 'attachment', $headers = null)
		{
			if (!is_file($file) || !is_readable($file)):
				throw new FileUnreadableException('Cannot read attachment: '.$file);
			endif;
			$data = file_get_contents($file);
			if ($data === false):
				throw new FileUnreadableException('Cannot read attachment: '.$file);
			endif;
			return $this->attach(basename($file), $data, mime_content_type($file) ?: 'application/octet-stream', $disposition, $headers);
		}

		/** Accept a name/value pair or a complete "Name: value" line. */
		public function header(string $header, ?string $value = null)
		{
			if ($value === null):
				$parts = explode(':', $header, 2);
				if (count($parts) !== 2):
					throw new \InvalidArgumentException('Expected a header name and value.');
				endif;
				[$header, $value] = array_map('trim', $parts);
			endif;
			$this->headers[$header] = $value;
			return $this;
		}

		/** Return the RFC5322 message for the SMTP DATA command. */
		public function content()
		{
			$boundary = bin2hex(random_bytes(16));
			$boundary_mixed = $boundary.'-mixed';
			$boundary_related = $boundary.'-related';
			$boundary_altern = $boundary.'-altern';
			$headers = $this->headers;
			$headers['X-Mailer'] = 'PHP '.phpversion();
			$headers['MIME-Version'] = '1.0';
			$headers['Content-Type'] = 'multipart/mixed; boundary='.$boundary_mixed;
			$headers['Date'] = date(DATE_RFC822);
			$headers['From'] = $this->from?->header();
			$headers['Subject'] = $this->subject;
			foreach (['To' => $this->to, 'CC' => $this->cc, 'Reply-To' => $this->reply] as $name => $contacts):
				if ($contacts->contacts):
					$headers[$name] = implode(', ', array_map(fn($c) => $c->header(), $contacts->contacts));
				endif;
			endforeach;
			$content = '';
			foreach ($headers as $key => $value):
				$content .= "{$key}: {$value}\r\n";
			endforeach;
			$content .= "\r\n--{$boundary_mixed}\r\n".
				"Content-Type: multipart/related; boundary={$boundary_related}\r\n\r\n".
				"--{$boundary_related}\r\n".
				"Content-Type: multipart/alternative; boundary={$boundary_altern}\r\n\r\n";
			foreach ([$this->plain, $this->html] as $part):
				if ($part):
					$content .= "--{$boundary_altern}\r\n".$part->content()."\r\n";
				endif;
			endforeach;
			$content .= "--{$boundary_altern}--\r\n\r\n";
			foreach ($this->files as $file):
				if (strcasecmp($file->disposition, 'inline') === 0):
					$content .= "--{$boundary_related}\r\n".$file->content()."\r\n";
				endif;
			endforeach;
			$content .= "--{$boundary_related}--\r\n\r\n";
			foreach ($this->files as $file):
				if (strcasecmp($file->disposition, 'inline') !== 0):
					$content .= "--{$boundary_mixed}\r\n".$file->content()."\r\n";
				endif;
			endforeach;
			return $content."--{$boundary_mixed}--\r\n\r\n";
		}

		public function rfcRecipientsArray()
		{
			$mailboxes = array_merge($this->to->rfcArray(), $this->cc->rfcArray(), $this->bcc->rfcArray());
			if (!$mailboxes):
				throw new NoRecipientsException('mailbox list is empty');
			endif;
			return $mailboxes;
		}
	}
