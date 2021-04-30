<?php

/**
 *
 * Plugin for exporting data for ingestion by Janeway.
 * Written by Andy Byers, Birkbeck COllege
 *
 */
if (file_exists('lib/pkp/classes/user/User.inc.php')) {
	import('lib.pkp.classes.user.User');
	import('lib.pkp.classes.user.UserDAO');
} else {
	import('classes.user.User');
	import('classes.user.UserDAO');
}


class JanewayDAO extends UserDAO {
	function &getAllUsers($journalId) {
		$sql = 'SELECT DISTINCT u.* FROM users u LEFT JOIN roles r ON u.user_id=r.user_id WHERE (r.journal_id='.$journalId.') ';
		$result =& $this->retrieveRange($sql);
		$returner = new DAOResultFactory($result, $this, '_returnUserFromRowWithData');
		return $result;
	}
	function &getTypesetFlag($articleId) {
		$sql = 'SELECT a.setting_value FROM article_settings a WHERE (a.setting_name = \'typesetFlag\'and a.article_id='.$articleId.') ';
		$result =& $this->retrieve($sql);
		$returner =  isset($result->fields["setting_value"]) ? $result->fields["setting_value"] : NULL;
		return $returner;
	}

	function &getPublishedIssues($journalId, $rangeInfo = null) {
		$result =& $this->retrieveRange(
			'SELECT i.* FROM issues i LEFT JOIN custom_issue_orders o ON (o.issue_id = i.issue_id) WHERE i.journal_id = ? AND i.published = 1 ORDER BY o.seq ASC, i.current DESC, i.date_published DESC',
			(int) $journalId, $rangeInfo
		);
		return $result;
	}
}

