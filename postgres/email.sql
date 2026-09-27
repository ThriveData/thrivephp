do $$
begin
	create domain public.email_address as text check (
		value ~* '^[a-z0-9._%+-]+@(?:[a-z0-9-]+\.)+[a-z]{2,16}$'
	);
exception when duplicate_object then null;
end;
$$;

do $$
begin
	create type public.email_contact as (
		address public.email_address,
		name text
	);
exception when duplicate_object then null;
end;
$$;

create table if not exists public.email (
	id uuid primary key default gen_random_uuid(),
	created timestamptz not null default now(),
	sender public.email_contact not null,
	subject text not null,
	headers jsonb,
	message_plain text,
	message_html text,
	attempted_last timestamptz,
	attempted_next timestamptz default now(),
	attempted_count integer not null default 0 check (attempted_count >= 0),
	attempted_max integer not null default 4 check (attempted_max >= 0),
	server_response jsonb,
	sent timestamptz check (sent >= attempted_last),
	check (message_plain is not null or message_html is not null)
);

create table if not exists public.email_contacts_types (
	id text primary key
);

insert into public.email_contacts_types (id) values
	('to'), ('cc'), ('bcc'), ('reply')
on conflict do nothing;

create table if not exists public.email_contacts (
	id uuid primary key default gen_random_uuid(),
	email_id uuid not null references public.email(id) on delete cascade,
	contact_type_id text not null references public.email_contacts_types(id),
	contact public.email_contact not null
);

create table if not exists public.email_files (
	id uuid primary key default gen_random_uuid(),
	email_id uuid not null references public.email(id) on delete cascade,
	position integer not null check (position >= 0),
	filename text not null,
	type text not null default 'application/octet-stream',
	disposition text not null default 'attachment',
	headers jsonb not null default '{}'::jsonb check (jsonb_typeof(headers) = 'object'),
	data bytea not null,
	unique (email_id, position)
);

create index if not exists email_sent_idx on public.email(sent);
create index if not exists email_contacts_type_idx on public.email_contacts(contact_type_id);
create index if not exists email_contacts_contact_idx on public.email_contacts(contact);
create index if not exists email_contacts_email_idx on public.email_contacts(email_id);

-- The complete payload is inserted by one CALL. A failure rolls back every row.
-- Files carry base64 data over JSON; only decoded bytes are stored in the table.
create or replace procedure public.email(
	sender jsonb,
	contacts jsonb,
	subject text,
	plain text,
	html text,
	headers jsonb,
	files jsonb
) language plpgsql as $$
declare
	_email_id uuid;
begin
	if headers is not null and jsonb_typeof(headers) <> 'object' then
		raise exception 'Email headers must be a JSON object' using errcode = '22023';
	end if;
	if files is not null and jsonb_typeof(files) <> 'array' then
		raise exception 'Email files must be a JSON array' using errcode = '22023';
	end if;

	insert into public.email (sender, subject, message_plain, message_html, headers)
		values ((sender->>'address', nullif(sender->>'name', ''))::public.email_contact,
			subject, plain, html, coalesce(headers, '{}'::jsonb))
		returning id into _email_id;

	insert into public.email_contacts (email_id, contact_type_id, contact)
		select _email_id, j.type, (j.address, j.name)::public.email_contact
		from jsonb_to_recordset(contacts) as j(type text, address text, name text);

	insert into public.email_files (email_id, position, filename, type, disposition, headers, data)
		select _email_id, (f.ordinality - 1)::integer,
			f.value->>'filename',
			coalesce(f.value->>'type', 'application/octet-stream'),
			coalesce(f.value->>'disposition', 'attachment'),
			coalesce(f.value->'headers', '{}'::jsonb),
			decode(f.value->>'data', 'base64')
		from jsonb_array_elements(files) with ordinality as f(value, ordinality);
end;
$$;

-- Keep the original JSON signature, including its optional arguments.
create or replace procedure public.email(
	sender jsonb,
	contacts jsonb,
	subject text,
	plain text default null,
	html text default null,
	headers jsonb default null
) language plpgsql as $$
begin
	call public.email(sender, contacts, subject, plain, html, headers, '[]'::jsonb);
end;
$$;

-- The typed interface accepts headers as complete "Name: value" lines.
create or replace procedure public.email(
	sender public.email_contact,
	recipients_to public.email_contact[],
	subject text,
	reply public.email_contact[] default null,
	message_plain text default null,
	message_html text default null,
	recipients_cc public.email_contact[] default null,
	recipients_bcc public.email_contact[] default null,
	headers text[] default null
) language plpgsql as $$
declare
	_contacts jsonb;
	_headers jsonb := '{}'::jsonb;
	_header text;
	_colon integer;
begin
	select coalesce(jsonb_agg(c.value || jsonb_build_object('type', t.kind)), '[]'::jsonb)
	into _contacts
	from (values
		('to', to_jsonb(recipients_to)),
		('cc', to_jsonb(recipients_cc)),
		('bcc', to_jsonb(recipients_bcc)),
		('reply', to_jsonb(reply))
	) as t(kind, contacts)
	cross join lateral jsonb_array_elements(t.contacts) as c(value);

	foreach _header in array coalesce(headers, array[]::text[]) loop
		_colon := strpos(_header, ':');
		if _header is null or _colon < 2 then
			raise exception 'Expected a header name and value' using errcode = '22023';
		end if;
		_headers := _headers || jsonb_build_object(
			btrim(substr(_header, 1, _colon - 1)), btrim(substr(_header, _colon + 1))
		);
	end loop;
	call public.email(to_jsonb(sender), _contacts, subject, message_plain, message_html, _headers, '[]'::jsonb);
end;
$$;

create or replace function public.email_update()
returns trigger language plpgsql as $$
begin
	if new.attempted_count > old.attempted_count then
		new.attempted_last = now();
		new.attempted_next = new.created + make_interval(hours := ((new.attempted_count ^ 2)::integer));
	end if;
	if new.sent is not null then
		new.attempted_next = null;
	end if;
	return new;
end;
$$;

create or replace trigger next_attempt
	before update on public.email
	for each row execute procedure public.email_update();
