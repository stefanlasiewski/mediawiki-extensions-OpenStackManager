<?
class ApiNovaProjects extends ApiBase {
	var $userLDAP;
	var $userNova;
	var $params;

	public function canExecute( $rights=array() ) {
		if ( ! $this->userLDAP->exists() ) {
			$this->dieUsage( wfMessage( 'openstackmanager-nonovacred' )->escaped() );
		}
		if ( in_array( 'inproject', $rights ) || in_array( 'isprojectadmin', $rights ) ) {
			if ( ! $this->userLDAP->inProject( $this->params['project'] ) ) {
				$this->dieUsage( wfMessage( 'openstackmanager-noaccount', $this->params['project'] )->escaped() );
			}
		}
		if ( in_array( 'isprojectadmin', $rights ) ) {
			if ( ! $this->userLDAP->inRole( 'projectadmin', $this->params['project'] ) ) {
				$this->dieUsage( wfMessage( 'openstackmanager-needrole', 'projectadmin', $this->params['project'] )->escaped() );
			}
		}
	}

	function execute() {
		$this->params = $this->extractRequestParams();
		$this->userLDAP = new OpenStackNovaUser();

		switch( $this->params['subaction'] ) {
		case 'getall':
			$projects = OpenStackNovaProject::getAllProjects();
			foreach ( $projects as $project ) {
				$projectNames[] = $project->getProjectName();
			}
			$this->getResult()->setIndexedTagName( $projectNames, 'project' );
			$this->getResult()->addValue( null, $this->getModuleName(), $projectNames );
			break;
		case 'getuser':
			$this->canExecute();
			$projectNames = $this->userLDAP->getProjects();
			$this->getResult()->setIndexedTagName( $projectNames, 'project' );
			$this->getResult()->addValue( null, $this->getModuleName(), $projectNames );
			break;
		case 'limits':
			$this->canExecute( array( 'isprojectadmin' ) );
			$this->userNova = OpenStackNovaController::newFromUser( $this->userLDAP );
			$this->userNova->setProject( $this->params['project'] );
			if ( isset( $this->params['region'] ) ) {
				$regions = array( $this->params['region'] );
			} else {
				$regions = $this->userNova->getRegions( 'compute' );
			}
			$limitsOut = array();
			foreach ( $regions as $region ) {
				$this->userNova->setRegion( $region );
				$limits = $this->userNova->getLimits();
				$limitsRegion = array();
				$limitsRegion["maxTotalRAMSize"] = $limits->getRamAvailable();
				$limitsRegion["totalRAMUsed"] = $limits->getRamUsed();
				$limitsRegion["maxTotalFloatingIps"] = $limits->getFloatingIpsAvailable();
				$limitsRegion["totalFloatingIpsUsed"] = $limits->getFloatingIpsUsed();
				$limitsRegion["maxTotalCores"] = $limits->getCoresAvailable();
				$limitsRegion["totalCoresUsed"] = $limits->getCoresUsed();
				$limitsRegion["maxTotalInstances"] = $limits->getInstancesAvailable();
				$limitsRegion["totalInstancesUsed"] = $limits->getInstancesUsed();
				$limitsRegion["maxSecurityGroups"] = $limits->getSecurityGroupsAvailable();
				$limitsRegion["totalSecurityGroupsUsed"] = $limits->getSecurityGroupsUsed();
				$limitsOut[$region] = array( 'absolute' => $limitsRegion );
			}
			$this->getResult()->addValue( null, $this->getModuleName(), array( 'regions' => $limitsOut ) );
		}

	}

	public function getPossibleErrors() {
		return array(
			array( 'openstackmanager-noaccount' ),
			array( 'openstackmanager-needrole' )
		);
	}

	// Face parameter.
	public function getAllowedParams() {
		return array(
			'subaction' => array (
				ApiBase::PARAM_TYPE => array(
					'getall',
					'getuser',
					'limits',
				),
				ApiBase::PARAM_REQUIRED => true
			),
			'project' => array (
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			),
			'region' => array (
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			),
		);
	}
 
	public function getParamDescription() {
		return array_merge( parent::getParamDescription(), array(
			'subaction' => 'The subaction to perform.',
			'project' => 'The project to perform the subaction upon',
			'region' => 'The region to perform the subaction upon',
		) );
	}

	public function getDescription() {
		return 'Gets information on projects.';
	}

	public function getExamples() {
		return array(
			'api.php?action=novaproject&subaction=getall'
			=> 'Get all projects',
			'api.php?action=novaproject&subaction=getuser'
			=> 'Get all projects for the logged-in user',
			'api.php?action=novaproject&subaction=limits&project=testing'
			=> 'Get limits for all regions for the testing project',
			'api.php?action=novaproject&subaction=limits&project=testing&region=A'
			=> 'Get limits for region A for the testing project',
		);
	}

	public function mustBePosted() {
		return false;
	}

}
