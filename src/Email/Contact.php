<?php
	namespace ThriveData\ThrivePHP\Email;

	/** An email address and optional display name. */
	class Contact
	{
		public function __construct(
			public string $address,
			public ?string $name = null,
		) {}

		public function rfcAddress()
		{
			return '<'.$this->address.'>';
		}

		public function rfcName()
		{
			return '"'.addcslashes($this->name ?? '', '\\"').'"';
		}

		public function header()
		{
			return $this->name ? $this->rfcName().' '.$this->rfcAddress() : $this->rfcAddress();
		}

		public function array()
		{
			return ['address' => $this->address, 'name' => $this->name];
		}

		public function json()
		{
			return json_encode($this->array(), JSON_THROW_ON_ERROR);
		}
	}
