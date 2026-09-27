<?php
	spl_autoload_register(function ($class) {
		$prefix = 'ThriveData\\ThrivePHP\\';
		if (str_starts_with($class, $prefix)):
			$file = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
			if (is_file($file)) require_once $file;
		endif;
	});

	function check($condition, string $message): void
	{
		if (!$condition) throw new RuntimeException($message);
	}

	function raises(string $class, callable $operation): void
	{
		try {
			$operation();
		} catch (Throwable $error) {
			check($error instanceof $class, 'Expected '.$class.', got '.get_class($error).': '.$error->getMessage());
			return;
		}
		throw new RuntimeException('Expected '.$class);
	}

	function attachmentBytes(string $content): array
	{
		preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $content, $matches);
		return array_map(fn($data) => base64_decode($data, true), $matches[1]);
	}
