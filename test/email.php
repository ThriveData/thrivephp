<?php
	require __DIR__.'/email-bootstrap.php';

	use ThriveData\ThrivePHP\Email\{Contact, ContactList, Email, FileUnreadableException, Message, MIMEFile, NoRecipientsException};
	use ThriveData\ThrivePHP\Email\SMTP\Config;

	$contacts = new ContactList('one@example.com', new Contact('two@example.com', 'Two'));
	check($contacts->rfcArray() === ['<one@example.com>', '<two@example.com>'], 'Contact address formatting failed');
	check(count(iterator_to_array($contacts)) === 2, 'Contact iteration failed');
	check((new Contact('one@example.com', 'A "quote"'))->header() === '"A \\"quote\\"" <one@example.com>', 'Display-name quoting failed');
	$first = new Message();
	$first->to('one@example.com');
	check((new Message())->to->contacts === [], 'Message contact lists are shared');
	raises(NoRecipientsException::class, fn() => (new Message())->rfcRecipientsArray());

	$binary = "\x00\xff\x80\r\n".implode('', array_map('chr', range(0, 255)));
	$path = tempnam(sys_get_temp_dir(), 'thrive-email-');
	try {
		file_put_contents($path, $binary);
		$email = new Email(
			to: 'one@example.com', from: 'sender@example.com', subject: 'Attachments', plain: "First\nSecond",
			html: '<img src="cid:logo">', bcc: 'hidden@example.com',
			headers: ['X-Tracking-ID' => 'abc', 'Message-ID' => '<abc@example.com>'],
			files: [$path, new MIMEFile('empty.txt', '', 'text/plain')],
		);
	} finally {
		unlink($path);
	}
	$email->message->attach('logo.png', 'image bytes', 'image/png', 'inline', ['Content-ID' => '<logo>']);
	$email->message->header('X-URL: https://example.com/a')->header('X-Empty', '');
	$content = $email->message->content();
	check(str_contains($content, "X-Tracking-ID: abc\r\n"), 'Custom message header missing');
	check(str_contains($content, "X-URL: https://example.com/a\r\n"), 'Header line parsing failed');
	check(str_contains($content, "X-Empty: \r\n"), 'Empty header value lost');
	check(str_contains($content, "Message-ID: <abc@example.com>\r\n"), 'Message-ID missing');
	check(!str_contains($content, 'hidden@example.com'), 'BCC leaked into the message');
	check($email->message->rfcRecipientsArray() === ['<one@example.com>', '<hidden@example.com>'], 'BCC envelope recipient missing');
	check(str_contains($content, "First\r\nSecond"), 'Body line endings are not CRLF');
	check(attachmentBytes($content) === ['image bytes', $binary, ''], 'MIME attachment bytes differ');
	preg_match('/multipart\/related; boundary=([^\r\n]+)/', $content, $related);
	$inline = strpos($content, 'Content-ID: <logo>');
	$relatedEnd = strpos($content, '--'.$related[1].'--');
	check($inline < $relatedEnd, 'Inline attachment is outside multipart/related');
	check(strpos($content, 'filename="empty.txt"') > $relatedEnd, 'Download attachment is inside multipart/related');
	foreach ($email->message->files as $file):
		check(MIMEFile::fromArray($file->array())->content() === $file->content(), 'Attachment array round trip failed');
		$restored = MIMEFile::fromArray(json_decode(json_encode($file->array(), JSON_THROW_ON_ERROR), true));
		check($restored->content() === $file->content(), 'Attachment serialization changed MIME content');
	endforeach;
	raises(FileUnreadableException::class, fn() => (new Message())->file($path));
	raises(InvalidArgumentException::class, fn() => (new Message())->header('Missing colon'));
	$badFile = $email->message->files[0]->array();
	$badFile['data'] = '!invalid!';
	raises(ThriveData\ThrivePHP\Email\Exception::class, fn() => MIMEFile::fromArray($badFile));
	$first->plain('text')->plain(null)->html('html')->html(null);
	check($first->plain === null && $first->html === null, 'Null body parts are not cleared');
	check((new Config('localhost', 'user', 'password', 587))->curlURL() === 'smtp://localhost:587', 'STARTTLS URL failed');
	check((new Config('localhost', 'user', 'password', 465, 'ssl'))->curlURL() === 'smtps://localhost:465', 'Implicit TLS URL failed');

	print "Email composition tests passed.\n";
