<?php
App::uses('AppController', 'Controller');
class CodedpapersController extends AppController {
	public $uses = array('Codedpaper', 'Study', 'Test', 'User');
	function isAuthorized($user = null, $request = null) {
		parent::isAuthorized($user);

		# Admins can view all pages.
		if ( $this->isAdmin ) {
			return true;
		}

		$req_action = $this->request->params['action'];

		switch ($req_action) {
			# These pages are accessible to all users.
			case 'add':
			case 'compare':
			case 'entry':
			case 'index':
			case 'morestudies':
			case 'moretests':
			case 'view':
				return true;
				break;

			case 'reviewed':
			case 'delete':
				return ( $this->isSeniorCoder || $this->isAdmin );
				break;

			default:
				die('Access permissions not set for route.');
		}

		$params = $this->request->params['pass'];
		if ( count($params) > 0 ) {
			$codedpaper_id = $this->request->params['pass'][0];
			$this->Codedpaper->id = $codedpaper_id;
			if (!$this->Codedpaper->exists()) {
			    throw new NotFoundException('Invalid coded paper');
			}
			else {
				# Senior coders can see any codedpaper
				if ($this->isSeniorCoder) {
					return true;
				}

				# Coders can see their own codedpapers
				$allowed = $this->Codedpaper->find('first',array(
					"recursive" => -1,
					"conditions" => array(
						'user_id' => $this->Auth->user('id'),
						'id' => $codedpaper_id
					),
				));
				if( $allowed['Codedpaper']['user_id'] == $this->Auth->user('id')) {
					return true;
				}
			}
		}

		return $this->isAdmin; # allow admins to do anything
	}
	public function add ($id = NULL) {

		$this->Codedpaper->create();
		$this->Codedpaper->Paper->id = $id;

		if (!$this->Codedpaper->Paper->exists()) {
		    throw new NotFoundException('Invalid paper');
		}

		# Is this a "review" - i.e., a comparitive review by a senior coder.
		$isReview = false;

		# Must have "review" param passed. Value doesn't matter
		if (array_key_exists('review', $this->request->query)) {
			# Only senior coders or admins can create review codings
			if ( $this->isSeniorCoder ) {
				$isReview = true;
			} else {
				throw new ForbiddenException('Only Senior Coders may create review codings.');
			}
		}

		$insertcp = $this->Codedpaper->createDummy(
			$id,
			$this->Auth->user('id'),
			true, # cascade. I have no idea what this is, but it's the default value. (LyndsySimon)
			$isReview
		);
		$newCodedPaperId = $insertcp['cid'];
		$message = $insertcp['message'];
		$alertClass = $insertcp['alert'];

		$this->Session->setFlash($message, $alertClass);

		if($newCodedPaperId !== null) {
			$this->redirect('entry/' . $newCodedPaperId);
		} else {
			$this->redirect('index/');
		}
	}

	public function entry($id = NULL) {
		// Add custom form field helper
		$this->helpers[] = 'FormField';

		// Set the object in $this->Codedpaper to the Codedpaper we're looking at.
		$this->Codedpaper->id = $id;

		// Was the study complete when we started? Need to know this later.
		$wasComplete = $this->Codedpaper->field("completed");

		// Codedpapers should be added through the above "add" action.
		if (!$this->Codedpaper->exists())
		    throw new NotFoundException('Invalid coded paper');

		$isReview = $this->Codedpaper->field('is_review');

		if ( $isReview ) {
			$codings = $this->Codedpaper->find(
				'all',
				array(
					'conditions' => array(
						'paper_id' => $this->Codedpaper->field('paper_id'),
						'is_review' => false,
						'completed' => true,
					),
					'contain' => array(
						'User' => 'username',
						'Study' => array(
							'Test' => array()
						),
					),
				)
			);
		} else {
			$codings = array();
		}
		$this->set('otherCodings', $codings);

		// Add list of referenced papers to the view.
		$this->set(array('referenced_papers' => $this->Codedpaper->Study->getReplicable($id)));

		// Attempt to save any submitted info
		if ($this->request->is('put') OR $this->request->is('ajax'))
		{

			// each study
			if(isset($this->request->data['Study']))
			{
				for($i=0; $i<sizeof($this->request->data['Study']); $i++)
				{
					// each test
					if( !isset($this->request->data['Study'][$i]) ){
						break;
					}
					for($j=0; $j<sizeof($this->request->data['Study'][$i]['Test']); $j++)
					{
						if( is_array(@$this->request->data['Study'][$i]['Test'][$j]['methodology_codes']) )
						{
							// join the selected values into a comma-separated string
							$this->request->data['Study'][$i]['Test'][$j]['methodology_codes'] = implode(
								$this->request->data['Study'][$i]['Test'][$j]['methodology_codes'],
								','
							);
						}
					}
				}
			}

			if($this->Codedpaper->saveAssociated($this->request->data, array("deep" => TRUE)	))
			{
				$msg = __('Study saved.');
				$kind = 'alert-info';
			}
			else {
				$msg = __('Study could not be saved!');
				$kind = 'alert-error';
			}

			$this->Session->setFlash($msg,$kind);

			// Handle validation errors (if any) resulting from save.
			$errors = array_unique(Set::flatten($this->Codedpaper->validationErrors));
			if(!empty($errors))
			{
				function inc($matches) {
				    return ++$matches[1];
				}

				foreach($errors AS $field => $error) {
					$field =  preg_replace_callback( "|(\d+)|", "inc", $field);
					$field = Inflector::humanize(str_replace("."," ",$field));

					$msg .= "<br>". $field . ": ". $error;
				}
			}
		}


		if ($this->request->is('ajax')) {
			$this->set(compact('msg','kind'));
			$this->render('message');
		} else {
			if( isset($msg) ) {
				$this->Session->setFlash($msg,$kind);
			}
			if( empty($errors) )
			{
				$this->request->data = $this->Codedpaper->findDeep($id);
			} else {
				// Get the title and abstract for the paper.
				$saved = $this->Codedpaper->findById($id);
				$this->request->data['Paper'] = $saved['Paper'];
			}

		}

		// Set flag to prevent changes if appropriate.
		$isLocked = false;

		// See if a review coding exists.
		if ( (! $this->Codedpaper->field('is_review')) && $this->Codedpaper->Paper->field('senior_coding_claimed') ) {
			$isLocked = true;
		}

		$this->set( 'locked', $isLocked );

		// If this is a review coding and it has just been marked complete, send an email.
		if( $this->Codedpaper->field('is_review') && $this->Codedpaper->field('completed') && ! $wasComplete ) {
			foreach ( $codings as $coding ) {
				$user = $this->User->findById($coding['Codedpaper']['user_id']);
				$email = new CakeEmail('smtp');
				$email->to($user['User']['email']) //)
					->subject("Coding Review Completed")
					->send("The review of your Archival Project coding \"" . $coding['Paper']['title'] . "\" is complete.");
			}
		}
	}

	public function delete($id = NULL) {

		$this->Codedpaper->id = $id;

		if ( ! $this->Codedpaper->exists() ) {
			throw new NotFoundException('Invalid coded paper');
		}

		$codedPaper = $this->Codedpaper->findDeep($id);

		if ( $codedPaper['Codedpaper']['user_id'] !== $this->Auth->user('id') ) {
			if ( ! $this->isAdmin ) {
				throw new ForbiddenException('Only admins may delete others\' review codings.');
			}
		}

		$studiesToDelete = array();
		$studiesToUpdate = array();
		$testsToDelete = array();
		$testsToUpdate = array();

		foreach ( $codedPaper['Study'] as $study) {
			// Add study to list to be deleted
			array_push($studiesToDelete, $study['id']);
			$studiesToUpdate = array_merge($studiesToUpdate, $study['ReviewOf']);

			foreach ( $study['Test'] as $test ) {
				// Add test to list to be deleted
				array_push($testsToDelete, $test['id']);
				$testsToUpdate = array_merge($testsToUpdate, $test['ReviewOf']);
			}
		}

		for ( $i=0 ; $i < sizeof($studiesToUpdate) ; $i++ ) {
			$studiesToUpdate[$i]['reviewed_id'] = null;
		}
		$this->Study->saveMany($studiesToUpdate, array(
			'validate' => false,
			'fieldList' => array('reviewed_id')
		));

		for ( $i=0 ; $i < sizeof($testsToUpdate) ; $i++ ) {
			$testsToUpdate[$i]['reviewed_id'] = null;
		}
		$this->Test->saveMany(
			$testsToUpdate, array(
				'validate' => false,
				'fieldList' => array('reviewed_id')
		));

		foreach ( $testsToDelete as $test ) { $this->Test->delete($test); }
		foreach ( $studiesToDelete as $study ) { $this->Study->delete($study); }

		$this->Codedpaper->delete($id);

		$this->redirect('reviewed/');

	}

	public function compare ($id1 = NULL, $id2 = NULL) {
		if (!$this->Codedpaper->exists($id1))
		    throw new NotFoundException('First paper does not exist.');
		if (!$this->Codedpaper->exists($id2))
		    throw new NotFoundException('Second paper does not exist.');
		if($this->Codedpaper->field('paper_id',array('id' => $id1))!== $this->Codedpaper->field('paper_id',array('id' => $id2)))
			throw new NotFoundException('These are codings of two different papers.');
		if($this->Codedpaper->field('completed',array('id' => $id1))==false OR $this->Codedpaper->field('completed',array('id' => $id2))==false)
			throw new NotFoundException('One of these papers is not yet marked as completely coded.');

		$comparison = $this->Codedpaper->compare($id1,$id2);
		$this->set('c1',$comparison[0]);
		$this->set('c2',$comparison[1]);
		$this->set('keys',$comparison[2]);
	}
	public function view($id = null) {
		$this->Codedpaper->id = $id;

		if (!$this->Codedpaper->exists()) {
		    throw new NotFoundException('Invalid coded paper');
		}
		$this->request->data = $this->Codedpaper->findDeep($id);

		$referenced_papers = $this->Codedpaper->Study->getReplicable($id);

		$onlyView = true;

		$this->set(compact('referenced_papers','onlyView'));

		$this -> render('entry'); ## added a couple of hooks in code.ctp
	}

	public function index() {
		$this->set(
			'my_codings',
			$this->Codedpaper->find('all',
				array(
					'recursive' => 1,
					'conditions' => array(
						'is_review' => false,
						'Codedpaper.user_id' => $this->Auth->user('id'),
					),
					'order' => array('Codedpaper.completed DESC', 'Codedpaper.modified ASC'),
				)
			)
		);

		if ( $this->isAdmin || $this->isSeniorCoder ) {
			$this->set(
				'all_codings',
				$this->Codedpaper->find('all',
					array(
						'recursive' => 1,
						'conditions' => array('is_review' => false),
					)
				)
			);
		}
	}

	public function reviewed() {

		$this->set('myCompleteReviewCodings', $this->Codedpaper->find('all',
			array(
				'recursive' => 1,
				'conditions' => array(
					'is_review' => true,
					'completed' => true,
					'user_id' => $this->Auth->user('id')
				),
			)
		));

		$this->set('myIncompleteReviewCodings', $this->Codedpaper->find('all',
			array(
				'recursive' => 1,
				'conditions' => array(
					'is_review' => true,
					'completed' => false,
					'user_id' => $this->Auth->user('id')
				),
			)
		));

		$this->set('allReviewCodings', $this->Codedpaper->find('all',
			array(
				'recursive' => 1,
				'conditions' => array(
					'is_review' => true,
				),
			)
		));


	}

}
