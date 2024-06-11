create table public.roles (
	id uuid not null primary key default gen_random_uuid(),
	name text not null unique
);
call public.provision('public.roles'::regclass);


create table public.users_roles (
	id uuid not null primary key default gen_random_uuid(),
	user_id uuid not null references public.users(id),
	role_id uuid not null references public.roles(id)
);
call public.provision('public.users_roles'::regclass);


create table public.acl_keys (
	id uuid not null primary key default gen_random_uuid(),
	key text not null,
	name text not null
);
call public.provision('public.acl_keys'::regclass);


create table public.acl_permissions (
	id uuid not null primary key default gen_random_uuid(),
	role_id uuid not null references public.roles(id),
	key_id uuid not null references public.acl_keys(id),
	permissions integer not null default 0
);
call public.provision('public.acl_permissions'::regclass);


create or replace function public.permissions_compose(
	sel boolean default false,
	upd boolean default false,
	ins boolean default false,
	del boolean default false,
	mgr boolean default false,
	adm boolean default false
) returns bit language sql as $function$
	select (
		(sel::integer * 1)
		+ (upd::integer * 2)
		+ (ins::integer * 4)
		+ (del::integer * 8)
		+ (mgr::integer * 16)
		+ (adm::integer * 32)
	)::bit(6);
$function$;


create or replace function public.permissions_decompose(
	grants bit,
	out sel boolean,
	out upd boolean,
	out ins boolean,
	out del boolean,
	out mgr boolean,
	out adm boolean
)
returns record language plpgsql as $function$
begin
	if (grants is null) then
		return;
	end if;
	if (grants & b'000001'::bit(6) = b'000001'::bit(6)) then sel := true; else sel := false; end if;
	if (grants & b'000010'::bit(6) = b'000010'::bit(6)) then upd := true; else upd := false; end if;
	if (grants & b'000100'::bit(6) = b'000100'::bit(6)) then ins := true; else ins := false; end if;
	if (grants & b'001000'::bit(6) = b'001000'::bit(6)) then del := true; else del := false; end if;
	if (grants & b'010000'::bit(6) = b'010000'::bit(6)) then mgr := true; else mgr := false; end if;
	if (grants & b'100000'::bit(6) = b'100000'::bit(6)) then adm := true; else adm := false; end if;
end;
$function$;
