<?php

/**
 *
 * Plugin for exporting data for ingestion by Janeway.
 * Written by Andy Byers, Birkbeck College
 *
 */

import('lib.pkp.classes.plugins.GenericPlugin');
require_once('JanewayDAO.inc.php');

class JanewayPlugin extends GenericPlugin {
	function register($category, $path, $mainContextId=NULL) {
		if(!parent::register($category, $path, $mainContextId)) {
			return false;
		}
		if($this->getEnabled()) {
			HookRegistry::register("LoadHandler", array(&$this, "handleRequest"));
			HookRegistry::register('Templates::Manager::Index::ManagementPages', array(&$this, 'janeway_link'));
			$tm =& TemplateManager::getManager();
			$tm->assign("janewayEnabled", true);
			define('JANEWAY_PLUGIN_NAME', $this->getName());
		}
		return true;
	}

	function handleRequest($hookName, $args) {
		$page =& $args[0];
		$op =& $args[1];
		$sourceFile =& $args[2];

		if ($page == 'janeway') {
			$this->import('JanewayHandler');
			Registry::set('plugin', $this);
			define('HANDLER_CLASS', 'JanewayHandler');
			return true;
		}
		return false;
	}

	function getDisplayName() {
		return "Janeway Export";
	}
	
	function getDescription() {
		return "Generates a JSON response for importing in progress content to Janeway.";
	}
	
	function getTemplatePath($inCore=false) {
		return parent::getTemplatePath($inCore) . 'templates/';
	}

	function janeway_link($hookName, $args) {
		$output =& $args[2];

		$templateMgr =& TemplateManager::getManager();
		$currentJournal = $templateMgr->get_template_vars('currentJournal');
		$output .=  <<< EOF
		<li><a href="{$currentJournal->getUrl()}/janeway/">Janeway Export</a></li>
EOF;

		
		return false;
	}


}
