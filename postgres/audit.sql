create or replace function public.audit() returns trigger language plpgsql as $function$
begin
	if (TG_OP = 'UPDATE' and NEW is distinct from OLD) then
		insert into public.audit(tbl, opr, role, usr, before, after, query)
			values (tg_relid, tg_op, session_user, current_setting('user.id', true)::uuid, row_to_json(old.*), row_to_json(new.*), current_query());
		return new;
	elsif (TG_OP = 'INSERT') then
		insert into public.audit(tbl, opr, role, usr, before, after, query)
			values (tg_relid, tg_op, session_user, current_setting('user.id', true)::uuid, null, row_to_json(new.*), current_query());
		return new;
	elsif (TG_OP = 'DELETE') THEN
		insert into public.audit(tbl, opr, role, usr, before, after, query)
			values (tg_relid, tg_op, session_user, current_setting('user.id', true)::uuid, row_to_json(old.*), null, current_query());
		return old;
    end if;
    return null;
end;
$function$;

create table if not exists public.audit (
	id uuid primary key default gen_random_uuid(),
	created_when timestamptz not null default now(),
	tbl regclass not null,
	opr text not null,
	role text not null,
	usr uuid,
	before jsonb,
	after jsonb,
	query text
);

create index on public.audit (created_when);
create index on public.audit (tbl);
create index on public.audit (opr);
create index on public.audit (role);
create index on public.audit (usr);
create index on public.audit using gin (before jsonb_path_ops);
create index on public.audit using gin (after jsonb_path_ops);
