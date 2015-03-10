<?php

namespace xHR;

class Controller_Acl extends \AbstractController {

	public $document=null;
	public $permissions=array(
		
		'can_view'=>'No',
		
		'allow_add'=>'No',
		'allow_edit'=>'No',
		'allow_del'=>'No',

		'can_submit'=>'No',
		'can_select_outsource'=>'No',
		'can_approve'=>'No',
		'can_reject'=>'No',
		'can_forward'=>'No',
		'can_receive'=>'No',
		'can_accept'=>'No',
		'can_cancel'=>'No',
		'can_start_processing'=>'No',
		'can_mark_processed'=>'No',
		'can_assign'=>'No',
		'can_assign_to'=>false,
		
		'can_manage_tasks'=>'No',
		'task_types'=>false,
		'can_send_via_email'=>'No',

		'can_see_communication'=>'No',
		'can_see_deep_communicatoin'=>'No',

		);

	public $self_only_ids=array();
	public $include_colleagues=array();
	public $include_subordinates=array();

	function init(){
		parent::init();

		if(! $this->document){
			if(!$this->owner->getModel())
				throw $this->exception('document not setted and model not found');

			if(! $this->owner->getModel() instanceof \Model_Document)
				throw $this->exception("Model ". get_class($this->owner->getModel()). " Must Inherit Model_Document");
			
			if($this->owner->getModel() instanceof \xProduction\Model_Task){
				$this->document = get_class($this->owner->getModel()). '\\'.$this->owner->getModel()->get('document_type');
			}else{
				$this->document = get_class($this->owner->getModel());
			}

		}

		$this->document = str_replace("Model_", "", $this->document);

		$dept_documents = $this->add('xHR/Model_Document');
		$dept_documents->addCondition('department_id', $_GET['department_id']?:$this->api->current_employee->department()->get('id'));
		$dept_documents->addCondition('name', $this->document);
		$dept_documents->tryLoadAny();

		if(!$dept_documents->loaded()) $dept_documents->save();

		$acl_model = $this->add('xHR/Model_DocumentAcl');
		$acl_model->addCondition('post_id',$this->api->current_employee->post()->get('id'));
		$acl_model->addCondition('document_id',$dept_documents->id);

		$acl_model->tryLoadAny();
		if(!$acl_model->loaded()) $acl_model->save();

		foreach ($this->permissions as $key => $value) {
			$this->permissions[$key] = $acl_model[$key];
		}

		$this->self_only_ids = array($this->api->current_employee->id);
		
		$this->include_colleagues = $this->api->current_employee->getColleagues();
		$this->include_colleagues[] = $this->self_only_ids[0];

		$this->include_subordinates = $this->api->current_employee->getSubordinats();
		$this->include_subordinates[] = $this->self_only_ids[0];

		$this->my_teams = $this->api->current_employee->getTeams();
		
		// CRUD
		if($this->owner instanceof \CRUD){
			// check add edit delete
			$this->doCRUD();

			if($this->owner->isEditing()){
				$this->doFORM();
			}else{
				$this->doGRID();
			}
		}elseif($this->owner instanceof \Grid){
			$this->doGRID();
		}elseif ($this->owner instanceof \Form){
			$this->doFORM();
		}else{
			// its just view
			$this->doVIEW();
		}

		// Grid
		// Form
			// Fields ??? readonly 
		// Page
		// View

	}

	function doCRUD(){

		if(!$this->owner->isEditing()){
			$btn = $this->owner->grid->buttonset->addButton()->set('ACL APPLIED');
			$self= $this;
			$vp = $this->owner->add('VirtualPage')->set(function($p)use($self){
				$can_view_column = 'can_view'; 
				if(isset($self->owner->model->actions)){
					foreach ($self->permissions as $action=>$acl) {
						if(!in_array($action, array_keys($self->owner->model->actions)))
							unset($self->permissions[$action]);
						else{
							if(is_array($self->owner->model->actions[$action]) AND isset($self->owner->model->actions[$action]['caption'])){
								if($action==$can_view_column) $can_view_column = $self->owner->model->actions[$action]['caption'];
								$self->permissions[$self->owner->model->actions[$action]['caption']] = $self->permissions[$action];
								unset($self->permissions[$action]);
							}
						}
					}
				}

				foreach ($self->permissions as $action => $value) {
					$view_type='Success';
					if($action == $can_view_column){
						if($value=='No')
							$view_type='Error';
						elseif($value!='All')
							$view_type='Warning';
					}
					$p->add('View_'.$view_type)->set($action." => " . $value);
				}
			});

			if($btn->isClicked()){
				$this->owner->js()->univ()->frameURL('Your ACL Status',$vp->getURL())->execute();
			}
		}

		if($this->permissions['can_view'] != 'All'){
			$this->filterModel(isset($this->owner->model->acl_field)?$this->owner->model->acl_field:'created_by_id');
		}

		if(!$this->permissions['allow_add']){
			$this->owner->allow_add=false;
			// $this->owner->add_button->destroy();
		}

		if($this->permissions['allow_edit']=='No'){
			$this->owner->allow_edit=false;
			$this->owner->grid->removeColumn('edit');
		}else{
			$this->filterGrid('edit');
		}

		if($this->permissions['allow_del'] == 'No'){
			$this->owner->allow_del=false;
			$this->owner->grid->removeColumn('delete');
		}else{
			$this->filterGrid('delete');
		}

		if($this->permissions['can_submit'] != 'No'){
			$this->manageAction('submit');
		}
	
		if($this->permissions['can_select_outsource'] and $this->permissions['can_select_outsource'] !='No'){
			$this->manageAction('select_outsource');
		}		

		if($this->permissions['can_approve'] and $this->permissions['can_approve'] !='No'){
			$this->manageAction('approve');
		}	
		
		if($this->permissions['can_reject'] !='No'){
			$this->manageAction('reject');
		}

		if($this->permissions['can_assign'] !='No'){			
			$this->manageAction('assign');
		}

		if($this->permissions['can_receive']){
			$this->manageAction('receive');
		}

		if($this->permissions['can_accept'] and $this->permissions['can_accept'] !='No'){
			$this->manageAction('accept');
		}

		if($this->permissions['can_cancel'] and $this->permissions['can_cancel'] !='No'){
			$this->manageAction('cancel');
		}

		if($this->permissions['can_forward'] !='No'){
			$this->manageAction('forward');
		}

		if($this->permissions['can_start_processing'] !='No'){
			$this->manageAction('start_processing');
		}

		if($this->permissions['can_mark_processed'] !='No'){
			$this->manageAction('mark_processed');
		}

		if($this->permissions['can_manage_tasks'] !='No'){
			if($tt=$this->permissions['task_types']){
				switch ($tt) {
					case 'job_card_tasks':
						$this->addRootDocumentTaskPage();
						break;
					case "job_card_current_status_tasks":
						$this->addDocumentSpecificTaskPage();
					break;

					case "job_card_all_status_tasks":
					break;

					default:
						# code...
						break;
				}
			}
		}

		if($this->permissions['can_see_communication'] != 'No'){
			$p=$this->owner->addFrame('Comm',array('icon'=>'user'));
			if($p){
				$p->add('xCRM/View_Communication',array('include_deep_communication'=>$this->permissions['can_see_deep_communication'],'document'=>$this->owner->getModel()->load($this->owner->id)));
			}
		}

		if($this->permissions['can_send_via_email'] !='No' AND $this->owner->model->hasMethod('send_via_email')){
			$this->owner->addAction('send_via_email',array('toolbar'=>false));		
			$this->filterGrid('send_via_email');
		}
	}

	function doGRID(){
	}

	function doFORM(){

	}

	function doVIEW(){

	}

	function manageAction($action_name){

		if($this->owner->model->hasMethod($action_name.'_page')){
			$action_page_function = $action_name.'_page';
			$p = $this->owner->addFrame(ucwords($action_name));
			if($p and $this->owner->isEditing('fr_'.$this->api->normalizeName(ucwords($action_name)))){
				$this->owner->model->tryLoad($this->owner->id);
				try{
					$this->api->db->beginTransaction();
						$function_run = $this->owner->model->$action_page_function($p);
					$this->api->db->commit();
				}catch(\Exception $e){
					$this->api->db->rollback();
					throw $e;
				}

				if($function_run ){
					$js=array();
					$js[] = $p->js()->univ()->closeDialog();
					// $js[] = $this->owner->js()->reload();
					$this->owner->js(null,$js)->execute();
				}
			}
			$this->filterGrid('fr_'.$this->api->normalizeName(ucwords($action_name)));
		}elseif($this->owner->model->hasMethod($action_name)){
			try{
				$this->api->db->beginTransaction();
					$this->owner->addAction($action_name,array('toolbar'=>false));		
				$this->api->db->commit();
			}catch(\Exception $e){
				$this->api->db->rollback();
				throw $e;
			}
			$this->filterGrid($action_name);
		}
	}

	function filterModel($filter_column='created_by_id'){
		// $acl =array('No'=>'No','Self Only'=>'Created By Employee',
		// 'Include Subordinats'=>'Created By Subordinates','Include Colleagues'=>'Created By Colleagues',
		// 'Include Subordinats & Colleagues'=>'Created By Subordinats or Colleagues',
		// 'Assigned To Me'=>'Assigned To Me','Assigned To My Team'=>'Assigned To Me & My Team',
		// 'If Team Leader'=>'If Team Leader','All'=>'All');

		if(!$this->owner->model->hasField($filter_column)){
			throw $this->exception("$filter_column must be defined in model " . get_class($this->owner->model));
		}
		$filter_ids = false;
		switch ($this->permissions['can_view']) {
			case 'Self Only':
				$filter_ids = $this->self_only_ids;
				break;
			case 'Include Subordinats':
				$filter_ids = $this->include_subordinates;
				break;
			case 'Include Colleagues':
				$filter_ids = $this->include_colleagues;
				break;
			case 'Include Subordinats & Colleagues':
				$filter_ids = $this->include_subordinates;
				$filter_ids = array_merge($filter_ids,$this->include_colleagues);
				break;

			case 'Assigned To Me':
				$this->owner->model->addCondition('employee_id',$this->self_only_ids);
			break;
			
			case 'Assigned To My Team':
				$this->owner->model->addCondition(
					$this->owner->model->dsql()->orExpr()
					->where('employee_id',$this->self_only_ids)
					->where('team_id',$this->my_teams)
					)
					;
			break;

			case 'If Team Leader':
			break;

			default: // No
				$filter_ids = array(0);
				break;
		}
		if($filter_ids) 
			$this->owner->model->addCondition($filter_column,$filter_ids);
	}

	function filterGrid($column){
		$filter_ids = null;
		switch ($this->permissions['can_'.$column]) {
			case 'Self Only':
				$filter_ids = $this->self_only_ids;
				break;
			case 'Include Subordinats':
				$filter_ids = $this->include_subordinates;
				break;
			case 'Include Colleagues':
				$filter_ids = $this->include_colleagues;
				break;
			case 'Include Subordinats & Colleagues':
				$filter_ids = $this->include_subordinates;
				$filter_ids = $filter_ids + $this->include_colleagues; 
				break;


			case 'Assigned To Me':
				$this->owner->grid->addMethod('format_flt_'.$column,function($g,$f){
					if($g->model->get('employee_id') != $g->api->current_employee->id){
						$g->current_row_html[$f] = "";
					}
				});

				$this->owner->grid->addFormatter($column,'flt_'.$column);
			break;
			
			case 'Assigned To My Team':
				$this->owner->grid->addMethod('format_flt_'.$column,function($g,$f){
					if($g->model->get('employee_id') != $g->api->current_employee->id AND !in_array($g->api->current_employee->id, $g->model->getTeamMembers())){
						$g->current_row_html[$f] = "";
					}
				});

				$this->owner->grid->addFormatter($column,'flt_'.$column);
			break;

			case 'If Team Leader':
				$this->owner->grid->addMethod('format_flt_'.$column,function($g,$f){
					if(!in_array($g->api->current_employee->id ,$g->model->getTeamLeaders())){
						$g->current_row_html[$f] = "";
					}
				});

				$this->owner->grid->addFormatter($column,'flt_'.$column);
			break;
			
			default: // All
				$filter_ids = null;
				break;
		}

		if($filter_ids!=null){
			$this->owner->grid->addMethod('format_flt_'.$column,function($g,$f)use($filter_ids){
				if(!in_array($g->model['created_by_id'], $filter_ids)){
					$g->current_row_html[$f] = "";//$g->model['created_by_id'] . " -- " .print_r($filter_ids,true);
				}
			});

			$this->owner->grid->addFormatter($column,'flt_'.$column);
		}
	}

	function addAssignPage(){
		$p= $this->owner->addFrame("Assign");
		
		if($p){
			$document = $this->owner->model->load($this->owner->id);
			$assigned_to = $document->assignedTo();
			$set_existing_assigned_to = false;
			switch ($this->permissions['can_assign_to']) {
				case 'Dept. Teams':
					$model = $p->api->current_employee->department()->teams();
					$field_caption = "Teams";
					$set_existing_assigned_to = $assigned_to instanceof \xProduction\Model_Team ? $assigned_to->id: false;
				break;

				case 'Dept. Employee':
					$model = $p->api->current_employee->department()->employees();
					$field_caption = "Employees";
					$set_existing_assigned_to = $assigned_to instanceof \xHR\Model_Employee ? $assigned_to->id: false;
				break;

				case 'Self Team Members':
					// $model = $p->api->current_employee->department()->teams();
					// $field_caption = "Teams";
				break;
			}

			$form = $p->add('Form');
			$field= $form->addField('DropDown','selected',$field_caption)->setEmptyText('Please Select Team')->validateNotNull(true);
			$form->addField('line','subject');
			$form->addField('text','message');
			$field->setModel($model);
			
			if($set_existing_assigned_to){
				$field->set($set_existing_assigned_to);
			}

			$form->addSubmit('Update');

			if($form->isSubmitted()){
				$model->load($form['selected']);
				$document->assignTo($model,$form['subject'],$form['message']);

				$form->js()->univ()->successMessage("sdfsd")->execute();
			}
		}
		return 'fr_'.$this->api->normalizeName("assign");
	}

	function addOutSourcePartiesPage(){
		$p= $this->owner->addFrame("Select Outsource",array('label'=>'OutSrc Parties','icon'=>'plus'));
		
		if($p){
			
			$current_job_card = $p->add('xProduction/Model_JobCard');
			$current_job_card->load($this->owner->id);

			$form = $p->add('Form');
			$osp = $form->addField('DropDown','out_source_parties')->setEmptyText('Not Applicable');
			$osp->setModel($current_job_card->department()->outSourceParties());
			
			if($selected_party = $current_job_card->outSourceParty()){
				$osp->set($selected_party->id);
			}

			$form->addSubmit('Update');

			if($form->isSubmitted()){		
				if($form['out_source_parties']){
					$party = $p->add('xProduction/Model_OutSourceParty');
					$party->load($form['out_source_parties']);
					$current_job_card->outSourceParty($party);
				}else{
					$current_job_card->removeOutSourceParty();
				}

				$p->js()->univ()->closeDialog()->execute();
			}
		}
		return 'fr_'.$this->api->normalizeName("Select Outsource");
	}

	function addRootDocumentTaskPage(){
		$p = $this->owner->addFrame("Task management");
		if($p){
			$crud = $p->add('CRUD');
			$task_model = $this->add('xProduction/Model_Task');
			$task_model->addCondition('document_type',$this->owner->getModel()->root_document_name);
			$task_model->addCondition('document_id',$this->owner->id);
			$crud->setModel($task_model);
			$crud->add('xHR/Controller_Acl');
		}
	}

	function getAlc(){
		return $this->permissions;
	}

}