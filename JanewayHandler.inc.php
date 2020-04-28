<?php

/**
 *
 * Plugin for exporting data for ingestion by Janeway.
 * Written by Andy Byers, Birkbeck College
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
		Locale::requireComponents(LOCALE_COMPONENT_OJS_MANAGER, LOCALE_COMPONENT_PKP_MANAGER);
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
				$body .= PKPString::html2text($reviewFormElement->getLocalizedQuestion()) . ": \n";
				$reviewFormResponse = $reviewFormResponseDao->getReviewFormResponse($reviewId, $reviewFormElement->getId());
				if ($reviewFormResponse) {
					$possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
					if (in_array($reviewFormElement->getElementType(), $reviewFormElement->getMultipleResponsesElementTypes())) {
						if ($reviewFormElement->getElementType() == REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
							foreach ($reviewFormResponse->getValue() as $value) {
								$body .= "\t" . PKPString::html2text($possibleResponses[$value-1]['content']) . "\n";
							}
						} else {
							$body .= "\t" . SPKPString::html2text($possibleResponses[$reviewFormResponse->getValue()-1]['content']) . "\n";
						}
						$body .= "\n";
					} else {
						$body .= "\t" . $reviewFormResponse->getValue() . "\n\n";
					}
				}
			}
			$body .= "------------------------------------------------------\n\n";
		} else {
			foreach ($article_comments as $comment) {
				$comment_data = $comment->_data;
				$body .= $comment_data['datePosted'] . ' - ' . $comment_data['comments'] . "\n\n";
			}
		}

		return $body;
	}

	function utf8ize($d) {

		if (is_array($d)) {
			foreach ($d as $k => $v) 
				$d[$k] = $this->utf8ize($v);
		} elseif (is_object($d)) {
			foreach ($d as $k => $v) 
				$d->$k = $this->utf8ize($v);
		} else {
			if(mb_detect_encoding($d) != "UTF-8") {
				$d = utf8_encode($d); 
			}
		}

		return $d;
	}

	function build_download_url($journal, $submission_id, $file_id) {
		if ($journal && $submission_id && $file_id) {
			return $journal->getUrl() . '/editor/downloadFile/' . $submission_id . '/' . $file_id;
		} else {
			return '';
		}
		
	}

	//
	// views
	//
	
	/* handles requests to:
		/janeway/
		/janeway/index/
	*/
	function index($args, &$request) {

		$user = $this->journal_manager_required($request);
		$journal =& $request->getJournal();
		$request_type = $_GET['request_type'];

		import('classes.file.ArticleFileManager');
		$editorSubmissionDao =& DAORegistry::getDAO('EditorSubmissionDAO');
		$sectionEditorSubmissionDao =& DAORegistry::getDAO('SectionEditorSubmissionDAO');

		if ($request_type == 'in_review') {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInReview($journal->getId(), 0, 0);
		} elseif ($request_type == 'in_editing') {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInEditing($journal->getId(), 0, 0);
		} elseif ($request_type == 'published') {
			$articleDao =& DAORegistry::getDAO('PublishedArticleDAO');
			$submissions =& $articleDao->getPublishedArticlesByJournalId($journal->getId());
		} else {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInReview($journal->getId(), 0, 0);
		}
		
		$submissions_array = array();

		foreach ($submissions->toArray() as $submission) {
			$submission_array = array();

			if ($request_type == 'published') {
				$submission =& $sectionEditorSubmissionDao->getSectionEditorSubmission($submission->getId());
			}

			// Generic Submission Meta
			$submission_array['ojs_id'] = $submission->getId();
			$submission_array['title'] = $submission->getArticleTitle();
			$submission_array['abstract'] = $submission->getArticleAbstract();
			$submission_array['section'] = $submission->getSectionTitle();
			$submission_array['language'] = $submission->getLanguage();
			$submission_array['date_submitted'] = $submission->getDateSubmitted();
			$submission_array['keywords'] = $submission->getLocalizedSubject();
			$submission_array['doi'] = $submission->getStoredPubId('doi');
			$submission_array['license'] = $submission->getLicenseURL();

			// Get submission file url
			$submission_array['manuscript_file_url'] = $journal->getUrl() . '/editor/downloadFile/' . $submission->getId() . '/' . $submission->getSubmissionFileId();
			$submission_array['review_file_url'] = $journal->getUrl() . '/editor/downloadFile/' . $submission->getId() . '/' . $submission->getReviewFileId();

			// Supp Files
			$suppDAO =& DAORegistry::getDAO('SuppFileDAO');
			$supp_files = $suppDAO->getSuppFilesByArticle($submission->getId());
			$supp_files_array = array();
			foreach ($supp_files as $supp_file) {
				$supp_file_array = array(
					'url' => $journal->getUrl() . '/article/downloadSuppFile/' . $submission->getId() . '/' . $supp_file->getBestSuppFileId($journal),
					'title' => $supp_file->getSuppFileTitle(),
				);
				array_push($supp_files_array, $supp_file_array);
			}
			$submission_array['supp_files'] = $supp_files_array;

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
					'email' => $author->getEmail(),
					'country' => $author->getCountry(),
					'orcid' => $author->getData('orcid'),
				);
				array_push($authors_array, $author_array);

				if ($author->getPrimaryContact()) {
					$submission_array['correspondence_author'] = $author->getEmail();
				}
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

					$review_array['date_requested'] = $review_data['dateAssigned'];
					$review_array['date_due'] = $review_data['dateDue'];
					$review_array['date_confirmed'] = $review->getDateConfirmed();
					$review_array['declined'] = $review_data['declined'];
					$review_array['date_acknowledged'] = $review->getDateAcknowledged();
					$review_array['recommendation'] = $review->getRecommendation();
					$review_array['date_complete'] = $review->getDateCompleted();
					$review_array['comments'] = $this->get_reviewer_comments($review->getReviewId(), $submission->getId());


					$user_dao = DAORegistry::getDAO('UserDAO');
					$user = $user_dao->getUser($review->getReviewerId());
					$review_array['first_name'] = $user->getFirstName();
					$review_array['last_name'] = $user->getLastName();
					$review_array['email'] = $user->getEmail();

					if ($review->getReviewerFileId()) {
						$review_array['review_file_url'] = $journal->getUrl() . '/editor/downloadFile/' . $submission->getId() . '/' . $review->getReviewerFileId();
					}
					

					array_push($reviews_array, $review_array);
				}
			}

			$submission_array['reviews'] = $reviews_array;

			// Copyedit assignments
			$copyeditor = $submission->getUserBySignoffType('SIGNOFF_COPYEDITING_INITIAL');
			$copyedit_dates = $submission->getSignoff('SIGNOFF_COPYEDITING_INITIAL');
			$copyedit_file = $submission->getFileBySignoffType('SIGNOFF_COPYEDITING_INITIAL');
			$copyediting_array = array();

			if ($copyeditor) {
				$initial_copyeditor_array = array(
					'email' => $copyeditor->getEmail(),
					'first_name' => $copyeditor->getFirstName(),
					'last_name' => $copyeditor->getLastName(),
					'notified' => $copyedit_dates->getdateNotified(),
					'underway' => $copyedit_dates->getdateUnderway(),
					'complete' => $copyedit_dates->getdateCompleted(),
				);
				if ($copyedit_file) {
					$initial_copyeditor_array['file'] = $this->build_download_url($journal, $submission->getId(), $copyedit_file->getFileId());
				}
				$copyediting_array['initial'] = $initial_copyeditor_array;

				$author_copyedit = $submission->getSignoff('SIGNOFF_COPYEDITING_AUTHOR');
				$author_copyedit_file = $submission->getFileBySignoffType('SIGNOFF_COPYEDITING_AUTHOR');
				$author_copyedit_array = array(
					'notified' => $author_copyedit->getdateNotified(),
					'underway' => $author_copyedit->getdateUnderway(),
					'complete' => $author_copyedit->getdateCompleted(),
				);

				if ($author_copyedit_file) {
					$author_copyedit_array['file'] = $this->build_download_url($journal, $submission->getId(), $author_copyedit_file->getFileId());
				}
				$copyediting_array['author'] = $author_copyedit_array;

				$final_copyedit = $submission->getSignoff('SIGNOFF_COPYEDITING_FINAL');
				$final_copyedit_file = $submission->getFileBySignoffType('SIGNOFF_COPYEDITING_FINAL');
				$final_copyedit_array = array(
					'notified' => $final_copyedit->getdateNotified(),
					'underway' => $final_copyedit->getdateUnderway(),
					'complete' => $final_copyedit->getdateCompleted(),
				);
				if ($final_copyedit_file) {
					$final_copyedit_array['file'] = $this->build_download_url($journal, $submission->getId(), $final_copyedit_file->getFileId());
				}

				$copyediting_array['final'] = $final_copyedit_array;

				$submission_array['copyediting'] = $copyediting_array;
			}

			// Proofreading assignments
			$proofreader = $submission->getUserBySignoffType('SIGNOFF_PROOFREADING_PROOFREADER');

			if ($proofreader) {
				$author_proof = $submission->getSignoff('SIGNOFF_PROOFREADING_AUTHOR');
				$proofing_array = array();

				$author_proofing_array = array(
					'notified' => $author_proof->getdateNotified(),
					'underway' => $author_proof->getdateUnderway(),
					'complete' => $author_proof->getdateCompleted(),
				);
				$proofing_array['author'] = $author_proofing_array;

				$proofreader_proof = $submission->getSignoff('SIGNOFF_PROOFREADING_PROOFREADER');
				$proofreader_proofing_array = array(
					'notified' => $proofreader_proof->getdateNotified(),
					'underway' => $proofreader_proof->getdateUnderway(),
					'complete' => $proofreader_proof->getdateCompleted(),
				);

				if ($copyeditor) {
					$proofreader_proofing_array['email'] = $copyeditor->getEmail();
				}
				
				$proofing_array['proofreader'] = $proofreader_proofing_array;

				$layout_proof = $submission->getSignoff('SIGNOFF_PROOFREADING_LAYOUT');
				$layout_proofing_array = array(
					'notified' => $layout_proof->getdateNotified(),
					'underway' => $layout_proof->getdateUnderway(),
					'complete' => $layout_proof->getdateCompleted(),
				);
				$proofing_array['layout'] = $layout_proofing_array;

				$articleCommentDao =& DAORegistry::getDAO('ArticleCommentDAO');
				$articleComments =& $articleCommentDao->getArticleComments($submission->getId(), COMMENT_TYPE_PROOFREAD);

				$comments_array = array();
				foreach ($articleComments as $comment) {
					$comment_user = $user_dao->getUser($comment->_data['authorId']);
					$comment_array = array(
						'title' => $comment->_data['commentTitle'],
						'comments' => $comment->_data['comments'],
						'author_email' => $comment_user->getEmail(),
						'posted' => $comment->_data['datePosted']
					);

					array_push($comments_array, $comment_array);
				}

				$proofing_array['comments'] = $comments_array;

				$submission_array['proofing'] = $proofing_array;
			}

			// Layout - grab an article's galleys.
			$layout_signoff = $submission->getSignoff('SIGNOFF_LAYOUT');
			$layout_file = $submission->getFileBySignoffType('SIGNOFF_LAYOUT');
			$layout_editor = $submission->getUserBySignoffType('SIGNOFF_LAYOUT');
			
			$sectionEditorSubmission =& $sectionEditorSubmissionDao->getSectionEditorSubmission($submission->getId());
			$galleys =& $sectionEditorSubmission->getGalleys();

			$layout_array = array(
				'notified' => $layout_signoff->getDateNotified(),
				'underway' => $layout_signoff->getDateUnderway(),
				'complete' => $layout_signoff->getDateCompleted(),
			);

			if ($layout_editor) {
				$layout_array['email'] = $layout_editor->getEmail();
			}

			// Fetch galleys
			$galleys_array = array();
			foreach ($galleys as $galley) {
				$galley_array = array(
					'label' => $galley->getGalleyLabel(),
					'file' => $this->build_download_url($journal, $submission->getId(), $galley->getFileId()),
				);
				array_push($galleys_array, $galley_array);
			}
			$layout_array['galleys'] = $galleys_array;
			$submission_array['layout'] = $layout_array;

			// Get Issue
			$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
			$publishedArticle =& $publishedArticleDao->getPublishedArticleByArticleId($submission->getId());
			if ($publishedArticle) {
				$issueDao =& DAORegistry::getDAO('IssueDAO');
				$issue =& $issueDao->getIssueById($publishedArticle->getIssueId());
				$issue_array = array(
					'issue_title' => $issue->getLocalizedTitle(),
					'issue_volume' => $issue->getVolume(),
					'issue_number' => $issue->getNumber(),
					'issue_year' => $issue->getYear(),
					'date_published' => $publishedArticle->getDatePublished(),
				);
				$submission_array['publication'] = $issue_array;
			}

			// Create file array
			array_push($submissions_array, $submission_array);
		}

		$out = array_values($submissions_array);
		header('Content-Type: application/json');
		echo json_encode($submissions_array);
	}

}

?>
