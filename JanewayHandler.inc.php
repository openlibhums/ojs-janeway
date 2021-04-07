<?php
error_reporting(E_ERROR);

/**
 *
 * Plugin for exporting data for ingestion by Janeway.
 * Written by Andy Byers, Birkbeck COllege
 *
 */



import('classes.handler.Handler');
import('classes.file.ArticleFileManager');
require_once('JanewayDAO.inc.php');
require_once('JanewayString.inc.php');

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
				$body .= JanewayString::html2text($reviewFormElement->getLocalizedQuestion()) . ": \n";
				$reviewFormResponse = $reviewFormResponseDao->getReviewFormResponse($reviewId, $reviewFormElement->getId());
				if ($reviewFormResponse) {
					$possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
					if (in_array($reviewFormElement->getElementType(), $reviewFormElement->getMultipleResponsesElementTypes())) {
						if ($reviewFormElement->getElementType() == REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
							foreach ($reviewFormResponse->getValue() as $value) {
								$body .= "\t" . JanewayString::html2text($possibleResponses[$value-1]['content']) . "\n";
							}
						} else {
							$body .= "\t" . JanewayString::html2text($possibleResponses[$reviewFormResponse->getValue()-1]['content']) . "\n";
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
		} elseif (is_string($d)){
			$encoding =  mb_detect_encoding($d);
			$d = iconv($encoding, 'UTF-8', $d);
		}

		return $d;
	}

	function getDraftDecisions($sectionEditorSubmissionDAO, $articleId) {
		$rows =& $sectionEditorSubmissionDAO->retrieve(
			'SELECT 
				dd.key_val as draft_key,
				dd.decision as recommendation,
				se.email as section_editor, 
				ed.email as editor,
				dd.status as status,
				dd.note as note,
				dd.attatchment as attachment,
				dd.body as body,
				dd.subject as subject
			 FROM draft_decisions dd
			 JOIN users se ON dd.junior_editor_id = se.user_id
			 JOIN users ed ON dd.senior_editor_id = ed.user_id
			 WHERE article_id = ?
			 ORDER BY dd.id',
				array($articleId)
		);
		$results = [];
		foreach($rows as $row){
			$results[$row["draft_key"]] = [];
			$results[$row["draft_key"]]["recommendation"]= $this->utf8ize($row["recommendation"]);
			$results[$row["draft_key"]]["section_editor"]= $row["section_editor"];
			$results[$row["draft_key"]]["editor"]= $row["editor"];
			$results[$row["draft_key"]]["status"]= $this->utf8ize($row["status"]);
			$results[$row["draft_key"]]["note"]= $this->utf8ize($row["note"]);
			$results[$row["draft_key"]]["body"]= $this->utf8ize($row["body"]);
			$results[$row["draft_key"]]["subject"]= $this->utf8ize($row["subject"]);
			$results[$row["draft_key"]]["attatchment"]= $row["attatchment"];
		}
		return $results;

	}

	function json_response($data) {
		header('Content-Type: application/json');
		$cleaned = $this->utf8ize($data);
		$json_data = json_encode($cleaned, JSON_PRETTY_PRINT);
		if ($json_data === false) {
			$err = json_last_error();
			header("HTTP/1.1 500 Internal Server Error");
			echo $err;
		} else {
			echo $json_data;
		}
	}

	function build_download_url($journal, $submission_id, $file_id) {
		if ($journal && $submission_id && $file_id) {
			return $journal->getUrl() . '/editor/downloadFile/' . $submission_id . '/' . $file_id;
		} else {
			return '';
		}

	}

	function encode_file_meta($journal, $submission, $file) {
		if ($file) {
			return array(
				'url' => $this->build_download_url($journal, $submission->getId(), $file->getFileId()),
				'date_uploaded' => $file->getDateUploaded(),
				'date_modified' => $file->getDateModified(),
				'mime_type' => $file->getFileType(),
				'file_name' => $file->getFileName(),
			);
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
		$article_id = $_GET['article_id'];

		import('classes.file.ArticleFileManager');
		$editorSubmissionDao =& DAORegistry::getDAO('EditorSubmissionDAO');
		$sectionEditorSubmissionDao =& DAORegistry::getDAO('SectionEditorSubmissionDAO');
		$user_dao = DAORegistry::getDAO('UserDAO');

		if ($article_id) {
			$submissions = array($editorSubmissionDao->getEditorSubmission($article_id));
		} elseif ($request_type == 'unassigned') {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsUnassigned($journal->getId(), 0, 0)->toArray();
		} elseif ($request_type == 'in_review') {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInReview($journal->getId(), 0, 0)->toArray();
		} elseif ($request_type == 'in_editing') {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInEditing($journal->getId(), 0, 0)->toArray();
		} elseif ($request_type == 'published') {
			$articleDao =& DAORegistry::getDAO('PublishedArticleDAO');
			$submissions =& $articleDao->getPublishedArticlesByJournalId($journal->getId())->toArray();
		} else {
			$submissions =& $editorSubmissionDao->getEditorSubmissionsInReview($journal->getId(), 0, 0)->toArray();
		}

		$submissions_array = array();

		foreach ($submissions as $submission) {
			$submission_array = array();
			$articleFileManager = new ArticleFileManager($submission->getId());

			if ($request_type == 'published') {
				$submission =& $sectionEditorSubmissionDao->getSectionEditorSubmission($submission->getId());
			}

			// Generic Submission Meta
			$submission_array['ojs_id'] = (int)$submission->getId();
			$submission_array['title'] = $submission->getArticleTitle();
			$submission_array['abstract'] = $submission->getArticleAbstract();
			$submission_array['section'] = $submission->getSectionTitle();
			$submission_array['language'] = $submission->getLanguage();
			$submission_array['date_submitted'] = $submission->getDateSubmitted();
			$submission_array['keywords'] = array_map('trim', explode(',', str_replace(';', ',', $submission->getLocalizedSubject())));
			$submission_array['doi'] = $submission->getStoredPubId('doi');
			if (method_exists($submission, "getLicenseUrl")){
				$submission_array['license'] = $submission->getLicenseURL();
			}

			// Get submission file url
			$submission_array['manuscript_file'] = $this->encode_file_meta($journal, $submission, $articleFileManager->getFile($submission->getSubmissionFileId()));
			$submission_array['review_file'] = $this->encode_file_meta($journal, $submission, $articleFileManager->getFile($submission->getReviewFileId()));
			$submission_array['editor_file'] = $this->encode_file_meta($journal, $submission, $articleFileManager->getFile($submission->getEditorFileId()));

			// Supp Files
			$suppDAO =& DAORegistry::getDAO('SuppFileDAO');
			$supp_files = $suppDAO->getSuppFilesByArticle($submission->getId());
			$supp_files_array = array();
			foreach ($supp_files as $supp_file) {
				$supp_file_array = $this->encode_file_meta($journal, $submission, $supp_file);
				array_push($supp_files_array, $supp_file_array);
			}
			$submission_array['supp_files'] = $supp_files_array;

			// Authors
			$authors = $submission->getAuthors();
			$authors_array = array();
			foreach ($authors as $author) {
				$author_array = array(
					'first_name' => $author->getFirstName(),
					'middle_name' => $author->getMiddleName(),
					'last_name' => $author->getLastName(),
					'email' => $author->getEmail(),
					'bio' => $author->getLocalizedBiography(),
					'affiliation' => $author->getLocalizedAffiliation(),
					'email' => $author->getEmail(),
					'country' => $author->getCountry(),
					'orcid' => $author->getData('orcid'),
					'sequence' => (float) $author->getSequence(),
				);
				array_push($authors_array, $author_array);

				if ($author->getPrimaryContact()) {
					$submission_array['correspondence_author'] = $author->getEmail();
				}
			}
			$submission_array['authors'] = $authors_array;
			// Editors
			$editors_assignments = $submission->getEditAssignments();
			$editors_array = [];
			
			foreach($editors_assignments as $ed){
				array_push(
					$editors_array,
					array(
						'email' => $ed->getEditorEmail(),
						'role' => $ed->isEditor ? 'editor' : 'section-editor',
						'notified' => $ed->getDateNotified(),
						'underway' => $ed->getDateUnderway(),
					)

				);
				
			}
			$submission_array['editors'] = $editors_array;

			// Reviews
			$submission_array['current_review_round'] = (int)$submission->getCurrentRound();
			$submission_array['decisions'] = $submission->getDecisions();
			$editorDecisions = $submission->getDecisions($submission->getCurrentRound());
			$last_decision = count($editorDecisions) >= 1 ? $editorDecisions[count($editorDecisions) - 1]: null;
			if ($last_decision) {
				$user = $user_dao->getUser($last_decision["editorId"]);
				$last_decision["editor"] = $user->getEmail();
			}
			$submission_array['latest_editor_decision'] = $last_decision;
			$revisions = $submission->getAuthorFileRevisions($submission->getCurrentRound());
			$submission_array['author_revision'] = count($revisions) >= 1 ? $this->encode_file_meta($journal, $submission, $revisions[count($revisions) - 1]): null;
			$reviewAssignments =& $submission->getReviewAssignments();
			$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
			$reviews_array = array();
			$sectionEditorSubmissionDAO =& DAORegistry::getDAO('SectionEditorSubmissionDAO');

			foreach ($reviewAssignments as $reviews) {
				foreach ($reviews as $review) {
					$review_data = $review->_data;
					$review_object = $review;
					$review_array = array();

					$review_array['round'] = (int)$review_data['round'];
					$review_array['date_requested'] = $review_data['dateAssigned'];
					$review_array['date_due'] = $review_data['dateDue'];
					$review_array['date_confirmed'] = $review->getDateConfirmed();
					$review_array['declined'] = $review->getDeclined() ? true: false;
					$review_array['cancelled'] = $review->getCancelled() ? true: false;
					$review_array['date_acknowledged'] = $review->getDateAcknowledged();
					$review_array['recommendation'] = $review->getRecommendation();
					$review_array['date_complete'] = $review->getDateCompleted();
					$review_array['comments'] = $this->get_reviewer_comments($review->getReviewId(), $submission->getId());
					if (method_exists($sectionEditorSubmissionDao, "getArticleDrafts")){
						$submission_array['draft_decisions'] = $this->getDraftDecisions($sectionEditorSubmissionDAO, (int)$submission->getId());
					}


					$user_dao = DAORegistry::getDAO('UserDAO');
					$user = $user_dao->getUser($review->getReviewerId());
					$review_array['first_name'] = $user->getFirstName();
					$review_array['middle_name'] = $user->getMiddleName();
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
			$copyediting_array["initial_file"] = $this->encode_file_meta($journal, $submission, $copyedit_file);
			$author_copyedit_file = $submission->getFileBySignoffType('SIGNOFF_COPYEDITING_AUTHOR');
			$copyediting_array['author_file'] = $this->encode_file_meta($journal, $submission, $author_copyedit_file);
			$final_copyedit = $submission->getSignoff('SIGNOFF_COPYEDITING_FINAL');
			$final_copyedit_file = $submission->getFileBySignoffType('SIGNOFF_COPYEDITING_FINAL');
			$copyediting_array['final_file'] = $this->encode_file_meta($journal, $submission, $final_copyedit_file);

			if ($copyeditor) {
				$initial_copyeditor_array = array(
					'email' => $copyeditor->getEmail(),
					'first_name' => $copyeditor->getFirstName(),
					'last_name' => $copyeditor->getLastName(),
					'notified' => $copyedit_dates->getdateNotified(),
					'underway' => $copyedit_dates->getdateUnderway(),
					'complete' => $copyedit_dates->getdateCompleted(),
					'file' => $copyediting_array["initial_file"],
				);
				$copyediting_array['initial'] = $initial_copyeditor_array;

				$author_copyedit = $submission->getSignoff('SIGNOFF_COPYEDITING_AUTHOR');
				$author_copyedit_file = $submission->getFileBySignoffType('SIGNOFF_COPYEDITING_AUTHOR');
				$author_copyedit_array = array(
					'notified' => $author_copyedit->getdateNotified(),
					'underway' => $author_copyedit->getdateUnderway(),
					'complete' => $author_copyedit->getdateCompleted(),
					'file' => $copyediting_array["author_file"],
				);
				$copyediting_array['author'] = $author_copyedit_array;
				$final_copyedit_array = array(
					'notified' => $final_copyedit->getdateNotified(),
					'underway' => $final_copyedit->getdateUnderway(),
					'complete' => $final_copyedit->getdateCompleted(),
					'file' => $copyediting_array["final_file"],
				);
				$copyediting_array['final'] = $final_copyedit_array;

			}
			if ($copyediting_array) {
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
			$layout_array['layout_file'] = $this->encode_file_meta($journal, $submission, $layout_file);

			if ($layout_editor) {
				$layout_array['email'] = $layout_editor->getEmail();
			}

			// Fetch galleys
			$galleys_array = array();
			foreach ($galleys as $galley) {
				$galley_array = array(
					'label' => $galley->getGalleyLabel(),
					'file' => $this->encode_file_meta($journal, $submission, $galley),
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
					'id' => (int)$issue->getIssueId(),
					'title' => $issue->getLocalizedTitle(),
					'volume' => $issue->getVolume(),
					'number' => $issue->getNumber(),
					'year' => $issue->getYear(),
					'date_published' => $publishedArticle->getDatePublished(),
				);
				$submission_array['publication'] = $issue_array;
			}

			// Create file array
			array_push($submissions_array, $submission_array);
		}

		$out = array_values($submissions_array);
		$this->json_response($submissions_array);
	}

	function users($args, &$request) {

		$user = $this->journal_manager_required($request);
		$journal =& $request->getJournal();
		$users_array = array();

		$user_dao = DAORegistry::getDAO('UserDAO');
		$roles_dao = DAORegistry::getDAO('RoleDAO');
		$users = $this->dao->getAllUsers($journalId=$journal->getId());

		foreach ($users as $user) {
			$user_array = array(
				'id' => (int)$user['user_id'],
				'salutation' => $user['salutation'],
				'first_name' => $user['first_name'],
				'middle_name' => $user['middle_name'],
				'last_name' => $user['last_name'],
				'email' => $user['email'],
			);

			$roles = $roles_dao->getRolesByUserId(
				$user['user_id'],
				$journal->getId()
			);
			$role_list = array();

			foreach ($roles as $role) {
				array_push($role_list, $role->getRoleName());
			}

			$user_array['roles'] = $role_list;

			array_push($users_array, $user_array);
		}

		$this->json_response($users_array);
	}


	function issues($args, &$request) {
		$user = $this->journal_manager_required($request);
		$journal =& $request->getJournal();
		$issues_array = array();

		$issues_dao = DAORegistry::getDAO('IssueDAO');
		$issues = $this->dao->getPublishedIssues($journal->getId());

		foreach ($issues as $issue_row) {
			$issue = $issues_dao->getIssueById($issue_row['issue_id']);

			$issue_array = array(
				'id' => (int)$issue->getIssueId(),
				'title' => $issue->getIssueTitle(),
				'volume' => $issue->getVolume(),
				'number' => $issue->getNumber(),
				'year' => $issue->getYear(),
				'date_published' => $issue->getDatePublished(),
				'description' => $issue->getIssueDescription(),
				'cover' => $request->getBaseUrl() . '/public/journals/'. $journal->getId() . '/' . $issue->getIssueFileName(),
			);

			if ($issues_dao->customIssueOrderingExists($journal->getId())) {
				$custom_order = $issues_dao->getCustomIssueOrder($journal->getId(), $issue->getIssueId());
				$issue_array['sequence'] = (int)$custom_order;
			}

			$pub_articles_dao = DAORegistry::getDAO('PublishedArticleDAO');
			$published_articles = $pub_articles_dao->getPublishedArticlesInSections($issue->getIssueId());
			$sections_array = array();

			foreach ($published_articles as $section) {
				$section_array = array();
				$articles_array = array();
				foreach ($section['articles'] as $article) {
					$article_array = array(
						'id' => (int)$article->_data['id'],
						'pages' => $article->_data['pages'],
					);
					array_push($articles_array, $article_array);
				}
				$section_array['title'] = $section['title'];
				$section_array['articles'] = $articles_array;
				array_push($sections_array, $section_array);
			}
			$issue_array['sections'] = $sections_array;

			array_push($issues_array, $issue_array);
		}
		header('Content-Type: application/json');
		echo json_encode($this->utf8ize($issues_array));
	}

}

?>
