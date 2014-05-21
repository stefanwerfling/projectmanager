<?php

class projectmanager_gantt extends projectmanager_elements_ui {

	public $public_functions = array(
		'chart'	=> true,
		'ajax_gantt_project' => true,
		'ajax_update' => true
	);
	public function __construct() {
		parent::__construct();
	}

	public function chart($data = array()) {

		// Find out which project we're working with
		if (isset($_REQUEST['pm_id']))
		{
			$pm_id = $_REQUEST['pm_id'];
			$GLOBALS['egw']->session->appsession('pm_id','projectmanager',$pm_id);
		}
		else if ($_GET['pm_id'])
		{
			// AJAX requests have pm_id only in GET, not REQUEST
			$pm_id = $_GET['pm_id'];
		}
		else if ($data['project_tree'])
		{
			$pm_id = array();
			$data['project_tree'] = is_array($data['project_tree']) ? $data['project_tree'] : explode(',',$data['project_tree']);
			foreach($data['project_tree'] as $project)
			{
				list(,$pm_id[]) = explode('::',$project,2);
			}
		}
		else
		{
			$pm_id = $GLOBALS['egw']->session->appsession('pm_id','projectmanager');
		}
		if(!$pm_id)
		{
			egw::redirect_link('/index.php', array(
				'menuaction' => 'projectmanager.projectmanager_ui.index',
				'msg'        => lang('You need to select a project first'),
			));
		}
		$pm_id = is_array($pm_id) ? $pm_id : explode(',',$pm_id);
		$this->pm_id = $pm_id[0];

		// Deal with incoming
		if($data['gantt']['action'])
		{
			$result = $this->action($data['gantt']['action'], $data['gantt']['selected'], $msg, $add_existing);
			if($msg)
			{
				egw_framework::message($msg, $result ? 'success' : 'error');
			}
		}
		if ($data['sync_all'])
		{
			$this->project = new projectmanager_bo($pm_id);
			if($this->project->check_acl(EGW_ACL_ADD))
			{
				$data['msg'] = lang('%1 element(s) updated',$this->sync_all());
			}
			unset($data['sync_all']);
		}

		if($data)
		{
			// Save settings implicitly as user preference
			$GLOBALS['egw']->preferences->add('projectmanager','gantt_planned_times',$data['planned_times']);
			$GLOBALS['egw']->preferences->add('projectmanager','gantt_constraints',$data['constraints']);
			// save prefs, but do NOT invalid the cache (unnecessary)
			$GLOBALS['egw']->preferences->save_repository(false,'user',false);

			// Save filters in session per project
			$result = egw_cache::setSession('projectmanager', 'gantt_'.$pm_id[0], $data['gantt']);
		}
		else
		{
			$data = array(
				'planned_times' => $GLOBALS['egw_info']['user']['preferences']['projectmanager']['gantt_planned_times'],
				'constraints' => $GLOBALS['egw_info']['user']['preferences']['projectmanager']['gantt_constraints'],
				'gantt' => (array)egw_cache::getSession('projectmanager', 'gantt_'.$pm_id[0])
			);
		}
		egw_framework::includeCSS('projectmanager','gantt');
		$GLOBALS['egw_info']['flags']['app_header'] = '';
		
		// Yes, we want the link registry
		$GLOBALS['egw_info']['flags']['js_link_registry'] = true;

		// Default to project elements, and their children - others will be done via ajax
		if(!array_key_exists('depth',$data)) $data['depth'] = 2;


		$data['gantt'] = $data['gantt'] + array('data' => array(), 'links' => array());
		$data['project_tree'] = array();
		foreach($pm_id as $id)
		{
			$this->add_project($data['gantt'], $id, $data);
			$data['project_tree'][] = 'projectmanager::'.$id;
		}

		$sel_options = array(
			'filter' => array(
				''        => lang('All'),
				'not'     => lang('Not started (0%)'),
				'ongoing' => lang('Ongoing (0 < % < 100)'),
				'done'    => lang('Done (100%)'),
			),
		);
		$template = new etemplate_new();
		$template->read('projectmanager.gantt');
		
		$sel_options['project_tree'] = projectmanager_ui::ajax_tree(0, true);
		$template->setElementAttribute('project_tree','actions', projectmanager_ui::project_tree_actions());
		$template->setElementAttribute('gantt','actions', $this->get_gantt_actions());

		$template->exec('projectmanager.projectmanager_gantt.chart', $data, $sel_options, $readonlys);
	}

	/**
	 * Get (context menu) actions for the gantt chart
	 */
	protected function get_gantt_actions()
	{
		$actions = $this->get_actions();
		$actions['open']['onExecute'] = 'javaScript:app.projectmanager.gantt_open_action';
		$actions['edit']['onExecute'] = 'javaScript:app.projectmanager.gantt_edit_element';

		// Cat IDs don't get a prefix, nm does something extra to them
		$add_id = function(&$action) use (&$add_id)
		{
			$children = $action['children'];
			$action['children'] = array();
			foreach($children as $id => $sub)
			{
				if($sub['id'] == $id) continue;
				$sub['id'] = 'cat_'.$id;
				$action['children'][] = $sub;
				if($sub['children'])
				{
					$add_id($sub);
				}
			}
		};
		$add_id($actions['cat']);



		// Don't do add existing, documents or timesheet,
		// they're not implemented / tested
		unset($actions['add_existing']);
		unset($actions['timesheet']);
		unset($actions['documents']);

		return $actions;
	}

	public function ajax_gantt_project($project_id, $params)
	{
		if(!is_array($project_id)) {
			$project_id = explode(',',$project_id);
		}
		$data = array('data' => array(), 'links' => array());
		$params['level'] = 1;
		if(!$params['depth']) $params['depth'] = 2;

		// Parse times
		if($params['start']['str']) {
			$time = egw_time::createFromFormat(
				egw_time::$user_dateformat,
				$params['start']['str']
			);
			$params['start'] = $time->format('U');
		} else {
			$params['start'] = null;
		}
		if($params['end']['str']) {
			$time = egw_time::createFromFormat(
				egw_time::$user_dateformat,
				$params['end']['str']
			);
			$params['end'] = $time->format('U');
		} else {
			$params['end'] = null;
		}

		foreach($project_id as $pm_id) {
			$projects[] = $this->add_project($data, $pm_id, $params);
		}
		$response = egw_json_response::get();
		$response->data($data);
	}

	// Get the data into required format
	protected function add_project(&$data = array(), $pm_id, $params) {
		if ($pm_id != $this->project->data['pm_id'])
		{
			if (!$this->project->read($pm_id) || !$this->project->check_acl(EGW_ACL_READ))
			{
				return;
			}
		}
		$project = $this->project->data + array(
			'id'	=>	'projectmanager::'.$this->project->data['pm_id'],
			'text'	=>	egw_link::title('projectmanager', $this->project->data['pm_id']),
			'edit'	=>	$this->project->check_acl(EGW_ACL_EDIT),
			'start_date'	=>	egw_time::to($params['planned_times'] ? $this->project->data['pm_planned_start'] : $this->project->data['pm_real_start'],egw_time::DATABASE),
			'open'	=>	$params['level'] < $params['depth'],
			'progress' => ((int)substr($this->project->data['pm_completion'],0,-1))/100
		);
		// Set field for filter to filter on
		$project['filter'] = $project['pm_completion'] > 0 ? ($pe['pm_completion'] != 100 ? 'ongoing' : 'done') : 'not';

		if($params['planned_times'] ? $this->project->data['pm_planned_end'] : $this->project->data['pm_real_end'])
		{
			// Make sure we don't kill the gantt chart with too large a time span - limit to 10 years
			$start = $params['planned_times'] ? $this->project->data['pm_planned_start'] : $this->project->data['pm_real_start'];
			$end = min($params['planned_times'] ? $this->project->data['pm_planned_end'] : $this->project->data['pm_real_end'],
				strtotime('+10 years',$start)
			);
			// Avoid a 0 length project, that causes display and control problems
			// Add 1 day - 1 second to go from 0:00 to 23:59
			if($end == $start) strtotime('+1 day', $end)-1;
			$project['end_date'] = egw_time::to($end,egw_time::DATABASE);
		}
		else
		{
			$project['duration'] = $params['planned_times'] ? $this->project->data['pm_planned_time'] : 1;
		}

		// Not sure how it happens, but it causes problems
		if($project['start'] && $project['start'] < 10) $project['start'] = 0;

		if(is_array($project['pm_members'])) {
			foreach($project['pm_members'] as $uid => &$member_data) {
				$member_data['name'] = common::grab_owner_name($member_data['member_uid']);
			}
		}
		$data['data'][] = $project;

		// Milestones are tasks too
		$milestones = $this->milestones->search(array('pm_id' => $pm_id),'ms_id,ms_date,ms_title');
		foreach((array)$milestones as $milestone)
		{
			$data['data'][] = array(
				'id'	=>	'milestone:'.$milestone['ms_id'],
				'pm_id' => $pm_id,
				'ms_id' => $milestone['ms_id'],
				'text'	=>	$milestone['ms_title'],
				'parent' => 'projectmanager::'.$pm_id,
				'edit'	=>	$this->project->check_acl(EGW_ACL_EDIT),
				'start_date'	=>	egw_time::to($milestone['ms_date'],egw_time::DATABASE),
				'type' => 'milestone'
			);
		}

		if($params['depth'])
		{
			$project['elements'] = $this->add_elements($data, $pm_id, $params, $params['level'] ? $params['level'] : 1);
			$data['data'] = array_merge($data['data'], $project['elements']);
		}

		return $project;
	}

	protected function add_elements(&$data, $pm_id, $params, $level = 1) {
		$elements = array();

		if($level > $params['depth']) return $elements;

		// defining start- and end-times depending on $params['planned_times'] and the availible data
		foreach(array('start','end') as $var)
		{
			if ($params['planned_times'])
			{
				$$var = "CASE WHEN pe_planned_$var IS NULL THEN pe_real_$var ELSE pe_planned_$var END";
			}
			else
			{
				$$var = "CASE WHEN pe_real_$var IS NULL THEN pe_planned_$var ELSE pe_real_$var END";
			}
		}
		$filter = array(
			'pm_id'	=> $pm_id,
			"pe_status != 'ignore'",
			'cumulate' => true,
		);
		$extra_cols = array(
			$start.' AS pe_start',
			$end.' AS pe_end',
		);
		if($params['end'])
		{
			$filter[] = $start.' <= ' . (int)$params['end'];
		}
		if($params['start'])
		{
			$filter[] = $end.' >= ' . (int)$params['start'];
		}
		switch ($params['filter'])
		{
			case 'not':
				$filter['pe_completion'] = 0;
				break;
			case 'ongoing':
				$filter[] = 'pe_completion!=100';
				break;
			case 'done':
				$filter['pe_completion'] = 100;
				break;
		}
		if ($params['pe_resources'])
		{
			$filter['pe_resources'] = $params['pe_resources'];
		}
		if ($params['cat_id'])
		{
			$filter['cat_id'] = $params['cat_id'];
		}


		$hours_per_day = $GLOBALS['egw_info']['user']['preferences']['calendar']['workdayends'] - $GLOBALS['egw_info']['user']['preferences']['calendar']['workdaystarts'];

		$element_index = array();
		foreach((array) $this->search(array(),false,'pe_start,pe_end',$extra_cols,
                        '',false,'AND',false,$filter) as $pe)
		{
			if (!$pe) continue;

			if($pe['pe_app'] == 'projectmanager') {// && $level < $params['depth']) {
				$project = true;
				$elements[] = $pe;
			} else {
				$pe['id'] = $pe['pe_app'].':'.$pe['pe_app_id'].':'.$pe['pe_id'];
				$pe['text'] = $pe['pe_title'];
				$pe['parent'] = 'projectmanager::'.$pm_id;
				$pe['start_date'] = egw_time::to((int)$pe['pe_start'],egw_time::DATABASE);
				$pe['duration'] = (float)($params['planned_times'] ? $pe['pe_planned_time'] : $pe['pe_used_time']);
				if($pe['pe_end'] && !$pe['duration'])
				{
					$pe['end_date'] = egw_time::to((int)$pe['pe_end'],egw_time::DATABASE);
				}
				$pe['progress'] = ((int)substr($this->project->data['pe_completion'],0,-1))/100;
				$pe['edit'] = $this->check_acl(EGW_ACL_EDIT, $pe);

				// Set field for filter to filter on
				$pe['filter'] = $pe['pe_completion'] > 0 ? ($pe['pe_completion'] != 100 ? 'ongoing' : 'done') : 'not';

				$elements[] = $pe;
			}

			$element_index[$pe['pe_id']] = $pe;
		}

		// Get project children
		if($project)
		{
			foreach($elements as &$pe)
			{
				// 0 duration tasks must be handled specially to avoid errors
				if(!$pe['duration']) $pe['duration'] = 1;
				$params['level'] = $level + 1;
				if($pe['pe_app'] == 'projectmanager')
				{
					$pe = $this->add_project($data, $pe['pe_app_id'], $params);
				}
			}
		}

		// adding the constraints
		if($params['constraints'])
		{
			foreach((array)$this->constraints->search(array('pm_id'=>$pm_id, 'pe_id'=>array_keys($element_index))) as $constraint)
			{
				// IDs have to match what we give the gantt chart
				$start = $element_index[$constraint['pe_id_start']];
				$end = $element_index[$constraint['pe_id_end']];
				$constraint['pe_id_start'] = $start ? $start['pe_app'].':'.$start['pe_app_id'].':'.$start['pe_id'] : 'milestone:'.$constraint['ms_id'];
				$constraint['pe_id_end'] = $end ? $end['pe_app'].':'.$end['pe_app_id'].':'.$end['pe_id'] : 'milestone:'.$constraint['ms_id'];
				error_log(array2string($constraint));
				$data['links'][] = array(
					'id' => $constraint['pm_id'] . ':'.$constraint['pe_id_start'].':'.$constraint['pe_id_end'],
					'source' => $constraint['pe_id_start'],
					'target' => $constraint['pe_id_end'],
					// TODO: Get proper type
					'type' => $constraint['constraint_type']=='start' || $constraint['constraint_type'] =='milestone' ? 0 : 3
				);
			}			
		}
		return $elements;
	}

	/**
	 * User updated start date or duration from gantt chart
	 */
	public static function ajax_update($values, $params)
	{
		if($params['planned_times'] == 'false') $params['planned_times'] = false;
		if($values['pe_id'])
		{
			$pe_bo = new projectmanager_elements_bo((int)$values['pm_id']);
			$pe_bo->read(array('pe_id' => (int)$values['pe_id']));
			$keys = array();
			$keys['pe_completion'] = (int)($values['progress'] * 100).'%';
			if(array_key_exists('duration', $values))
			{
				$keys['pe_' . ($params['planned_times'] ? 'planned' : 'used') .'_time'] = $values['duration'];
			}
			if(array_key_exists('start_date', $values))
			{
				$keys['pe_' . ($params['planned_times'] ? 'planned' : 'real') . '_start'] = egw_time::to($values['start_date'],'ts');
			}
			if(array_key_exists('end_date', $values))
			{
				$keys['pe_' . ($params['planned_times'] ? 'planned' : 'real') . '_end'] = egw_time::to($values['end_date'],'ts');
			}
			foreach($keys as $field => $val)
			{
				if(is_int($val)) error_log("$field: " . egw_time::to($val));
			}
			if($keys)
			{
				$result = $pe_bo->save($keys);
			}
		}
		else if ($values['ms_id'])
		{
			// Update milestone
			$pe_bo = new projectmanager_elements_bo((int)$values['pm_id']);
			$milestone = $pe_bo->milestones->read((int)$values['ms_id']);
			$pe_bo->milestones->save(array('ms_date' => egw_time::to($values['start_date'],'ts')));
		}
		else if ($values['pm_id'])
		{
			$pm_bo = new projectmanager_bo((int)$values['pm_id']);
			$keys['pm_completion'] = (int)($values['progress'] * 100).'%';
			if(array_key_exists('duration', $values))
			{
				$keys['pm_' . ($params['planned_times'] ? 'planned' : 'used') .'_time'] = $values['duration'];
			}
			if(array_key_exists('start_date', $values))
			{
				$keys['pm_' . ($params['planned_times'] ? 'planned' : 'real') . '_start'] = egw_time::to($values['start_date'],'ts');
			}
			if(array_key_exists('end_date', $values))
			{
				$keys['pm_' . ($params['planned_times'] ? 'planned' : 'real') . '_end'] = egw_time::to($values['end_date'],'ts');
			}
			if($keys)
			{
				$result = $pm_bo->save($keys);
			}
		}
		else if ($values['id'] && $values['source'] && $values['target'])
		{
			// Link added or removed
			$pe_bo = new projectmanager_elements_bo((int)$pm_id);

			error_log(array2string($values));
			list(,$pm_id) = explode('::',$values['parent']);
			list(,$m_start_id,$start_id) = explode(':',$values['source']);
			list(,$m_end_id,$end_id) = explode(':',$values['target']);
			$keys = array(
				'pm_id' => $pm_id,
				'pe_id_start' => (int)$start_id,
				'pe_id_end' => (int)$end_id,
				'ms_id' => !(int)$start_id ? $m_start_id : (!(int)$end_id ? $m_end_id : 0)
			);
			// Gantt chart gives new links integer IDs
			if($values['id'] && is_numeric($values['id']))
			{
				$pe_bo->constraints->save($keys);

				// Return the new key so we can tell new from old
				egw_json_response::get()->data($keys['pm_id'] . ':'.$values['source'].':'.$values['target']);
			}
			else if ($values['id'])
			{
				$pe_bo->constraints->delete($keys);
			}
		}
		else
		{
			error_log(array2string($values));
		}
		error_log(__METHOD__ .' Save ' . array2string($keys) . '= ' .$result);
	}
	
}
?>
