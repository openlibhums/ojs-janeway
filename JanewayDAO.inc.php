<?php

/**
 *
 * Plugin for exporting data for ingestion by Janeway.
 * Written by Andy Byers, Birkbeck COllege
 *
 */
import('classes.user.User');
import('classes.user.UserDAO');

class JanewayDAO extends UserDAO {
	function &getAllUsers($journalId) {
		$sql = 'SELECT DISTINCT u.* FROM users u LEFT JOIN roles r ON u.user_id=r.user_id WHERE (r.journal_id='.$journalId.') ';
		$result =& $this->retrieveRange($sql);
		$returner = new DAOResultFactory($result, $this, '_returnUserFromRowWithData');
		return $result;
	}

	function &getPublishedIssues($journalId, $rangeInfo = null) {
		$result =& $this->retrieveRange(
			'SELECT i.* FROM issues i LEFT JOIN custom_issue_orders o ON (o.issue_id = i.issue_id) WHERE i.journal_id = ? AND i.published = 1 ORDER BY o.seq ASC, i.current DESC, i.date_published DESC',
			(int) $journalId, $rangeInfo
		);
		return $result;
	}
}

