<?php

/**
 *
 * Plugin for exporting data for ingestion by Janeway.
 * Written by Andy Byers, Birkbeck COllege
 *
 */
import('classes.user.User');
import('classes.user.UserDAO');
import('classes.article.Article');

class JanewayDAO extends UserDAO {
	function &getAllUsers($journalId) {
		$sql = 'SELECT DISTINCT u.* FROM users u LEFT JOIN roles r ON u.user_id=r.user_id WHERE (r.journal_id='.$journalId.') ';
		$result =& $this->retrieveRange($sql);
		return $result;
	}

	function &getPublishedIssues($journalId, $rangeInfo = null) {
		$result =& $this->retrieveRange(
			'SELECT i.* FROM issues i LEFT JOIN custom_issue_orders o ON (o.issue_id = i.issue_id) WHERE i.journal_id = ? AND i.published = 1 ORDER BY o.seq ASC, i.current DESC, i.date_published DESC',
			(int) $journalId, $rangeInfo
		);
		return $result;
	}

	function &getViewMetrics($context_id) {
		$sql = 'SELECT SUM(metric) as views, submission_id FROM metrics WHERE assoc_type= '. ASSOC_TYPE_ARTICLE .' AND context_id='. $context_id . ' GROUP BY submission_id';
		$result =& $this->retrieveRange($sql);
		return $result;
	}

	function &getDownloadMetrics($context_id) {
		$sql = 'SELECT SUM(metric) as downloads, submission_id FROM metrics WHERE assoc_type= '. ASSOC_TYPE_GALLEY .' AND context_id='. $context_id . ' AND file_type IS NOT NULL GROUP BY submission_id';
		$result =& $this->retrieveRange($sql);
		return $result;
	}
}

