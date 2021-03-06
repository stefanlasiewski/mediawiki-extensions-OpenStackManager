<?php

/**
 * todo comment me
 *
 * @file
 * @ingroup Extensions
 */

class OpenStackNovaUser {

	var $username;
	var $userDN;
	var $userInfo;

	/**
	 * @param string $username
	 */
	function __construct( $username = '' ) {
		$this->username = $username;
		OpenStackNovaLdapConnection::connect();
		$this->fetchUserInfo();
	}

	/**
	 * @return void
	 */
	function fetchUserInfo() {
		global $wgAuth, $wgUser;

		if ( $this->username ) {
			$this->userDN = $wgAuth->getUserDN( strtolower( $this->username ) );
			$wgAuth->printDebug( "Fetching userdn using username: $this->userDN ", NONSENSITIVE );
			if ( ! $this->userDN ) {
				$this->userDN = $wgAuth->getUserDN( strtolower( $this->username ), false, "uid" );
				$wgAuth->printDebug( "Fetching userdn using shell name: $this->userDN ", NONSENSITIVE );
			}
		} else {
			$this->userDN = $wgAuth->getUserDN( strtolower( $wgUser->getName() ) );
			$this->username = $wgUser->getName();
			$wgAuth->printDebug( "Fetching userdn using wiki name: " . $wgUser->getName(), NONSENSITIVE );
		}
		$this->userInfo = $wgAuth->userInfo;
	}

	/**
	 * @return string
	 */
	function getUid() {
		return $this->userInfo[0]['uid'][0];
	}

	/**
	 * @return string
	 */
	function getUsername() {
		return $this->username;
	}

	/**
	 * @param string $project
	 * @return array
	 */
	function getCredentials( $project='' ) {
		$userNova = OpenStackNovaController::newFromUser( $this );
		$token = $userNova->getProjectToken( $project );

		return array( 'token' => $token );
	}

	/**
	 * @param User $user
	 * @return string
	 */
	static function loadToken( $user ) {
		$user_id = $user->getId();
		if ( $user_id != 0 ) {
			$dbr = wfGetDB( DB_SLAVE );
			$row = $dbr->selectRow(
				'openstack_tokens',
				array( 'token' ),
				array( 'user_id' => $user_id ),
				__METHOD__ );

			if ( $row ) {
				return $row->token;
			}
		}

		return null;
	}

	/**
	 * @param User $user
	 * @param string $token
	 * @return bool
	 */
	static function saveToken( $user, $token ) {
		$user_id = $user->getId();
		if ( $user_id != 0 ) {
			$dbw = wfGetDB( DB_MASTER );
			$oldtoken = self::loadToken( $user );
			if ( $oldtoken ) {
				return $dbw->update(
					'openstack_tokens',
					array( 'token' => $token ),
					array( 'user_id' => $user_id ),
					__METHOD__ );
			} else {
				return $dbw->insert(
					'openstack_tokens',
					array(  'token' => $token,
						'user_id' => $user_id ),
					__METHOD__ );
			}
		} else {
			return false;
		}
	}

	/**
	 * @return array
	 */
	function getKeypairs() {
		global $wgAuth;

		$this->fetchUserInfo();
		if ( isset( $this->userInfo[0]['sshpublickey'] ) ) {
			$keys = $this->userInfo[0]['sshpublickey'];
			$keypairs = array();
			array_shift( $keys );
			foreach ( $keys as $key ) {
				$hash = md5( $key );
				$keypairs[$hash] = $key;
			}
			return $keypairs;
		} else {
			$wgAuth->printDebug( "No keypairs found", NONSENSITIVE );
			return array();
		}
	}

	/**
	 * @return bool
	 */
	function exists() {
		$credentials = $this->getCredentials();
		return (bool)$credentials['token'];
	}

	/**
	 * @return array
	 */
	function getProjects() {
		global $wgAuth;
		global $wgOpenStackManagerLDAPProjectBaseDN;

		# All projects have a owner attribute, project
		# roles do not
		$projects = array();
		$filter = "(&(objectclass=groupofnames)(member=$this->userDN))";
		$result = LdapAuthenticationPlugin::ldap_list( $wgAuth->ldapconn, $wgOpenStackManagerLDAPProjectBaseDN, $filter );
		if ( $result ) {
			$entries = LdapAuthenticationPlugin::ldap_get_entries( $wgAuth->ldapconn, $result );
			if ( $entries ) {
				# First entry is always a count
				array_shift( $entries );
				foreach ( $entries as $entry ) {
					$projects[] = $entry['cn'][0];
				}
			}
		} else {
			$wgAuth->printDebug( "No result found when searching for user's projects", NONSENSITIVE );
		}
		return $projects;
	}

	/**
	 * Returns a list of roles this user is a member of. Includes
	 * all projects.
	 * @return array
	 */
	function getRoles() {
		global $wgAuth, $wgMemc;
		global $wgOpenStackManagerLDAPProjectBaseDN;

		$key = wfMemcKey( 'openstackmanager', 'roles', $this->userDN );
		$roles = $wgMemc->get( $key );
		if ( is_array( $roles ) ) {
			return $roles;
		}

		# All projects have a owner attribute, project
		# roles do not
		$roles = array();
		$filter = "(&(objectclass=organizationalrole)(roleoccupant=$this->userDN))";
		$result = LdapAuthenticationPlugin::ldap_search( $wgAuth->ldapconn, $wgOpenStackManagerLDAPProjectBaseDN, $filter );
		if ( $result ) {
			$entries = LdapAuthenticationPlugin::ldap_get_entries( $wgAuth->ldapconn, $result );
			if ( $entries ) {
				# First entry is always a count
				array_shift( $entries );
				foreach ( $entries as $entry ) {
					$roles[] = $entry['cn'][0];
				}
			}
			$roles = array_unique( $roles );
		} else {
			$wgAuth->printDebug( "No result found when searching for user's roles", NONSENSITIVE );
		}
		$key = wfMemcKey( 'openstackmanager', 'roles', $this->getUsername() );
		$wgMemc->set( $key, $roles, '3600' );
		return $roles;
	}

	/**
	 * @param  $project
	 * @return bool
	 */
	function inProject( $project ) {
		global $wgAuth;
		global $wgOpenStackManagerLDAPProjectBaseDN;
		global $wgMemc;

		$key = wfMemcKey( 'openstackmanager', "project-$project", $this->userDN );
		$cacheLength = 3600;
		$inProject = $wgMemc->get( $key );
		if ( is_int( $inProject ) ) {
			return (bool)$inProject;
		}

		$filter = "(&(cn=$project)(member=$this->userDN)(objectclass=groupofnames))";
		$result = LdapAuthenticationPlugin::ldap_search( $wgAuth->ldapconn, $wgOpenStackManagerLDAPProjectBaseDN, $filter );
		$ret = false;
		if ( $result ) {
			$entries = LdapAuthenticationPlugin::ldap_get_entries( $wgAuth->ldapconn, $result );
			if ( $entries ) {
				if ( $entries['count'] == "0" ) {
					$wgAuth->printDebug( "Couldn't find the user in project: $project", NONSENSITIVE );
				} else {
					$ret = true;
				}
			}
		}
		$wgMemc->set( $key, (int)$ret, $cacheLength );
		return $ret;
	}

	/**
	 * @param $role
	 * @param string $projectname
	 * @param bool $strict
	 * @return bool
	 */
	function inRole( $role, $projectname, $strict=false ) {
		global $wgAuth;
		global $wgMemc;

		if ( !$projectname ) {
			return false;
		}
		$key = wfMemcKey( 'openstackmanager', "projectrole-$projectname-$role", $this->userDN );
		$cacheLength = 3600;
		$inRole = $wgMemc->get( $key );
		if ( is_int( $inRole ) ) {
			return (bool)$inRole;
		}

		$ret = false;
		# Check project specific role
		$project = OpenStackNovaProject::getProjectByName( $projectname );
		if ( ! $project ) {
			$wgMemc->set( $key, 0, $cacheLength );
			return false;
		}
		$filter = "(&(cn=$role)(roleoccupant=$this->userDN))";
		$result = LdapAuthenticationPlugin::ldap_search( $wgAuth->ldapconn, $project->projectDN, $filter );
		if ( $result ) {
			$entries = LdapAuthenticationPlugin::ldap_get_entries( $wgAuth->ldapconn, $result );
			if ( $entries ) {
				if ( $entries['count'] == "0" ) {
					$wgAuth->printDebug( "Couldn't find the user in role: $role", NONSENSITIVE );
				} else {
					$ret = true;
				}
			}
		}
		$wgMemc->set( $key, (int)$ret, $cacheLength );
		return $ret;
	}

	/**
	 * @param  $key
	 * @return bool
	 */
	function importKeypair( $key ) {
		global $wgAuth;
		global $wgMemc;

		$keypairs = array();
		if ( isset( $this->userInfo[0]['sshpublickey'] ) ) {
			$keypairs = $this->userInfo[0]['sshpublickey'];
			array_shift( $keypairs );
		}
		$keypairs[] = $key;
		$values = array();
		$values['sshpublickey'] = $keypairs;
		$success = LdapAuthenticationPlugin::ldap_modify( $wgAuth->ldapconn, $this->userDN, $values );
		if ( $success ) {
			$wgAuth->printDebug( "Successfully imported the user's sshpublickey", NONSENSITIVE );
			$key = wfMemcKey( 'ldapauthentication', "userinfo", $this->userDN );
			$wgAuth->printDebug( "Deleting memcache key: $key.", NONSENSITIVE );
			$wgMemc->delete( $key );
			$this->fetchUserInfo();
			return true;
		} else {
			$wgAuth->printDebug( "Failed to import the user's sshpublickey", NONSENSITIVE );
			return false;
		}
	}

	/**
	 * @param  $key
	 * @return bool
	 */
	function deleteKeypair( $key ) {
		global $wgAuth;
		global $wgMemc;

		if ( isset( $this->userInfo[0]['sshpublickey'] ) ) {
			$keypairs = $this->userInfo[0]['sshpublickey'];
			array_shift( $keypairs );
			$index = array_search( $key, $keypairs );
			if ( $index === false ) {
				$wgAuth->printDebug( "Unable to find the sshpublickey to be deleted", NONSENSITIVE );
				return false;
			}
			unset( $keypairs[$index] );
			$values = array();
			$values['sshpublickey'] = array();
			foreach ( $keypairs as $keypair ) {
				$values['sshpublickey'][] = $keypair;
			}
			$success = LdapAuthenticationPlugin::ldap_modify( $wgAuth->ldapconn, $this->userDN, $values );
			if ( $success ) {
				$wgAuth->printDebug( "Successfully deleted the user's sshpublickey", NONSENSITIVE );
				$key = wfMemcKey( 'ldapauthentication', "userinfo", $this->userDN );
				$wgAuth->printDebug( "Deleting memcache key: $key.", NONSENSITIVE );
				$wgMemc->delete( $key );
				$this->fetchUserInfo();
				return true;
			} else {
				$wgAuth->printDebug( "Failed to delete the user's sshpublickey", NONSENSITIVE );
				return false;
			}
		} else {
			$wgAuth->printDebug( "User does not have a sshpublickey attribute", NONSENSITIVE );
			return false;
		}
	}

	/**
	 * Does not ensure uniqueness during concurrent use!
	 * Also does not work when resource limits are set for
	 * LDAP queries by the client or the server.
	 *
	 * TODO: write a better and more efficient version of this.
	 *
	 * TODO: Make use of $wgOpenStackManagerIdRanges for all cases.
	 * TODO: Make $wgOpenStackManagerIdRanges use a set of ranges.
	 *
	 * @static
	 * @param  $auth
	 * @param  $attr
	 * @return mixed|string
	 */
	static function getNextIdNumber( $auth, $attr ) {
		global $wgOpenStackManagerIdRanges;

		$highest = '';
		if ( $attr === 'gidnumber' ) {
			$filter = "(objectclass=posixgroup)";
			$base = GROUPDN;
			$highest = $wgOpenStackManagerIdRanges['service']['gid']['min'];
		} else {
			$filter = "(objectclass=posixaccount)";
			$base = USERDN;
			$highest = '500';
		}
		$basedn = $auth->getBaseDN( $base );

		$result = LdapAuthenticationPlugin::ldap_search( $auth->ldapconn, $basedn, $filter );
		if ( $result ) {
			$entries = LdapAuthenticationPlugin::ldap_get_entries( $auth->ldapconn, $result );
			if ( $entries ) {
				if ( $entries['count'] != "0" ) {
					array_shift( $entries );
					$uids = array();
					foreach ( $entries as $entry ) {
						$uids[] = $entry[$attr][0];
					}
					sort( $uids, SORT_NUMERIC );
					$highest = array_pop( $uids ) + 1;
				}
			} else {
				$auth->printDebug( "Failed to find any entries when searching for next $attr", NONSENSITIVE );
			}
		} else {
			$auth->printDebug( "Failed to get a result searching for next $attr", NONSENSITIVE );
		}

		if ( $highest > $wgOpenStackManagerIdRanges['service']['gid']['max']) {
			$auth->printDebug( "Ran out of service group gids!", NONSENSITIVE );
		}

		$auth->printDebug( "id returned: $highest", NONSENSITIVE );
		return $highest;
	}

	/**
	 * Create a shell request page for $username
	 *
	 * @static
	 * @param  $auth
	 * @param  $username
	 * @return bool
	 */
	static function RequestShellAccess( $auth, $username ) {

		$auth->printDebug( "Autogenerating shell access request for " . $username, NONSENSITIVE );

		$titletext = "Shell_Request/" . $username;
		$title = Title::newFromText( $titletext );
		$article = WikiPage::factory( $title );

		if ( $article->exists() ) {
			$auth->printDebug( "shell request for " . $username . " already exists.", NONSENSITIVE );
			return false;
		}

		$text = "{{Shell Access Request "
				. "|Justification=(auto request on account creation) "
				. "|Completed=false "
				. "|User Name=$username}}";

		$logbot = User::newFromName( 'labslogbot' );
		if ( ! $logbot ) {
			$auth->printDebug( "Failed to get 'labslogbot' user, so unable to create shell request.", NONSENSITIVE );
		} else {
			$article->doEdit( $text, 'auto request', 0, false, $logbot );
		}

		return true;
	}

	/**
	 * Hook to add objectclasses and attributes for users being created.
	 *
	 * @static
	 * @param  $auth
	 * @param  $username
	 * @param  $values
	 * @param  $writeloc
	 * @param  $userdn
	 * @param  $result
	 * @return bool
	 */
	static function LDAPSetCreationValues( $auth, $username, &$values, $writeloc, &$userdn, &$result ) {
		global $wgOpenStackManagerLDAPDefaultGid;
		global $wgOpenStackManagerLDAPDefaultShell;
		global $wgOpenStackManagerLDAPUseUidAsNamingAttribute;
		global $wgRequest;

		$values['objectclass'][] = 'person';
		$values['objectclass'][] = 'ldappublickey';
		$values['objectclass'][] = 'posixaccount';
		$values['objectclass'][] = 'shadowaccount';
		$uidnumber = OpenStackNovaUser::getNextIdNumber( $auth, 'uidnumber' );
		if ( ! $uidnumber ) {
			$result = false;
			return false;
		}
		$values['cn'] = $username;
		if ( '' !== $auth->realname ) {
			$values['displayname'] = $auth->realname;
		}
		$shellaccountname = $wgRequest->getText( 'shellaccountname' );
		if ( ! preg_match( "/^[a-z][a-z0-9\-_]*$/", $shellaccountname ) ) {
			$result = false;
			return false;
		}
		$check = ucfirst( $shellaccountname );
		if ( ! User::isCreatableName( $check ) ) {
			$auth->printDebug( "$shellaccountname is not a creatable name.", NONSENSITIVE );
			$result = false;
			return false;
		}
		$values['uid'] = $shellaccountname;
		$base = $auth->getBaseDN( USERDN );
		# Though the LDAP plugin checks to see if the user account exists,
		# it does not check to see if the uid attribute is already used.
		$result = LdapAuthenticationPlugin::ldap_search( $auth->ldapconn, $base, "(uid=$shellaccountname)" );
		if ( $result ) {
			$entries = LdapAuthenticationPlugin::ldap_get_entries( $auth->ldapconn, $result );
			if ( (int)$entries['count'] > 0 ) {
				$auth->printDebug( "User $shellaccountname already exists.", NONSENSITIVE );
				# uid attribute is already in use, fail.
				$result = false;
				return false;
			}
		}
		$values['uidnumber'] = $uidnumber;
		$values['gidnumber'] = $wgOpenStackManagerLDAPDefaultGid;
		$values['homedirectory'] = '/home/' . $shellaccountname;
		$values['loginshell'] = $wgOpenStackManagerLDAPDefaultShell;

		if ( $wgOpenStackManagerLDAPUseUidAsNamingAttribute ) {
			if ( $writeloc === '' ) {
				$auth->printDebug( "Trying to set the userdn, but write location isn't set.", NONSENSITIVE );
				return false;
			} else {
				$userdn = 'uid=' . $shellaccountname . ',' . $writeloc;
				$auth->printDebug( "Using uid as the naming attribute, dn is: $userdn", NONSENSITIVE );
			}
		}
		$auth->printDebug( "User account's objectclasses: ", NONSENSITIVE, $values['objectclass'] );
		$auth->printDebug( "User account's attributes: ", HIGHLYSENSITIVE, $values );

		OpenStackNovaUser::RequestShellAccess( $auth, $username );

		return true;
	}

	/**
	 * Hook to retry setting the creation values. Specifically, this will try to set a new
	 * uid in case there's a race condition.
	 *
	 * @static
	 * @param  $auth
	 * @param  $username
	 * @param  $values
	 * @param  $writeloc
	 * @param  $userdn
	 * @param  $result
	 * @return bool
	 */
	static function LDAPRetrySetCreationValues( $auth, $username, &$values, $writeloc, &$userdn, &$result ) {
		$uidnumber = OpenStackNovaUser::getNextIdNumber( $auth, 'uidnumber' );
		if ( ! $uidnumber ) {
			$result = false;
			return false;
		}
		$values['uidnumber'] = $uidnumber;

		$result = true;
		return true;
	}

	/**
	 * @static
	 * @param $template
	 * @return bool
	 */
	static function LDAPModifyUITemplate( &$template ) {
		$input = array( 'msg' => 'openstackmanager-shellaccountname', 'type' => 'text', 'name' => 'shellaccountname', 'value' => '', 'helptext' => 'openstackmanager-shellaccountnamehelp' );
		$template->set( 'extraInput', array( $input ) );

		return true;
	}

	/**
	 * @static
	 * @param $user
	 * @return bool
	 */
	static function LDAPUpdateUser( &$wikiUser ) {
		if ( $wikiUser->getToken( false ) && isset( $_SESSION['wsOpenStackToken'] ) ) {
			# If the user has a long-lived token, save the token,
			# so that it can be refetched.
			self::saveToken( $wikiUser, $_SESSION['wsOpenStackToken'] );
		}
		return true;
	}

	/**
	 * @param $username string
	 * @param $password string
	 * @param $result bool
	 * @return bool
	 */
	static function ChainAuth( $username, $password, &$result ) {
		global $wgOpenStackManagerLDAPUseUidAsNamingAttribute;

		$user = new OpenStackNovaUser( $username );
		$userNova = OpenStackNovaController::newFromUser( $user );
		if ( $wgOpenStackManagerLDAPUseUidAsNamingAttribute ) {
			$username = $user->getUid();
		}
		$token = $userNova->authenticate( $username, $password );
		if ( $token ) {
			$result = true;
			# Add token to session, so that it can be referenced later
			$_SESSION['wsOpenStackToken'] = $token;
		} else {
			$result = false;
		}

		return $result;
	}

	static function DynamicSidebarGetGroups( &$groups ) {
		global $wgUser, $wgMemc;
		if ( $wgUser->isLoggedIn() ) {
			$key = wfMemcKey( 'openstackmanager', 'roles', $wgUser->getName() );
			$roles = $wgMemc->get( $key );
			if ( !is_array( $roles ) ) {
				$user = new OpenStackNovaUser();
				$roles = $user->getRoles();
			}
			$groups = array_merge( $groups, $roles );
		}

		return true;
	}

	public static function manageShellAccess( $user, $addedGroups, $removedGroups ) {
		global $wgOpenStackManagerRemoveUserFromBastionProjectOnShellDisable;
		global $wgOpenStackManagerRemoveUserFromAllProjectsOnShellDisable;
		global $wgOpenStackManagerBastionProjectName;

		$username = $user->getName();

		if( $user->isAllowed( 'loginviashell' ) ) {
			// Check the user is in the bastion project
			$project = new OpenStackNovaProject( $wgOpenStackManagerBastionProjectName );
			if( !in_array( $username, $project->getMembers() ) ) {
				$project->addMember( $username );
			}
		} elseif( $wgOpenStackManagerRemoveUserFromAllProjectsOnShellDisable ) {
			// Get a users projects
			$userLDAP = new OpenStackNovaUser( $username );
			foreach( $userLDAP->getProjects() as $projectName ) {
				// Remove the user from the project
				$project = new OpenStackNovaProject( $projectName );
				$project->deleteMember( $username );
			}
		} elseif( $wgOpenStackManagerRemoveUserFromBastionProjectOnShellDisable ) {
			// Remove the user from the bastion project
			$project = new OpenStackNovaProject( $wgOpenStackManagerBastionProjectName );
			if( in_array( $username, $project->getMembers() ) ) {
				$project->deleteMember( $username );
			}
		}
		return True;
	}

	/**
	 * @param $user User
	 * @param $preferences array
	 * @return bool True
	 */
	public static function novaUserPreferences( User $user, array &$preferences ) {
		$link = Linker::link( SpecialPage::getTitleFor( 'NovaKey' ),
			wfMessage( 'novakey' )->escaped(),
			array(),
			array( 'returnto' => SpecialPage::getTitleFor( 'Preferences' )->getPrefixedText() )
		);

		$novaUser = new OpenStackNovaUser( $user->getName() );

		$preferences['shellusername'] = array(
			'type' => 'info',
			'label-message' => 'openstackmanager-shellaccountname-pref',
			'default' => $novaUser->getUid(),
			'section' => 'personal/info',
		);

		$preferences['openstack-sshkeylist'] = array(
			'type' => 'info',
			'raw' => true,
			'default' => self::getKeyList( $novaUser ),
			'label-message' => 'openstackmanager-prefs-novapublickey',
			'section' => 'openstack/openstack-keys',
		);
		return true;
	}

	/**
	 * @param $user OpenStackNovaUser
	 * @return string
	 */
	static function getKeyList( $user ) {
		global $wgOpenStackManagerNovaKeypairStorage;
		$keyInfo = array();
		if ( $wgOpenStackManagerNovaKeypairStorage === 'nova' ) {
			$projects = $user->getProjects();
			$keyInfo['keyname'] = array(
				'type' => 'text',
				'label-message' => 'openstackmanager-novakeyname',
				'default' => '',
				'name' => 'keyname',
			);
			$project_keys = array();
			foreach ( $projects as $project ) {
				$project_keys[$project] = $project;
			}
			$keyInfo['project'] = array(
				'type' => 'select',
				'options' => $project_keys,
				'label-message' => 'openstackmanager-project',
				'name' => 'project',
			);
		}
		$keyInfo['key'] = array(
			'type' => 'textarea',
			'default' => '',
			'label-message' => 'openstackmanager-novapublickey',
			'name' => 'key',
		);

		$out = '';
		if ( $wgOpenStackManagerNovaKeypairStorage === 'nova' ) {
			# TODO: add project filter
			foreach ( $projects as $project ) {
				$userCredentials = $user->getCredentials();
				$userNova = new OpenStackNovaController( $userCredentials, $project );
				$keypairs = $userNova->getKeypairs();
				if ( !$keypairs ) {
					continue;
				}
				$out .= Html::element( 'h2', array(), $project );
				$headers = array( 'openstackmanager-name', 'openstackmanager-fingerprint' );
				$keyRows = array();
				foreach ( $keypairs as $keypair ) {
					$keyRow = array();
					SpecialNova::pushResourceColumn( $keyRow, $keypair->getKeyName() );
					SpecialNova::pushResourceColumn( $keyRow, $keypair->getKeyFingerprint() );
					$keyRows[] = $kewRow;
				}
				$out .= SpecialNova::createResourceTable( $headers, $keyRows );
			}
		} elseif ( $wgOpenStackManagerNovaKeypairStorage === 'ldap' ) {
			$headers = array( 'openstackmanager-keys', 'openstackmanager-actions' );
			$keypairs = $user->getKeypairs();
			$keyRows = array();
			foreach ( $keypairs as $hash => $key ) {
				$keyRow = array();
				SpecialNova::pushResourceColumn( $keyRow, $key, array( 'class' => 'Nova_col' ) );
				$actions = array();
				$actions[] = SpecialNova::createNovaKeyActionLink(
					'openstackmanager-delete',
					array(
						'action' => 'delete',
						'hash' => $hash,
						'returnto' => SpecialPage::getTitleFor( 'Preferences', false, 'mw-prefsection-openstack' )->getFullText()
					)
				);
				SpecialNova::pushRawResourceColumn( $keyRow, SpecialNova::createResourceList( $actions ) );
				$keyRows[] = $keyRow;
			}
			$out .= SpecialNova::createResourceTable( $headers, $keyRows );
		}
		$out .= Linker::link(
			SpecialPage::getTitleFor( 'NovaKey' ),
			wfMessage( 'openstackmanager-addkey' )->escaped(),
			array(),
			array( 'returnto' => SpecialPage::getTitleFor( 'Preferences', false, 'mw-prefsection-openstack' )->getFullText() )
		);
		return $out;
	}
}
