<?php

/**
 *
 * Plugin for exporting data for ingestion by Janeway.
 * Written by Andy Byers, Birkbeck COllege
 *
 */



import('classes.handler.Handler');
require_once('JanewayDAO.inc.php');

function redirect($url) {
	header("Location: ". $url); // http://www.example.com/"); /* Redirect browser */
	/* Make sure that code below does not get executed when we redirect. */
	exit;
}

function raise404($msg='404 Not Found') {
	header("HTTP/1.0 404 Not Found");
	fatalError($msg);
	return;
}

function clean_string($v) {
	// strips non-alpha-numeric characters from $v	
	return preg_replace('/[^\-a-zA-Z0-9]+/', '',$v);
}

function login_required($user) {
	if ($user === NULL) {
		redirect($journal->getUrl() . '/login/signIn?source=' . $_SERVER['REQUEST_URI']);
	}
}

class JanewayHandler extends Handler {

	public $dao = null;

	function JanewayHandler() {
		parent::Handler();
		$this->dao = new JanewayDAO();
	}
	
	/* sets up the template to be rendered */
	function display($fname, $page_context=array()) {
		// setup template
		AppLocale::requireComponents(LOCALE_COMPONENT_OJS_MANAGER, LOCALE_COMPONENT_PKP_MANAGER);
		parent::setupTemplate();
		
		// setup template manager
		$templateMgr =& TemplateManager::getManager();
		
		// default page values
		$context = array(
			"page_title" => "Janeway"
		);
		foreach($page_context as $key => $val) {
			$context[$key] = $val;
		}

		$plugin =& PluginRegistry::getPlugin('generic', JANEWAY_PLUGIN_NAME);
		$tp = $plugin->getTemplatePath();
		$templateMgr->assign($context); // http://www.smarty.net/docsv2/en/api.assign.tpl

		// render the page
		$templateMgr->display($tp . $fname);
	}

	function journal_manager_required($request) {
		$user = $request->getUser();
		$journal = $request->getJournal();

		// If we have no user, redirect to index
		if ($user == NULL) {
			$request->redirect(null, 'index');
		}

		// If we have a user, grab their roles from the DAO
		$roleDao =& DAORegistry::getDAO('RoleDAO');
		$roles =& $roleDao->getRolesByUserId($user->getId(), $journal->getId());


		// Loop through the roles to check if the user is a Journal Manager
		$check = false;
		foreach ($roles as $role) {
			if ($role->getRoleId() == ROLE_ID_JOURNAL_MANAGER) {
				$check = true;
			}
		}

		// If user is a journal manager, return the user, if not, redirect to the user page.
		if ($check) {
			return $user;
		} else {
			$request->redirect(null, 'user');
		}

	}

	function get_reviewer_comments($review_id, $submission_id) {
		$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
		$view_reivew =& $reviewAssignmentDao->getReviewAssignmentById($review_id);
		$articleCommentDao =& DAORegistry::getDAO('ArticleCommentDAO');
		$article_comments =& $articleCommentDao->getArticleComments($view_reivew->getSubmissionId(), COMMENT_TYPE_PEER_REVIEW, $view_reivew->getId());
		$body = '';
		if ($view_reivew->getReviewFormId()) {
			$reviewFormId = $view_reivew->getReviewFormId();
			$reviewId = $view_reivew->getId();
			$reviewFormResponseDao =& DAORegistry::getDAO('ReviewFormResponseDAO');
			$reviewFormElementDao =& DAORegistry::getDAO('ReviewFormElementDAO');
			$reviewFormElements =& $reviewFormElementDao->getReviewFormElements($reviewFormId);
			foreach ($reviewFormElements as $reviewFormElement) {
				$body .= String::html2text($reviewFormElement->getLocalizedQuestion()) . ": \n";
				$reviewFormResponse = $reviewFormResponseDao->getReviewFormResponse($reviewId, $reviewFormElement->getId());
				if ($reviewFormResponse) {
					$possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
					if (in_array($reviewFormElement->getElementType(), $reviewFormElement->getMultipleResponsesElementTypes())) {
						if ($reviewFormElement->getElementType() == REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
							foreach ($reviewFormResponse->getValue() as $value) {
								$body .= "\t" . String::html2text($possibleResponses[$value-1]['content']) . "\n";
							}
						} else {
							$body .= "\t" . String::html2text($possibleResponses[$reviewFormResponse->getValue()-1]['content']) . "\n";
						}
						$body .= "\n";
					} else {
						$body .= "\t" . $reviewFormResponse->getValue() . "\n\n";
					}
				}
			}
			$body .= "------------------------------------------------------\n\n";
		} else {
			var_dump($article_comments);
		}

		return $body;
	}

	//
	// views
	//
	
	/* handles requests to:
		/janeeway/
		/janeeway/index/
	*/
	function index($args, &$request) {

		$user = $this->journal_manager_required($request);
		$journal =& $request->getJournal();
		$request_type = $_GET['request_type'];

		import('classes.file.ArticleFileManager');
		$editorSubmissionDao =& DAORegistry::getDAO('EditorSubmissionDAO');

		if ($request_type == 'in_review') {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInReview($journal->getId(), 0, 0);
		} elseif ($request_type == 'in_editing') {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInEditing($journal->getId(), 0, 0);
		} else {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInReview($journal->getId(), 0, 0);
		}
		
		$submissions_array = array();

		foreach ($submissions->toArray() as $submission) {
			$submission_array = array();

			// Generic Submission Meta
			$submission_array['title'] = $submission->getArticleTitle();
			$submission_array['abstract'] = $submission->getArticleAbstract();
			$submission_array['section'] = $submission->getSectionTitle();
			$submission_array['language'] = $submission->getLanguage();
			$submission_array['date_submitted'] = $submission->getDateSubmitted();

			// Get submission file url
			$submission_array['manuscript_file_url'] = $journal->getUrl() . '/editor/downloadFile/' . $submission->getId() . '/' . $submission->getSubmissionFileId();
			$submission_array['review_file_url'] = $journal->getUrl() . '/editor/downloadFile/' . $submission->getId() . '/' . $submission->getReviewFileId();

			// Authors
			$authors = $submission->getAuthors();
			$authors_array = array();
			foreach ($authors as $author) {
				$author_array = array(
					'first_name' => $author->getFirstName(), 
					'last_name' => $author->getLastName(),
					'email' => $author->getEmail(),
					'bio' => $author->getLocalizedBiography(),
					'affiliation' => $author->getLocalizedAffiliation(),
				);
				array_push($authors_array, $author_array);
			}
			$submission_array['authors'] = $authors_array;

			// Reviews
			$reviewAssignments =& $submission->getReviewAssignments();
			$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
			$reviews_array = array();

			foreach ($reviewAssignments as $reviews) {
				foreach ($reviews as $review) {
					$review_data = $review->_data;
					$review_object = $review;
					$review_array = array();

					$review_array['name'] = $review_data['reviewerFullName'];
					$review_array['date_requested'] = $review_data['dateAssigned'];
					$review_array['date_due'] = $review_data['dateDue'];
					$review_array['date_confirmed'] = $review->getDateConfirmed();
					$review_array['declined'] = $review_data['declined'];
					$review_array['date_acknowledged'] = $review->getDateAcknowledged();
					$review_array['recommendation'] = $review->getRecommendation();
					$review_array['date_complete'] = $review->getDateCompleted();
					$review_array['comments'] = $this->get_reviewer_comments($review->getReviewId());

					if ($review->getReviewerFileId()) {
						$review_array['review_file_url'] = $journal->getUrl() . '/editor/downloadFile/' . $submission->getId() . '/' . $review->getReviewerFileId();
					}
					

					array_push($reviews_array, $review_array);
				}
			}

			$submission_array['reviews'] = $reviews_array;

			// Create file array
			array_push($submissions_array, $submission_array);
		}
		
		$out = array_values($submissions_array);
		$context = array(
			"page_title" => "Janeway Export",
			"json" => json_encode($out),
		);
		$this->display('index.tpl', $context);
	}

}

?>