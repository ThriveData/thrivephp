<?php
	namespace ThriveData\ThrivePHP\Email\SMTP;

	use ThriveData\ThrivePHP\Email\{Message, SendException};
	use ThriveData\ThrivePHP\Log;

	class Session
	{
		public $code;
		public $response;

		public function __construct(
			public Config $config,
			public Message $message,
		) {}

		public function send()
		{
			Log::debug('starting smtp session');
			$recipients = $this->message->rfcRecipientsArray();
			$content = $this->message->content();
			$msg = tmpfile();
			$log = tmpfile();
			if ($msg === false || $log === false):
				if (is_resource($msg)) fclose($msg);
				if (is_resource($log)) fclose($log);
				throw new SendException('Cannot create SMTP temporary files.');
			endif;
			try {
				fwrite($msg, $content);
				rewind($msg);
				$c = curl_init($this->config->curlURL());
				curl_setopt_array($c, [
					CURLOPT_USE_SSL => CURLUSESSL_ALL,
					CURLOPT_USERNAME => $this->config->user,
					CURLOPT_PASSWORD => $this->config->password,
					CURLOPT_MAIL_FROM => $this->message->from->rfcAddress(),
					CURLOPT_MAIL_RCPT => $recipients,
					CURLOPT_UPLOAD => true,
					CURLOPT_READDATA => $msg,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_VERBOSE => true,
					CURLOPT_STDERR => $log,
				]);
				$result = curl_exec($c);
				$this->code = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
				rewind($log);
				$this->response = stream_get_contents($log);
				Log::debug('smtp session done | code='.$this->code);
				if ($result === false):
					throw new SendException(sprintf('smtp code = %s | errno = %s | error = %s',
						$this->code, curl_errno($c), curl_error($c)));
				endif;
			} finally {
				fclose($msg);
				fclose($log);
			}
		}
	}
