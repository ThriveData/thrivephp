<?
	namespace ThriveData\ThrivePHP;
	
	/**
	 * Query access control for users and roles.
	 *
	 * There are four database tables involved in access control:
	 * - roles table: The roles table is a list of roles that can be assigned to users.
	 * - users_roles: The users_roles table stores all the roles assigned to users.
	 * - acl_keys: The acl_keys table is a list of items that represent things for which
	 *    access should be controlled. Most keys represent a database tables or data objects.
	 * - acl_permissions: The acl_permissions table stores the permissions granted permissions
	 *    granted for a key to a role.
	 *
	 * Example: Johnny Appleseed is a sales manager and needs permissions to create, edit, and
	 * delete estimates for himself and his team. Johnny will need to be assigned the Sales Manager role.
	 * And the Sales Manager role should be given access to the key `data.estimates` and permission grants
	 * of `select`, `update`, `create`, `delete`, and `manager`.
	 *
	 */
	class ACL
	{
		const none = 0;
		const select = 1;   // User has read/view/select permission for items belonging to himself
		const update = 2;   // User has write/save/update permission for items belonging to himeself
		const insert = 4;   // User has write/create/insert permission for items belonging to himself
		const delete = 8;   // User has delete permission for items belonging to himself
		const manager = 16;   // User has the above permissions for items belonging to users for who he is a manager
		const admin = 32;	// User has the above permissions for all items
		const grant = 64;   // User has permission to grant to other users
		const revoke = 128;   // User has permission to revoke from other users
		
		/**
		 * Check if `$userId` is granted `$permissions` for `$key`.
		 *
		 * The call `check('data.estimates', ACL::select)` will check if the current user
		 * can view estimates for himself only. The call `check('data.estimates', ACL::select|ACL::manager)`
		 * will check if the current user can view estimates for himself and anyone for who he is a manager.
		 *
		 * The expression `ACL::select|ACL::manager`, where `|` is the binary OR operator, means the
		 * user needs to have both select and manager grants. It can also be expressed like this:
		 *
		 * ```
		 *    Binary:    Decimal:
		 *      10000       1
		 *    | 00001    + 16
		 *      -----      --
		 *      10001      17
		 * ```
		 *
		 * @param string $key The key from the `acl_keys` database table.
		 *
		 * @param int $permissions The granted permissions for `$key`. Possible permissions are:
		 * - ACL::none
		 * - ACL::select
		 * - ACL::update
		 * - ACL::create
		 * - ACL::delete
		 * - ACL::manager
		 * - ACL::admin
		 *
		 * @param uuid $userId The user's ID from the `users` database table.
		 */
		static function check($key, $permissions, $userId=null)
		{
			$userId = $userId ?? $_SESSION['user']['id'] ?? null;
			$trace = sprintf('acl::check(key: %s, permissions: %s, userId: %s)', $key, $permissions, $userId);
			Log::trace($trace);
			if (is_null($userId)) throw new PermissionException('could not get user ID to check permissions');
			
			// Check permissions with session (avoids potentially hundreds of database lookups)
			if ($userId === ($_SESSION['user']['id'] ?? null) && is_array($_SESSION['permissions'] ?? null)):
				$superuser = $_SESSION['user']['superuser'] ?? null;
				$grants = $_SESSION['permissions'][$key] ?? 0;
				Log::trace($trace.' | found session permissions');
				
				if ($superuser):
					Log::trace($trace.' | is superuser');
					return true;
				endif;
				
				if (($grants & $permissions) == $permissions):
					Log::trace($trace.' | grant found');
					return true;
				endif;
				
			// Check permissions with database lookup
			else:
				Log::trace($trace.' | session permissions not found for user');
				// Check if user is superuser
				$superuser = DB::query("SELECT count(*) FROM users WHERE id=$1 AND superuser IS TRUE", $userId)->single()->count;
				if ($superuser):
					Log::trace($trace.' | is superuser');
					return true;
				endif;
				
				$permitted = DB::query("SELECT public.permissions_check($1::text, $2::integer::bit(6), $3::uuid)", $key, $permissions, $userId)->single()->permissions_check;
				if ($permitted):
					Log::trace($trace.' | grant found');
					return true;
				endif;
			endif;
			
			Log::trace($trace.' | check failed');
			return false;
		}
		
		/**
		 * Same as `check()` except throw exception instead of returning boolean.
		 */
		static function assert($key, $permissions, $userId=null)
		{
			if (self::check($key, $permissions, $userId))
				return true;
				
			throw new PermissionDenied('User does not have permission.',
				context: ['key' => $key, 'permissions' => $permissions, 'userId' => $userId]);
		}
		
		/**
		 * Check if `$userId` has given `$role`.
		 */
		static function role($role)
		{
			$userId = $userId ?? $_SESSION['user']['id'] ?? null;
			if (is_null($userId)) throw new PermissionsException('could not get user ID to check roles.');
			
			if (isset($_SESSION['session']['roles'][$role])):
				return true;
			endif;
		}
		
		/**
		 * Same as `assert()` except error message is specifically about page access.
		 */
		static function page($key, $permissions)
		{
			try {
				self::assert($key, $permissions);
			} catch (PermissionDenied $e) {
				throw new PermissionDenied('Access to page is not allowed.', previous: $e);
			}
		}
		
		/**
		 * Get users that have given permissions.
		 */
		static function users($key, $permissions)
		{
			$users = DB::query(<<<SQL
				SELECT
					u.id,
					u.email
				FROM public.roles_permissions AS p
					JOIN public.permissions AS k ON (p.key_id = k.id)
					JOIN public.roles AS r ON (p.role_id = r.id)
					JOIN public.users_roles AS ur ON (ur.role_id = r.id)
					JOIN public.users AS u ON (ur.user_id = u.id)
				WHERE k.id = $1 AND p.permissions & $2::integer::bit(6) = $2::integer::bit(6)
				SQL,
				$key, $permissions
			);
			
			return $users;
		}
		
		/**
		 * Get permissions info to be stored in the user's login session.
		 */
		static function session($user_id)
		{
			$json = DB::query(<<<'SQL'
				WITH t_user AS (
					SELECT u.id,
						json_build_object(
							'id', u.id,
							'login', u.login,
							'email', u.email,
							'name', u.name,
							'superuser', u.superuser
						) AS data
					FROM public.users AS u WHERE u.id=$1
				),
				t_acl AS (
					WITH t AS(
						SELECT
							k.id AS key,
							bit_or(p.permissions)::integer AS permissions
						FROM
							public.roles_permissions AS p
							JOIN public.permissions AS k ON (p.key_id = k.id)
							JOIN public.roles AS r ON (p.role_id = r.id)
							JOIN public.users_roles AS ur ON (ur.role_id = r.id)
							JOIN public.users AS u ON (ur.user_id = u.id)
						WHERE u.id=(SELECT id FROM t_user)
						GROUP BY k.id
					)
					SELECT json_object_agg(t.key, t.permissions ORDER BY t.key) AS data FROM t
				),
				t_roles AS (
					SELECT json_object_agg(r.id, true) AS data
					FROM public.users AS u
						JOIN public.users_roles AS ur ON (ur.user_id = u.id)
						JOIN public.roles AS r ON (ur.role_id = r.id)
					WHERE u.id = (SELECT id FROM t_user)
				)
				SELECT json_build_object(
					'user', (SELECT data FROM t_user),
					'roles', (SELECT data FROM t_roles),
					'permissions', (SELECT data FROM t_acl)
				) AS data
				SQL, $user_id
			)->single(json: 'array')->data;
			
			return $json;
		}
	}
	
	class PermissionException extends ContextException {}
	class PermissionDenied extends PermissionException {}
	
?>
