<?php
	namespace ThriveData\ThrivePHP\Email;

	use ThriveData\ThrivePHP\{DB, Log};

	class Queue
	{
		static function add(Message $m)
		{
			$m->rfcRecipientsArray();
			$contacts = [];
			foreach (['to', 'cc', 'bcc', 'reply'] as $type):
				foreach ($m->{$type}->array() as $contact):
					$contacts[] = ['type' => $type] + $contact;
				endforeach;
			endforeach;
			DB::query(<<<SQL
				CALL public.email(
					sender := $1::jsonb,
					contacts := $2::jsonb,
					subject := $3::text,
					plain := $4::text,
					html := $5::text,
					headers := $6::jsonb,
					files := $7::jsonb
				)
				SQL,
				$m->from->json(),
				json_encode($contacts, JSON_THROW_ON_ERROR),
				$m->subject,
				$m->plain->content ?? null,
				$m->html->content ?? null,
				json_encode((object) $m->headers, JSON_THROW_ON_ERROR),
				json_encode(array_values(array_map(fn($f) => $f->array(), $m->files)), JSON_THROW_ON_ERROR),
			);
		}

		static function send()
		{
			Log::debug('starting to send email queue');
			$unsent = DB::query(<<<SQL
				SELECT
					e.id,
					to_json(e.sender) AS from,
					to_json(array_agg(c.contact) FILTER (WHERE c.contact_type_id = 'reply')) AS reply,
					to_json(array_agg(c.contact) FILTER (WHERE c.contact_type_id = 'to')) AS recipients_to,
					to_json(array_agg(c.contact) FILTER (WHERE c.contact_type_id = 'cc')) AS recipients_cc,
					to_json(array_agg(c.contact) FILTER (WHERE c.contact_type_id = 'bcc')) AS recipients_bcc,
					e.subject, e.message_plain, e.message_html, e.headers,
					(SELECT json_agg(json_build_object(
						'filename', f.filename, 'type', f.type, 'disposition', f.disposition,
						'headers', f.headers, 'data', encode(f.data, 'base64')
					) ORDER BY f.position) FROM public.email_files AS f WHERE f.email_id = e.id) AS files
				FROM public.email AS e
					JOIN public.email_contacts AS c ON (c.email_id = e.id)
				WHERE e.sent IS NULL
					AND e.attempted_count < e.attempted_max
					AND (e.attempted_next <= now() OR e.attempted_next IS NULL)
				GROUP BY e.id
				ORDER BY e.created
				SQL
			);
			Log::debug("got {$unsent->rows()} from queue");
			while ($u = $unsent->fetch(json: 'array')):
				Log::debug("sending message {$u->id}");
				DB::query('UPDATE public.email SET attempted_last=now(), attempted_count=attempted_count + 1 WHERE id=$1', $u->id);
				$e = null;
				try {
					$contacts = fn($list) => new ContactList(...array_map(fn($c) => new Contact($c['address'], $c['name']), $list ?? []));
					$e = new Email(
						from: new Contact($u->from['address'], $u->from['name']),
						to: $contacts($u->recipients_to),
						cc: $contacts($u->recipients_cc),
						bcc: $contacts($u->recipients_bcc),
						reply: $contacts($u->reply),
						subject: $u->subject,
						plain: $u->message_plain ?? '',
						html: $u->message_html,
						headers: $u->headers ?? [],
						files: array_map(fn($f) => MIMEFile::fromArray($f), $u->files ?? []),
					);
					$e->message->plain($u->message_plain);
					$e->send();
				} catch (\Exception $x) {
					Log::error("could not send message {$u->id}: {$x->getMessage()}");
					DB::query("UPDATE public.email SET server_response=jsonb_build_object('message', $2::text) WHERE id=$1",
						$u->id, $e->session->response ?? $x->getMessage());
					continue;
				}
				DB::query("UPDATE public.email SET server_response=jsonb_build_object('message', $2::text), sent=now() WHERE id=$1",
					$u->id, $e->session->response);
			endwhile;
		}
	}
