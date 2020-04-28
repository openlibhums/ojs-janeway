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
}

