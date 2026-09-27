<?php
	namespace ThriveData\ThrivePHP\Email\SMTP;

	class Config
	{
		public function __construct(
			public $host,
			public $user,
			public $password,
			public $port = 465,
			public $secure = 'tls',
		) {}

		public function curlURL()
		{
			$scheme = $this->secure === 'ssl' ? 'smtps' : 'smtp';
			return "{$scheme}://{$this->host}:{$this->port}";
		}
	}
