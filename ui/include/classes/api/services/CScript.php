<?php
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class containing methods for operations with scripts.
 */
class CScript extends CApiService {

	public const ACCESS_RULES = [
		'get' => ['min_user_type' => USER_TYPE_ZABBIX_USER],
		'getscriptsbyhosts' => ['min_user_type' => USER_TYPE_ZABBIX_USER],
		'create' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'update' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'delete' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'execute' => ['min_user_type' => USER_TYPE_ZABBIX_USER, 'action' => CRoleHelper::ACTIONS_EXECUTE_SCRIPTS]
	];

	protected $tableName = 'scripts';
	protected $tableAlias = 's';
	protected $sortColumns = ['scriptid', 'name'];

	/**
	 * This property, if filled out, will contain all hostrgroup ids
	 * that requested scripts did inherit from.
	 * Keyed by scriptid.
	 *
	 * @var array|HostGroup[]
	 */
	protected $parent_host_groups = [];

	/**
	 * @param array $options
	 *
	 * @throws APIException if the input is invalid.
	 *
	 * @return array|int
	 */
	public function get(array $options) {
		$script_fields = ['scriptid', 'name', 'command', 'host_access', 'usrgrpid', 'groupid', 'description',
			'confirmation', 'type', 'execute_on', 'timeout', 'parameters', 'scope', 'port', 'authtype', 'username',
			'password', 'publickey', 'privatekey', 'menu_path'
		];
		$group_fields = ['groupid', 'name', 'flags', 'internal'];
		$host_fields = ['hostid', 'host', 'name', 'description', 'status', 'proxy_hostid', 'inventory_mode', 'flags',
			'ipmi_authtype', 'ipmi_privilege', 'ipmi_username', 'ipmi_password', 'maintenanceid', 'maintenance_status',
			'maintenance_type', 'maintenance_from', 'tls_connect', 'tls_accept', 'tls_issuer', 'tls_subject'
		];

		$api_input_rules = ['type' => API_OBJECT, 'fields' => [
			// filter
			'scriptids' =>				['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'default' => null],
			'hostids' =>				['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'default' => null],
			'groupids' =>				['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'default' => null],
			'usrgrpids' =>				['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'default' => null],
			'filter' =>					['type' => API_OBJECT, 'flags' => API_ALLOW_NULL, 'default' => null, 'fields' => [
				'scriptid' =>				['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'name' =>					['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'command' =>				['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'host_access' =>			['type' => API_INTS32, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'in' => implode(',', [PERM_READ, PERM_READ_WRITE])],
				'usrgrpid' =>				['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'groupid' =>				['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'confirmation' =>			['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'type' =>					['type' => API_INTS32, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'in' => implode(',', [ZBX_SCRIPT_TYPE_CUSTOM_SCRIPT, ZBX_SCRIPT_TYPE_IPMI, ZBX_SCRIPT_TYPE_SSH, ZBX_SCRIPT_TYPE_TELNET, ZBX_SCRIPT_TYPE_WEBHOOK])],
				'execute_on' =>				['type' => API_INTS32, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'in' => implode(',', [ZBX_SCRIPT_EXECUTE_ON_AGENT, ZBX_SCRIPT_EXECUTE_ON_SERVER, ZBX_SCRIPT_EXECUTE_ON_PROXY])],
				'scope' =>					['type' => API_INTS32, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'in' => implode(',', [ZBX_SCRIPT_SCOPE_ACTION, ZBX_SCRIPT_SCOPE_HOST, ZBX_SCRIPT_SCOPE_EVENT])],
				'menu_path' =>				['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE]
			]],
			'search' =>					['type' => API_OBJECT, 'flags' => API_ALLOW_NULL, 'default' => null, 'fields' => [
				'name' =>					['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'command' =>				['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'description' =>			['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'confirmation' =>			['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'username' =>				['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE],
				'menu_path' =>				['type' => API_STRINGS_UTF8, 'flags' => API_ALLOW_NULL | API_NORMALIZE]
			]],
			'searchByAny' =>			['type' => API_BOOLEAN, 'default' => false],
			'startSearch' =>			['type' => API_FLAG, 'default' => false],
			'excludeSearch' =>			['type' => API_FLAG, 'default' => false],
			'searchWildcardsEnabled' =>	['type' => API_BOOLEAN, 'default' => false],
			// output
			'output' =>					['type' => API_OUTPUT, 'in' => implode(',', $script_fields), 'default' => API_OUTPUT_EXTEND],
			'selectGroups' =>			['type' => API_OUTPUT, 'flags' => API_ALLOW_NULL, 'in' => implode(',', $group_fields), 'default' => null],
			'selectHosts' =>			['type' => API_OUTPUT, 'flags' => API_ALLOW_NULL, 'in' => implode(',', $host_fields), 'default' => null],
			'countOutput' =>			['type' => API_FLAG, 'default' => false],
			// sort and limit
			'sortfield' =>				['type' => API_STRINGS_UTF8, 'flags' => API_NORMALIZE, 'in' => implode(',', $this->sortColumns), 'uniq' => true, 'default' => []],
			'sortorder' =>				['type' => API_SORTORDER, 'default' => []],
			'limit' =>					['type' => API_INT32, 'flags' => API_ALLOW_NULL, 'in' => '1:'.ZBX_MAX_INT32, 'default' => null],
			// flags
			'editable' =>				['type' => API_BOOLEAN, 'default' => false],
			'preservekeys' =>			['type' => API_BOOLEAN, 'default' => false]
		]];
		if (!CApiInputValidator::validate($api_input_rules, $options, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$sql_parts = [
			'select'	=> ['scripts' => 's.scriptid'],
			'from'		=> ['scripts' => 'scripts s'],
			'where'		=> [],
			'order'		=> []
		];

		// editable + permission check
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN) {
			if ($options['editable']) {
				return $options['countOutput'] ? 0 : [];
			}

			$user_groups = getUserGroupsByUserId(self::$userData['userid']);

			$sql_parts['where'][] = '(s.usrgrpid IS NULL OR '.dbConditionInt('s.usrgrpid', $user_groups).')';
			$sql_parts['where'][] = '(s.groupid IS NULL OR EXISTS ('.
				'SELECT NULL'.
				' FROM rights r'.
				' WHERE s.groupid=r.id'.
					' AND '.dbConditionInt('r.groupid', $user_groups).
				' GROUP BY r.id'.
				' HAVING MIN(r.permission)>'.PERM_DENY.
			'))';
		}

		$host_groups = null;
		$host_groups_by_hostids = null;
		$host_groups_by_groupids = null;

		// Hostids and groupids selection API calls must be made separately because we must intersect enriched groupids.
		if ($options['hostids'] !== null) {
			$host_groups_by_hostids = enrichParentGroups(API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'hostids' => $options['hostids'],
				'preservekeys' => true
			]));
		}
		if ($options['groupids'] !== null) {
			$host_groups_by_groupids = enrichParentGroups(API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $options['groupids'],
				'preservekeys' => true
			]));
		}

		if ($host_groups_by_groupids !== null && $host_groups_by_hostids !== null) {
			$host_groups = array_intersect_key($host_groups_by_hostids, $host_groups_by_groupids);
		}
		elseif ($host_groups_by_hostids !== null) {
			$host_groups = $host_groups_by_hostids;
		}
		elseif ($host_groups_by_groupids !== null) {
			$host_groups = $host_groups_by_groupids;
		}

		if ($host_groups !== null) {
			$sql_parts['where'][] = '('.dbConditionInt('s.groupid', array_keys($host_groups)).' OR s.groupid IS NULL)';
			$this->parent_host_groups = $host_groups;
		}

		// usrgrpids
		if ($options['usrgrpids'] !== null) {
			$sql_parts['where'][] = '(s.usrgrpid IS NULL OR '.dbConditionInt('s.usrgrpid', $options['usrgrpids']).')';
		}

		// scriptids
		if ($options['scriptids'] !== null) {
			$sql_parts['where'][] = dbConditionInt('s.scriptid', $options['scriptids']);
		}

		// search
		if ($options['search'] !== null) {
			zbx_db_search('scripts s', $options, $sql_parts);
		}

		// filter
		if ($options['filter'] !== null) {
			$this->dbFilter('scripts s', $options, $sql_parts);
		}

		$db_scripts = [];

		$sql_parts = $this->applyQueryOutputOptions($this->tableName(), $this->tableAlias(), $options, $sql_parts);
		$sql_parts = $this->applyQuerySortOptions($this->tableName(), $this->tableAlias(), $options, $sql_parts);

		$result = DBselect(self::createSelectQueryFromParts($sql_parts), $options['limit']);

		while ($db_script = DBfetch($result)) {
			if ($options['countOutput']) {
				return $db_script['rowscount'];
			}

			$db_scripts[$db_script['scriptid']] = $db_script;
		}

		if ($db_scripts) {
			$db_scripts = $this->addRelatedObjects($options, $db_scripts);
			$db_scripts = $this->unsetExtraFields($db_scripts, ['scriptid', 'groupid', 'host_access'],
				$options['output']
			);

			if (!$options['preservekeys']) {
				$db_scripts = array_values($db_scripts);
			}
		}

		return $db_scripts;
	}

	/**
	 * @param array $scripts
	 *
	 * @return array
	 */
	public function create(array $scripts) {
		$this->validateCreate($scripts);

		$scriptids = DB::insert('scripts', $scripts);
		$scripts_params = [];

		foreach ($scripts as $index => &$script) {
			$script['scriptid'] = $scriptids[$index];

			if ($script['type'] == ZBX_SCRIPT_TYPE_WEBHOOK && array_key_exists('parameters', $script)) {
				foreach ($script['parameters'] as $param) {
					$scripts_params[] = ['scriptid' => $script['scriptid']] + $param;
				}
			}
		}
		unset($script);

		if ($scripts_params) {
			DB::insertBatch('script_param', $scripts_params);
		}

		$this->addAuditBulk(AUDIT_ACTION_ADD, AUDIT_RESOURCE_SCRIPT, $scripts);

		return ['scriptids' => $scriptids];
	}

	/**
	 * @param array $scripts
	 *
	 * @throws APIException if the input is invalid
	 */
	protected function validateCreate(array &$scripts) {
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('You do not have permission to perform this operation.'));
		}

		/*
		 * Get general validation rules and firstly validate name uniqueness and all the possible fields, so that there
		 * are no invalid fields for any of the script types. Unfortunaly there is also a drawback, since field types
		 * validated before we know what rules belong to each script type.
		 */
		$api_input_rules = $this->getValidationRules('create', $common_fields);

		if (!CApiInputValidator::validate($api_input_rules, $scripts, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		/*
		 * Then validate each script separately. Depending on script type, each script may have different set of allowed
		 * fields. Then in case the type is SSH and authtype is set, validate parameters again.
		 */
		$i = 0;
		$check_names = [];

		foreach ($scripts as $script) {
			$path = '/'.++$i;

			$type_rules = $this->getTypeValidationRules($script['type'], 'create', $type_fields);
			$scope_rules = $this->getScopeValidationRules($script['scope'], $scope_fields);

			$type_rules['fields'] += $common_fields + $scope_fields;

			if (!CApiInputValidator::validate($type_rules, $script, $path, $error)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, $error);
			}

			if (array_key_exists('authtype', $script)) {
				$ssh_rules = $this->getAuthTypeValidationRules($script['authtype'], 'create');
				$ssh_rules['fields'] += $common_fields + $type_fields + $scope_fields;

				if (!CApiInputValidator::validate($ssh_rules, $script, $path, $error)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, $error);
				}
			}

			$check_names[$script['name']] = true;
		}

		$db_script_names = API::getApiService()->select('scripts', [
			'output' => ['scriptid'],
			'filter' => ['name' => array_keys($check_names)]
		]);

		if ($db_script_names) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Script "%1$s" already exists.', $script['name']));
		}

		// Finally check User and Host IDs.
		$this->checkUserGroups($scripts);
		$this->checkHostGroups($scripts);
	}

	/**
	 * @param array $scripts
	 *
	 * @return array
	 */
	public function update(array $scripts) {
		$this->validateUpdate($scripts, $db_scripts);

		$upd_scripts = [];
		$scripts_params = [];

		foreach ($scripts as $script) {
			$scriptid = $script['scriptid'];
			$db_script = $db_scripts[$scriptid];
			$db_type = $db_script['type'];
			$db_authtype = $db_script['authtype'];
			$db_scope = $db_script['scope'];
			$type = array_key_exists('type', $script) ? $script['type'] : $db_type;
			$authtype = array_key_exists('authtype', $script) ? $script['authtype'] : $db_authtype;
			$scope = array_key_exists('scope', $script) ? $script['scope'] : $db_scope;

			$upd_script = [];

			// strings
			foreach (['name', 'command', 'description', 'confirmation', 'timeout', 'menu_path', 'username', 'publickey',
					'privatekey', 'password'] as $field_name) {
				if (array_key_exists($field_name, $script) && $script[$field_name] !== $db_script[$field_name]) {
					$upd_script[$field_name] = $script[$field_name];
				}
			}

			// integers
			foreach (['type', 'execute_on', 'usrgrpid', 'groupid', 'host_access', 'scope', 'port', 'authtype']
					as $field_name) {
				if (array_key_exists($field_name, $script) && $script[$field_name] != $db_script[$field_name]) {
					$upd_script[$field_name] = $script[$field_name];
				}
			}

			// No mattter what the old type was, clear and reset all unnecessary fields from any other types.
			if ($type != $db_type) {
				switch ($type) {
					case ZBX_SCRIPT_TYPE_CUSTOM_SCRIPT:
						$upd_script['port'] = '';
						$upd_script['authtype'] = DB::getDefault('scripts', 'authtype');
						$upd_script['username'] = '';
						$upd_script['password'] = '';
						$upd_script['publickey'] = '';
						$upd_script['privatekey'] = '';
					break;

					case ZBX_SCRIPT_TYPE_IPMI:
						$upd_script['port'] = '';
						$upd_script['authtype'] = DB::getDefault('scripts', 'authtype');
						$upd_script['username'] = '';
						$upd_script['password'] = '';
						$upd_script['publickey'] = '';
						$upd_script['privatekey'] = '';
						$upd_script['execute_on'] = DB::getDefault('scripts', 'execute_on');
						break;

					case ZBX_SCRIPT_TYPE_SSH:
						$upd_script['execute_on'] = DB::getDefault('scripts', 'execute_on');
					break;

					case ZBX_SCRIPT_TYPE_TELNET:
						$upd_script['authtype'] = DB::getDefault('scripts', 'authtype');
						$upd_script['publickey'] = '';
						$upd_script['privatekey'] = '';
						$upd_script['execute_on'] = DB::getDefault('scripts', 'execute_on');
					break;

					case ZBX_SCRIPT_TYPE_WEBHOOK:
						$upd_script['port'] = '';
						$upd_script['authtype'] = DB::getDefault('scripts', 'authtype');
						$upd_script['username'] = '';
						$upd_script['password'] = '';
						$upd_script['publickey'] = '';
						$upd_script['privatekey'] = '';
						$upd_script['execute_on'] = DB::getDefault('scripts', 'execute_on');
					break;
				}
			}
			elseif ($type == ZBX_SCRIPT_TYPE_SSH && $authtype != $db_authtype && $authtype == ITEM_AUTHTYPE_PASSWORD) {
				$upd_script['publickey'] = '';
				$upd_script['privatekey'] = '';
			}

			if ($scope != $db_scope && $scope == ZBX_SCRIPT_SCOPE_ACTION) {
				$upd_script['menu_path'] = '';
				$upd_script['usrgrpid'] = 0;
				$upd_script['host_access'] = DB::getDefault('scripts', 'host_access');;
				$upd_script['confirmation'] = '';
			}

			if ($type == ZBX_SCRIPT_TYPE_WEBHOOK && array_key_exists('parameters', $script)) {
				$params = [];

				foreach ($script['parameters'] as $param) {
					$params[$param['name']] = $param['value'];
				}

				$scripts_params[$scriptid] = $params;
				unset($script['parameters']);
			}

			if ($type != $db_type && $db_type == ZBX_SCRIPT_TYPE_WEBHOOK) {
				$upd_script['timeout'] = DB::getDefault('scripts', 'timeout');
				$scripts_params[$scriptid] = [];
			}

			if ($upd_script) {
				$upd_scripts[] = [
					'values' => $upd_script,
					'where' => ['scriptid' => $scriptid]
				];
			}
		}

		if ($upd_scripts) {
			DB::update('scripts', $upd_scripts);
		}

		if ($scripts_params) {
			$insert_script_param = [];
			$delete_script_param = [];
			$update_script_param = [];
			$db_scripts_params = DB::select('script_param', [
				'output' => ['script_paramid', 'scriptid', 'name', 'value'],
				'filter' => ['scriptid' => array_keys($scripts_params)]
			]);

			foreach ($db_scripts_params as $param) {
				$scriptid = $param['scriptid'];

				if (!array_key_exists($param['name'], $scripts_params[$scriptid])) {
					$delete_script_param[] = $param['script_paramid'];
				}
				elseif ($scripts_params[$scriptid][$param['name']] !== $param['value']) {
					$update_script_param[] = [
						'values' => ['value' => $scripts_params[$scriptid][$param['name']]],
						'where' => ['script_paramid' => $param['script_paramid']]
					];
					unset($scripts_params[$scriptid][$param['name']]);
				}
				else {
					unset($scripts_params[$scriptid][$param['name']]);
				}
			}

			$scripts_params = array_filter($scripts_params);

			foreach ($scripts_params as $scriptid => $params) {
				foreach ($params as $name => $value) {
					$insert_script_param[] = compact('scriptid', 'name', 'value');
				}
			}

			if ($delete_script_param) {
				DB::delete('script_param', ['script_paramid' => array_keys(array_flip($delete_script_param))]);
			}

			if ($update_script_param) {
				DB::update('script_param', $update_script_param);
			}

			if ($insert_script_param) {
				DB::insert('script_param', $insert_script_param);
			}
		}

		$this->addAuditBulk(AUDIT_ACTION_UPDATE, AUDIT_RESOURCE_SCRIPT, $scripts, $db_scripts);

		return ['scriptids' => zbx_objectValues($scripts, 'scriptid')];
	}

	/**
	 * @param array $scripts
	 * @param array $db_scripts
	 *
	 * @throws APIException if the input is invalid
	 */
	protected function validateUpdate(array &$scripts, array &$db_scripts = null) {
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('You do not have permission to perform this operation.'));
		}

		/*
		 * Get general validation rules and firstly validate name uniqueness and all the possible fields, so that there
		 * are no invalid fields for any of the script types. Unfortunaly there is also a drawback, since field types
		 * validated before we know what rules belong to each script type.
		 */
		$api_input_rules = $this->getValidationRules('update', $common_fields);

		if (!CApiInputValidator::validate($api_input_rules, $scripts, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		// Continue to validate script name.
		$db_scripts = DB::select('scripts', [
			'output' => ['scriptid', 'name', 'command', 'host_access', 'usrgrpid', 'groupid', 'description',
				'confirmation', 'type', 'execute_on', 'timeout', 'scope', 'port', 'authtype', 'username', 'password',
				'publickey', 'privatekey', 'menu_path'
			],
			'scriptids' => zbx_objectValues($scripts, 'scriptid'),
			'preservekeys' => true
		]);

		$check_names = [];
		foreach ($scripts as $script) {
			if (!array_key_exists($script['scriptid'], $db_scripts)) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}

			if (array_key_exists('name', $script)) {
				$check_names[$script['name']] = true;
			}
		}

		if ($check_names) {
			$db_script_names = API::getApiService()->select('scripts', [
				'output' => ['scriptid', 'name'],
				'filter' => ['name' => array_keys($check_names)]
			]);
			$db_script_names = zbx_toHash($db_script_names, 'name');

			foreach ($scripts as $script) {
				if (array_key_exists('name', $script)
						&& array_key_exists($script['name'], $db_script_names)
						&& !idcmp($db_script_names[$script['name']]['scriptid'], $script['scriptid'])) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Script "%1$s" already exists.', $script['name'])
					);
				}
			}
		}

		// Populate common and mandatory fields.
		$scripts = zbx_toHash($scripts, 'scriptid');
		$scripts = $this->extendFromObjects($scripts, $db_scripts, ['name', 'type', 'command', 'scope']);

		$i = 0;
		foreach ($scripts as $num => &$script) {
			$path = '/'.++$i;
			$db_script = $db_scripts[$script['scriptid']];
			$method = 'update';

			if (array_key_exists('type', $script) && $script['type'] != $db_script['type']) {
				// This means that all other fields are now required just like create method.
				$method = 'create';

				// Populate username field, if no new name is given and types are similar to previous.
				if (!array_key_exists('username', $script)
						&& (($db_script['type'] == ZBX_SCRIPT_TYPE_TELNET && $script['type'] == ZBX_SCRIPT_TYPE_SSH)
							|| ($db_script['type'] == ZBX_SCRIPT_TYPE_SSH
									&& $script['type'] == ZBX_SCRIPT_TYPE_TELNET))) {
					$script['username'] = $db_script['username'];
				}
			}

			$type_rules = $this->getTypeValidationRules($script['type'], $method, $type_fields);
			$scope_rules = $this->getScopeValidationRules($script['scope'], $scope_fields);

			$type_rules['fields'] += $common_fields + $scope_fields;

			if (!CApiInputValidator::validate($type_rules, $script, $path, $error)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, $error);
			}

			if ($script['type'] == ZBX_SCRIPT_TYPE_SSH) {
				$method = 'update';

				if (array_key_exists('authtype', $script) && $script['authtype'] != $db_script['authtype']) {
					$method = 'create';
				}

				$script = $this->extendFromObjects([$script], [$db_script], ['authtype'])[0];

				$ssh_rules = $this->getAuthTypeValidationRules($script['authtype'], $method);
				$ssh_rules['fields'] += $common_fields + $type_fields + $scope_fields;

				if (!CApiInputValidator::validate($ssh_rules, $script, $path, $error)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, $error);
				}
			}
		}
		unset($script);

		$this->checkUserGroups($scripts);
		$this->checkHostGroups($scripts);
	}

	/**
	 * Get general validation rules.
	 *
	 * @param string $method [IN]          API method "create" or "update".
	 * @param array  $common_fields [OUT]  Returns common fields for all script types.
	 *
	 * @return array
	 */
	protected function getValidationRules(string $method, &$common_fields = []): array {
		$api_input_rules = ['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY | API_NORMALIZE, 'fields' => []];

		$common_fields = [
			'name' =>			['type' => API_SCRIPT_NAME, 'length' => DB::getFieldLength('scripts', 'name')],
			'type' =>			['type' => API_INT32, 'in' => implode(',', [ZBX_SCRIPT_TYPE_CUSTOM_SCRIPT, ZBX_SCRIPT_TYPE_IPMI, ZBX_SCRIPT_TYPE_SSH, ZBX_SCRIPT_TYPE_TELNET, ZBX_SCRIPT_TYPE_WEBHOOK])],
			'scope' =>			['type' => API_INT32, 'in' => implode(',', [ZBX_SCRIPT_SCOPE_ACTION, ZBX_SCRIPT_SCOPE_HOST, ZBX_SCRIPT_SCOPE_EVENT])],
			'command' =>		['type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('scripts', 'command')],
			'groupid' =>		['type' => API_ID],
			'description' =>	['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('scripts', 'description')]
		];

		if ($method === 'create') {
			$common_fields['scope']['default'] = ZBX_SCRIPT_SCOPE_ACTION;
			$api_input_rules['uniq'] = [['name']];
			$common_fields['name']['flags'] = API_REQUIRED;
			$common_fields['type']['flags'] = API_REQUIRED;
			$common_fields['command']['flags'] |= API_REQUIRED;
		}
		else {
			$api_input_rules['uniq'] =  [['scriptid'], ['name']];
			$common_fields += ['scriptid' => ['type' => API_ID, 'flags' => API_REQUIRED]];
		}

		/*
		 * Merge together optional fields that depend on script type. Some of these fields are not required for some
		 * script types. Set only type for now. Unique parameter names, lengths and other flags are set later.
		 */
		$api_input_rules['fields'] += $common_fields + [
			'execute_on' =>		['type' => API_INT32],
			'menu_path' =>		['type' => API_STRING_UTF8],
			'usrgrpid' =>		['type' => API_ID],
			'host_access' =>	['type' => API_INT32],
			'confirmation' =>	['type' => API_STRING_UTF8],
			'port' =>			['type' => API_PORT, 'flags' => API_ALLOW_USER_MACRO],
			'authtype' =>		['type' => API_INT32],
			'username' =>		['type' => API_STRING_UTF8],
			'publickey' =>		['type' => API_STRING_UTF8],
			'privatekey' =>		['type' => API_STRING_UTF8],
			'password' =>		['type' => API_STRING_UTF8],
			'timeout' =>		['type' => API_TIME_UNIT],
			'parameters' =>			['type' => API_OBJECTS, 'fields' => [
				'name' =>				['type' => API_STRING_UTF8],
				'value' =>				['type' => API_STRING_UTF8]
			]]
		];

		return $api_input_rules;
	}

	/**
	 * Get validation rules for script scope.
	 *
	 * @param int    $scope  [IN]          Script scope.
	 * @param array  $common_fields [OUT]  Returns common fields for specific script scope.
	 *
	 * @return array
	 */
	protected function getScopeValidationRules(int $scope, &$common_fields = []): array {
		$api_input_rules = ['type' => API_OBJECT, 'fields' => []];
		$common_fields = [];

		if ($scope == ZBX_SCRIPT_SCOPE_HOST || $scope == ZBX_SCRIPT_SCOPE_EVENT) {
			$common_fields = [
				'menu_path' =>		['type' => API_SCRIPT_MENU_PATH, 'length' => DB::getFieldLength('scripts', 'menu_path')],
				'usrgrpid' =>		['type' => API_ID],
				'host_access' =>	['type' => API_INT32, 'in' => implode(',', [PERM_READ, PERM_READ_WRITE])],
				'confirmation' =>	['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('scripts', 'confirmation')]
			];

			$api_input_rules['fields'] += $common_fields;
		}

		return $api_input_rules;
	}

	/**
	 * Get validation rules for each script type.
	 *
	 * @param int    $type   [IN]          Script type.
	 * @param string $method [IN]          API method "create" or "update".
	 * @param array  $common_fields [OUT]  Returns common fields for specific script type.
	 *
	 * @return array
	 */
	protected function getTypeValidationRules(int $type, string $method, &$common_fields = []): array {
		$api_input_rules = ['type' => API_OBJECT, 'fields' => []];
		$common_fields = [];

		switch ($type) {
			case ZBX_SCRIPT_TYPE_CUSTOM_SCRIPT:
				$api_input_rules['fields'] += [
					'execute_on' =>		['type' => API_INT32, 'in' => implode(',', [ZBX_SCRIPT_EXECUTE_ON_AGENT, ZBX_SCRIPT_EXECUTE_ON_SERVER, ZBX_SCRIPT_EXECUTE_ON_PROXY])]
				];
				break;

			case ZBX_SCRIPT_TYPE_SSH:
				$common_fields = [
					'port' =>			['type' => API_PORT, 'flags' => API_ALLOW_USER_MACRO],
					'authtype' =>		['type' => API_INT32, 'in' => implode(',', [ITEM_AUTHTYPE_PASSWORD, ITEM_AUTHTYPE_PUBLICKEY])],
					'username' =>		['type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('scripts', 'username')],
					'password' =>		['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('scripts', 'password')]
				];

				if ($method === 'create') {
					$common_fields['username']['flags'] |= API_REQUIRED;
				}

				$api_input_rules['fields'] += $common_fields + [
					'publickey' =>		['type' => API_STRING_UTF8],
					'privatekey' =>		['type' => API_STRING_UTF8]
				];
				break;

			case ZBX_SCRIPT_TYPE_TELNET:
				$api_input_rules['fields'] += [
					'port' =>			['type' => API_PORT, 'flags' => API_ALLOW_USER_MACRO],
					'username' =>		['type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('scripts', 'username')],
					'password' =>		['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('scripts', 'password')]
				];

				if ($method === 'create') {
					$api_input_rules['fields']['username']['flags'] |= API_REQUIRED;
				}
				break;

			case ZBX_SCRIPT_TYPE_WEBHOOK:
				$api_input_rules['fields'] += [
					'timeout' =>		['type' => API_TIME_UNIT, 'in' => '1:'.SEC_PER_MIN],
					'parameters' =>			['type' => API_OBJECTS, 'uniq' => [['name']], 'fields' => [
						'name' =>				['type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('script_param', 'name')],
						'value' =>				['type' => API_STRING_UTF8, 'flags' => API_REQUIRED, 'length' => DB::getFieldLength('script_param', 'value')]
					]]
				];
				break;
		}

		return $api_input_rules;
	}

	/**
	 * Get validation rules for each script authtype.
	 *
	 * @param int    $authtype  Script authtype.
	 * @param string $method    API method "create" or "update".
	 *
	 * @return array
	 */
	protected function getAuthTypeValidationRules(int $authtype, string $method): array {
		$api_input_rules = ['type' => API_OBJECT, 'fields' => []];

		if ($authtype == ITEM_AUTHTYPE_PUBLICKEY) {
			$api_input_rules['fields'] += [
				'publickey' =>		['type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('scripts', 'publickey')],
				'privatekey' =>		['type' => API_STRING_UTF8,'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('scripts', 'privatekey')]
			];

			if ($method === 'create') {
				$api_input_rules['fields']['publickey']['flags'] |= API_REQUIRED;
				$api_input_rules['fields']['privatekey']['flags'] |= API_REQUIRED;
			}
		}

		return $api_input_rules;
	}

	/**
	 * Check for valid user groups.
	 *
	 * @param array $scripts
	 * @param array $scripts[]['usrgrpid']  (optional)
	 *
	 * @throws APIException  if user group is not exists.
	 */
	private function checkUserGroups(array $scripts) {
		$usrgrpids = [];

		foreach ($scripts as $script) {
			if (array_key_exists('usrgrpid', $script) && $script['usrgrpid'] != 0) {
				$usrgrpids[$script['usrgrpid']] = true;
			}
		}

		if (!$usrgrpids) {
			return;
		}

		$usrgrpids = array_keys($usrgrpids);

		$db_usrgrps = DB::select('usrgrp', [
			'output' => [],
			'usrgrpids' => $usrgrpids,
			'preservekeys' => true
		]);

		foreach ($usrgrpids as $usrgrpid) {
			if (!array_key_exists($usrgrpid, $db_usrgrps)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('User group with ID "%1$s" is not available.', $usrgrpid));
			}
		}
	}

	/**
	 * Check for valid host groups.
	 *
	 * @param array $scripts
	 * @param array $scripts[]['groupid']  (optional)
	 *
	 * @throws APIException  if host group is not exists.
	 */
	private function checkHostGroups(array $scripts) {
		$groupids = [];

		foreach ($scripts as $script) {
			if (array_key_exists('groupid', $script) && $script['groupid'] != 0) {
				$groupids[$script['groupid']] = true;
			}
		}

		if (!$groupids) {
			return;
		}

		$groupids = array_keys($groupids);

		$db_groups = DB::select('hstgrp', [
			'output' => [],
			'groupids' => $groupids,
			'preservekeys' => true
		]);

		foreach ($groupids as $groupid) {
			if (!array_key_exists($groupid, $db_groups)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Host group with ID "%1$s" is not available.', $groupid));
			}
		}
	}

	/**
	 * @param array $scriptids
	 *
	 * @return array
	 */
	public function delete(array $scriptids) {
		$this->validateDelete($scriptids, $db_scripts);

		DB::delete('scripts', ['scriptid' => $scriptids]);

		$this->addAuditBulk(AUDIT_ACTION_DELETE, AUDIT_RESOURCE_SCRIPT, $db_scripts);

		return ['scriptids' => $scriptids];
	}

	/**
	 * @param array $scriptids
	 * @param array $db_scripts
	 *
	 * @throws APIException if the input is invalid
	 */
	protected function validateDelete(array &$scriptids, array &$db_scripts = null) {
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('You do not have permission to perform this operation.'));
		}

		$api_input_rules = ['type' => API_IDS, 'flags' => API_NOT_EMPTY, 'uniq' => true];
		if (!CApiInputValidator::validate($api_input_rules, $scriptids, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$db_scripts = DB::select('scripts', [
			'output' => ['scriptid', 'name'],
			'scriptids' => $scriptids,
			'preservekeys' => true
		]);

		foreach ($scriptids as $scriptid) {
			if (!array_key_exists($scriptid, $db_scripts)) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}
		}

		// Check if deleted scripts used in actions.
		$db_actions = DBselect(
			'SELECT a.name,oc.scriptid'.
			' FROM opcommand oc,operations o,actions a'.
			' WHERE oc.operationid=o.operationid'.
				' AND o.actionid=a.actionid'.
				' AND '.dbConditionInt('oc.scriptid', $scriptids),
			1
		);

		if ($db_action = DBfetch($db_actions)) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Cannot delete scripts. Script "%1$s" is used in action operation "%2$s".',
					$db_scripts[$db_action['scriptid']]['name'], $db_action['name']
				)
			);
		}
	}

	/**
	 * @param array $data
	 *
	 * @return array
	 */
	public function execute(array $data) {
		global $ZBX_SERVER, $ZBX_SERVER_PORT;

		$api_input_rules = ['type' => API_OBJECT, 'fields' => [
			'scriptid' =>	['type' => API_ID, 'flags' => API_REQUIRED],
			'hostid' =>		['type' => API_ID],
			'eventid' =>	['type' => API_ID],
		]];
		if (!CApiInputValidator::validate($api_input_rules, $data, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		if (!array_key_exists('hostid', $data) && !array_key_exists('eventid', $data)) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Invalid parameter "%1$s": %2$s.', '/', _s('the parameter "%1$s" is missing', 'eventid'))
			);
		}

		if (array_key_exists('hostid', $data) && array_key_exists('eventid', $data)) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Invalid parameter "%1$s": %2$s.', '/', _s('unexpected parameter "%1$s"', 'eventid'))
			);
		}

		if (array_key_exists('eventid', $data)) {
			$db_events = API::Event()->get([
				'output' => [],
				'selectHosts' => ['hostid'],
				'eventids' => $data['eventid']
			]);
			if (!$db_events) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}

			$hostids = array_column($db_events[0]['hosts'], 'hostid');
			$is_event = true;
		}
		else {
			$hostids = $data['hostid'];
			$is_event = false;

			$db_hosts = API::Host()->get([
				'output' => [],
				'hostids' => $hostids
			]);
			if (!$db_hosts) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}
		}

		$db_scripts = $this->get([
			'output' => [],
			'hostids' => $hostids,
			'scriptids' => $data['scriptid']
		]);
		if (!$db_scripts) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}

		// execute script
		$zabbix_server = new CZabbixServer($ZBX_SERVER, $ZBX_SERVER_PORT,
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::CONNECT_TIMEOUT)),
			timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::SCRIPT_TIMEOUT)), ZBX_SOCKET_BYTES_LIMIT
		);
		$result = $zabbix_server->executeScript($data['scriptid'], self::$userData['sessionid'],
			$is_event ? null : $data['hostid'],
			$is_event ? $data['eventid'] : null
		);

		if ($result !== false) {
			// return the result in a backwards-compatible format
			return [
				'response' => 'success',
				'value' => $result,
				'debug' => $zabbix_server->getDebug()
			];
		}
		else {
			self::exception(ZBX_API_ERROR_INTERNAL, $zabbix_server->getError());
		}
	}

	/**
	 * Returns all the scripts that are available on each given host.
	 *
	 * @param $hostids
	 *
	 * @return array
	 */
	public function getScriptsByHosts($hostids) {
		zbx_value2array($hostids);

		$scripts_by_host = [];

		if (!$hostids) {
			return $scripts_by_host;
		}

		foreach ($hostids as $hostid) {
			$scripts_by_host[$hostid] = [];
		}

		$scripts = $this->get([
			'output' => API_OUTPUT_EXTEND,
			'hostids' => $hostids,
			'sortfield' => 'name',
			'preservekeys' => true
		]);

		$scripts = $this->addRelatedGroupsAndHosts([
			'selectGroups' => null,
			'selectHosts' => ['hostid']
		], $scripts, $hostids);

		if ($scripts) {
			// resolve macros
			$macros_data = [];
			foreach ($scripts as $scriptid => $script) {
				if (!empty($script['confirmation'])) {
					foreach ($script['hosts'] as $host) {
						if (isset($scripts_by_host[$host['hostid']])) {
							$macros_data[$host['hostid']][$scriptid] = $script['confirmation'];
						}
					}
				}
			}
			if ($macros_data) {
				$macros_data = CMacrosResolverHelper::resolve([
					'config' => 'scriptConfirmation',
					'data' => $macros_data
				]);
			}

			foreach ($scripts as $scriptid => $script) {
				$hosts = $script['hosts'];
				unset($script['hosts']);
				// set script to host
				foreach ($hosts as $host) {
					$hostid = $host['hostid'];

					if (isset($scripts_by_host[$hostid])) {
						$size = count($scripts_by_host[$hostid]);
						$scripts_by_host[$hostid][$size] = $script;

						// set confirmation text with resolved macros
						if (isset($macros_data[$hostid][$scriptid]) && $script['confirmation']) {
							$scripts_by_host[$hostid][$size]['confirmation'] = $macros_data[$hostid][$scriptid];
						}
					}
				}
			}
		}

		return $scripts_by_host;
	}

	protected function applyQueryOutputOptions($tableName, $tableAlias, array $options, array $sqlParts) {
		$sqlParts = parent::applyQueryOutputOptions($tableName, $tableAlias, $options, $sqlParts);

		if ($options['selectGroups'] !== null || $options['selectHosts'] !== null) {
			$sqlParts = $this->addQuerySelect($this->fieldId('groupid'), $sqlParts);
			$sqlParts = $this->addQuerySelect($this->fieldId('host_access'), $sqlParts);
		}

		return $sqlParts;
	}

	/**
	 * Applies relational subselect onto already fetched result.
	 *
	 * @param  array $options
	 * @param  array $result
	 *
	 * @return array $result
	 */
	protected function addRelatedObjects(array $options, array $result) {
		$result = parent::addRelatedObjects($options, $result);

		if ($this->outputIsRequested('parameters', $options['output'])) {
			foreach ($result as $scriptid => $script) {
				$result[$scriptid]['parameters'] = [];
			}

			$parameters = DB::select('script_param', [
				'output' => ['scriptid', 'name', 'value'],
				'filter' => ['scriptid' => array_keys($result)]
			]);

			foreach ($parameters as $parameter) {
				$result[$parameter['scriptid']]['parameters'][] = [
					'name' => $parameter['name'],
					'value' => $parameter['value']
				];
			}
		}

		return $this->addRelatedGroupsAndHosts($options, $result);
	}

	/**
	 * Applies relational subselect onto already fetched result.
	 *
	 * @param  array $options
	 * @param  mixed $options['selectGroups']
	 * @param  mixed $options['selectHosts']
	 * @param  array $result
	 * @param  array $hostids                  An additional filter by hostids, which will be added to "hosts" key.
	 *
	 * @return array $result
	 */
	private function addRelatedGroupsAndHosts(array $options, array $result, array $hostids = null) {
		$is_groups_select = $options['selectGroups'] !== null && $options['selectGroups'];
		$is_hosts_select = $options['selectHosts'] !== null && $options['selectHosts'];

		if (!$is_groups_select && !$is_hosts_select) {
			return $result;
		}

		$host_groups_with_write_access = [];
		$has_write_access_level = false;

		$group_search_names = [];
		foreach ($result as $script) {
			$has_write_access_level |= ($script['host_access'] == PERM_READ_WRITE);

			// If any script belongs to all host groups.
			if ($script['groupid'] == 0) {
				$group_search_names = null;
			}

			if ($group_search_names !== null) {
				/*
				 * If scripts were requested by host or group filters, then we have already requested group names
				 * for all groups linked to scripts. And then we can request less groups by adding them as search
				 * condition in hostgroup.get. Otherwise we will need to request all groups, user has access to.
				 */
				if (array_key_exists($script['groupid'], $this->parent_host_groups)) {
					$group_search_names[] = $this->parent_host_groups[$script['groupid']]['name'];
				}
			}
		}

		$select_groups = ['name', 'groupid'];
		$select_groups = $this->outputExtend($options['selectGroups'], $select_groups);

		$host_groups = API::HostGroup()->get([
			'output' => $select_groups,
			'search' => $group_search_names ? ['name' => $group_search_names] : null,
			'searchByAny' => true,
			'startSearch' => true,
			'preservekeys' => true
		]);

		if ($has_write_access_level && $host_groups) {
			$host_groups_with_write_access = API::HostGroup()->get([
				'output' => $select_groups,
				'groupid' => array_keys($host_groups),
				'preservekeys' => true,
				'editable' => true
			]);
		}
		else {
			$host_groups_with_write_access = $host_groups;
		}

		$nested = [];
		foreach ($host_groups as $groupid => $group) {
			$name = $group['name'];

			while (($pos = strrpos($name, '/')) !== false) {
				$name = substr($name, 0, $pos);
				$nested[$name][$groupid] = true;
			}
		}

		$hstgrp_branch = [];
		foreach ($host_groups as $groupid => $group) {
			$hstgrp_branch[$groupid] = [$groupid => true];
			if (array_key_exists($group['name'], $nested)) {
				$hstgrp_branch[$groupid] += $nested[$group['name']];
			}
		}

		if ($is_hosts_select) {
			$sql = 'SELECT hostid,groupid FROM hosts_groups'.
				' WHERE '.dbConditionInt('groupid', array_keys($host_groups));
			if ($hostids !== null) {
				$sql .= ' AND '.dbConditionInt('hostid', $hostids);
			}

			$db_group_hosts = DBSelect($sql);

			$all_hostids = [];
			$group_to_hosts = [];
			while ($row = DBFetch($db_group_hosts)) {
				if (!array_key_exists($row['groupid'], $group_to_hosts)) {
					$group_to_hosts[$row['groupid']] = [];
				}

				$group_to_hosts[$row['groupid']][$row['hostid']] = true;
				$all_hostids[] = $row['hostid'];
			}

			$used_hosts = API::Host()->get([
				'output' => $options['selectHosts'],
				'hostids' => $all_hostids,
				'preservekeys' => true
			]);
		}

		$host_groups = $this->unsetExtraFields($host_groups, ['name', 'groupid'], $options['selectGroups']);
		$host_groups_with_write_access = $this->unsetExtraFields(
			$host_groups_with_write_access, ['name', 'groupid'], $options['selectGroups']
		);

		foreach ($result as &$script) {
			if ($script['groupid'] == 0) {
				$script_groups = ($script['host_access'] == PERM_READ_WRITE)
					? $host_groups_with_write_access
					: $host_groups;
			}
			else {
				$script_groups = ($script['host_access'] == PERM_READ_WRITE)
					? array_intersect_key($host_groups_with_write_access, $hstgrp_branch[$script['groupid']])
					: array_intersect_key($host_groups, $hstgrp_branch[$script['groupid']]);
			}

			if ($is_groups_select) {
				$script['groups'] = array_values($script_groups);
			}

			if ($is_hosts_select) {
				$script['hosts'] = [];
				foreach (array_keys($script_groups) as $script_groupid) {
					if (array_key_exists($script_groupid, $group_to_hosts)) {
						$script['hosts'] += array_intersect_key($used_hosts, $group_to_hosts[$script_groupid]);
					}
				}
				$script['hosts'] = array_values($script['hosts']);
			}
		}
		unset($script);

		return $result;
	}
}
