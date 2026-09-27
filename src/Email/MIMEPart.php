<?php
	namespace ThriveData\ThrivePHP\Email;

	class MIMEPart
	{
		public function __construct(
			public $type,
			public $headers,
			public $content,
		) {}

		public function content()
		{
			$headers = $this->headers;
			$headers['Content-Type'] = $this->type;
			$content = '';
			foreach ($headers as $key => $value):
				$content .= "{$key}: {$value}\r\n";
			endforeach;
			return $content."\r\n".$this->content."\r\n";
		}
	}
