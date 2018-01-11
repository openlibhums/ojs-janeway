<?php

/**
 *
 * Plugin for exporting data for ingestion by Janeway.
 * Written by Andy Byers, Birkbeck COllege
 *
 */


class JanewayDAO extends DAO {

	function get_excluded_emails() {
		$sql = <<< EOF
			SELECT * FROM kudos_emails;
EOF;
		return $this->retrieve($sql);
	}

}

