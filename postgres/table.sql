
create or replace function public.created()
returns trigger language plpgsql as $function$
begin
	
	begin
		raise debug 'setting created_id to %', new.created_id;
		new.created_id := ((current_setting('app.state', true)::jsonb) #>> '{user,id}')::uuid;
	exception
		when undefined_column then
			-- do nothing
	end;
	
	begin
		raise debug 'setting updated_id to %', new.created_id;
		NEW.updated_id := ((current_setting('app.state', true)::jsonb) #>> '{user,id}')::uuid;
	exception
		when undefined_column then
			-- do nothing
	end;
	
	return new;

end;
$function$;


create or replace function public.updated()
returns trigger language plpgsql as $function$
begin
	
	-- the condition is here in the function instead of on the trigger
	-- in order to deal with generated columns
	if (NEW is distinct from OLD) then
		begin
			NEW.updated_when := coalesce(new.updated_when, now());
		exception
			when undefined_column then
				-- do nothing
		end;
		
		begin
			NEW.updated_id := ((current_setting('app.state', true)::jsonb) #>> '{user,id}')::uuid;
		exception
			when undefined_column then
				-- do nothing
		end;
	end if;
	
	return new;

end;
$function$;



create or replace procedure public.provision(
	target_table regclass,
	user_id boolean default true,
	created_when boolean default true,
	created_id boolean default true,
	updated_when boolean default true,
	updated_id boolean default true,
	deleted_when boolean default true,
	deleted_id boolean default true,
	audit boolean default true
)
language plpgsql as $procedure$
begin

	if (user_id is false) then
		created_id := false;
		updated_id := false;
		deleted_id := false;
	end if;
	
	-- created
	
	if (created_when) then
		execute format('alter table %s '
			'add column if not exists created_when timestamptz not null default now()',
			target_table
		);
	end if;
	if (created_id and user_id) then
		execute format('alter table %s '
			'add column if not exists created_id uuid not null references public.users(id) on update cascade deferrable initially deferred',
			target_table
		);
	end if;
	if (created_when or created_id) then
		execute format('create or replace trigger created before insert on %s for each row execute procedure created()', target_table);
	end if;
	
	-- updated

	if (updated_when) then
		execute format('alter table %s '
			'add column if not exists updated_when timestamptz not null default now()',
			target_table
		);
	end if;
	if (updated_id) then
		execute format('alter table %s '
			'add column if not exists updated_id uuid not null references public.users(id) on update cascade deferrable initially deferred',
			target_table
		);
	end if;
	if (updated_when or updated_id) then
		execute format('create or replace trigger updated before update or insert on %s for each row execute procedure updated()', target_table);
	end if;
	
	-- deleted
	
	if (deleted_when) then
		execute format('alter table %s '
			'add column if not exists deleted_when timestamptz',
			target_table
		);
	end if;
	if (deleted_id) then
		execute format('alter table %s '
			'add column if not exists deleted_id uuid references public.users(id) on update cascade deferrable initially deferred',
			target_table
		);
	end if;
	
	-- audit

	if (audit is true) then		
		execute format('create or replace trigger audit after insert or update or delete on %s for each row execute procedure public.audit()', target_table);
	end if;
end;
$procedure$;