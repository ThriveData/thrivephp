<?php
	namespace ThriveData\ThrivePHP\Email;

	class MIMEFile
	{
		/** $data is raw bytes on input, and base64-encoded in the stored property. */
		public function __construct(
			public string $filename,
			public $data = '',
			public string $type = 'application/octet-stream',
			public string $disposition = 'attachment',
			public $headers = [],
		) {
			$this->headers = (array) ($headers ?? []);
			$this->data = chunk_split(base64_encode($data));
		}

		/** A JSON-safe representation for the queue. */
		public function array()
		{
			return [
				'filename' => $this->filename,
				'type' => $this->type,
				'disposition' => $this->disposition,
				'headers' => (object) $this->headers,
				'data' => $this->data,
			];
		}

		public static function fromArray(array $file): self
		{
			$data = base64_decode($file['data'], true);
			if ($data === false):
				throw new Exception('Invalid base64 attachment data.');
			endif;
			return new self(
				filename: $file['filename'],
				data: $data,
				type: $file['type'],
				disposition: $file['disposition'],
				headers: $file['headers'] ?? [],
			);
		}

		public function content()
		{
			$headers = $this->headers;
			$filename = addcslashes($this->filename, '\\"');
			$headers['Content-Disposition'] = sprintf('%s; filename="%s"', $this->disposition, $filename);
			$headers['Content-Type'] = sprintf('%s; name="%s"', $this->type, $filename);
			$headers['Content-Transfer-Encoding'] = 'base64';
			$content = '';
			foreach ($headers as $key => $value):
				$content .= "{$key}: {$value}\r\n";
			endforeach;
			return $content."\r\n".$this->data."\r\n\r\n";
		}
	}
