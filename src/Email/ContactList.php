<?php
	namespace ThriveData\ThrivePHP\Email;

	/** A list of email contacts. */
	class ContactList implements \Iterator
	{
		public array $contacts = [];
		private int $iteratorPosition = 0;

		public function __construct(...$contacts)
		{
			foreach ($contacts as $c):
				$this->append(is_string($c) ? new Contact($c) : $c);
			endforeach;
		}

		public function append(Contact $contact)
		{
			$this->contacts[] = $contact;
		}

		public function rfcArray()
		{
			return array_map(fn($c) => $c->rfcAddress(), $this->contacts);
		}

		public function array()
		{
			return array_map(fn($c) => $c->array(), $this->contacts);
		}

		public function json()
		{
			return json_encode($this->array(), JSON_THROW_ON_ERROR);
		}

		public function rewind(): void { $this->iteratorPosition = 0; }
		public function current(): mixed { return $this->contacts[$this->iteratorPosition]; }
		public function key(): mixed { return $this->iteratorPosition; }
		public function next(): void { ++$this->iteratorPosition; }
		public function valid(): bool { return isset($this->contacts[$this->iteratorPosition]); }
	}
