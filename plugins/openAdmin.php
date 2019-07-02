<?php
/*
 * Use this plugin to allow anonymous visitors to "experience" the administrative
 * pages of a netPhotoGraphics installation.
 *
 * Any actions which might changes to the state of the installation are suppressed.
 * Some sensitive content will be hidden, for instance the <i>security log</i> and the site
 * <i>master user</i>.
 * But in general, no attempt is made to filter what the user sees, so be careful what
 * plugins you enable.
 *
 * @author Stephen Billard (sbillard)
 *
 * @package plugins/openAdmin
 * @pluginCategory netPhotoGraphics
 *
 * @Copyright 2018 by Stephen L Billard for use in {@link https://%GITHUB% netPhotoGraphics} and derivatives
 */

// force UTF-8 Ø

$plugin_is_filter = 1000 | FEATURE_PLUGIN;
$plugin_description = gettext("Allow visitors to view the Administrative pages.");

$option_interface = 'openAdmin';

if (!npg_loggedin()) {

	npgFilters::register('admin_head', 'openAdmin::head', 9999);
	npgFilters::register('tinymce_config', 'openAdmin::tinyMCE');
	if (!isset($_GET['fromlogout']) && (!isset($_GET['userlog']) || $_GET['userlog'] != 0)) {
		npgFilters::register('admin_allow_access', 'openAdmin::access', 9999);
		npgFilters::register('theme_body_close', 'openAdmin::close', 9999);
		$master = $_authority->getMasterUser();
		$_current_admin_obj = new openAdmin('Visitor', 1, $master->getID());
		$_current_admin_obj->setRights($master->getRights());
		$_COOKIE['user_auth'] = $_loggedin = $_current_admin_obj->getRights();

		if (OFFSET_PATH) {
			$_get_original = $_GET;
			npgFilters::register('database_query', 'openAdmin::query', 9999);
			npgFilters::register('admin_note', 'openAdmin::notice', 9999);
			if (isset($_GET['action'])) {
				$allowedActions = array('save', 'sorttags', 'sortorder', 'saveoptions', 'external');
				if (!in_array($_GET['action'], $allowedActions)) {
					$_GET['action'] = 'NULL'; // block the action
				}
			}
		}
	}
}

class openAdmin extends _Administrator {

	function __construct($user = NULL, $valid = NULL, $id = NULL) {

		if (OFFSET_PATH == 2) {
			setOptionDefault('openAdmin_logging', 0);
		}

		parent::__construct('', NULL, false);
		$this->setUser($user);
		$this->setName('Site ' . $user);
		$this->setEmail($user . '@netPhotoGraphics.com');
		$this->exists = true;
		$this->transient = true;
		$this->valid = $valid;
		$this->set('valid', $valid);
		$this->set('id', $id);
	}

	function setPolicyACK($v) {
		parent::setPolicyACK($v);
		if ($v) {
			setNPGCookie('policyACK', getOption('GDPR_cookie')); //	since the object is not persistent
		}
	}

	function getOptionsSupported() {
		$options = array(
				gettext('Log access') => array('key' => 'openAdmin_loging', 'type' => OPTION_TYPE_CHECKBOX,
						'desc' => gettext('Log administrative pages visited.'))
		);
		return $options;
	}

	static function setAdmin() {
		global $_current_admin_obj, $_authority;
		$masterid = $_current_admin_obj->getID();
		$_authority->admin_all[$masterid] = $_current_admin_obj->getData();
		$_authority->admin_users[$masterid] = $_current_admin_obj->getData();
	}

	/**
	 * removes upload capability from tinyMCE
	 *
	 * @global type $MCEspecial
	 */
	static function tinyMCE() {
		global $MCEspecial;
		unset($MCEspecial['images_upload_url']);
		unset($MCEspecial['file_picker_callback']);
	}

	static function access($allow, $url) {
		global $_admin_menu, $_current_admin_obj;
		self::setAdmin();
		if (!isset($_POST['policy_acknowledge']) || $_POST['policy_acknowledge'] == md5(getUserID() . getOption('GDPR_cookie'))) {
			if (class_exists('GDPR_required') && getNPGCookie('policyACK') != getOption('GDPR_cookie')) {
				GDPR_required::page(NULL, NULL);
			}
		}
		setNPGCookie('policyACK', getOption('GDPR_cookie'));

		//	limit security logging for "visitor"
		npgFilters::remove('admin_allow_access', 'security_logger::adminGate');
		npgFilters::remove('authorization_cookie', 'security_logger::adminCookie', 0);
		npgFilters::remove('admin_managed_albums_access', 'security_logger::adminAlbumGate');
		npgFilters::remove('save_user_complete', 'security_logger::UserSave');
		npgFilters::remove('admin_XSRF_access', 'security_logger::admin_XSRF_access');
		npgFilters::remove('admin_log_actions', 'security_logger::log_action');
		npgFilters::remove('log_setup', 'security_logger::log_setup');
		npgFilters::remove('security_misc', 'security_logger::security_misc');

		if (isset($_admin_menu['logs']['subtabs'])) {
			//	hide sensitive logs
			foreach ($_admin_menu['logs']['subtabs'] as $subtab) {
				$masterlog = $subtab = substr($subtab, strpos($subtab, 'tab=') + 4);
				$j = strpos($subtab, '-');
				if ($j !== FALSE) {
					$masterlog = substr($subtab, 0, $j);
				}
				switch ($masterlog) {
					case 'security':
					case 'openAdmin':
					case 'debug':
						unset($_admin_menu['logs']['subtabs'][$subtab]);
						unset($_admin_menu['logs']['alert'][$subtab]);
						break;
				}
			}
		}

		if (empty($_admin_menu['logs']['subtabs'])) {
			$_admin_menu['logs']['link'] = getAdminLink('admin-tabs/logs.php') . '?page=logs';
			$_admin_menu['logs']['default'] = NULL;
		} else {
			$_admin_menu['logs']['default'] = $default = current(array_keys($_admin_menu['logs']['subtabs']));
			$_admin_menu['logs']['link'] = $_admin_menu['logs']['subtabs'][$default];
		}
		//	protect against un-monitored uploading
		if (isset($_admin_menu['upload'])) {
			foreach ($_admin_menu['upload']['subtabs'] as $key => $link) {
				if (strpos($link, '/elFinder/') !== false) {
					unset($_admin_menu['upload']['subtabs'][$key]);
					break;
				}
			}
			if (empty($_admin_menu['upload']['subtabs'])) {
				unset($_admin_menu['upload']);
			} else {
				$_admin_menu['upload']['default'] = $default = current(array_keys($_admin_menu['upload']['subtabs']));
				$_admin_menu['upload']['link'] = $_admin_menu['upload']['subtabs'][$default];
			}
		}
		if (isset($_admin_menu['development'])) {
			$allowedDebugTabs = array('tokens', 'locale', 'http', 'checkdeprecated', 'rewrite', 'macros', 'filters', 'locale', 'deprecated');
			foreach ($_admin_menu['development']['subtabs'] as $key => $link) {
				preg_match('~tab=(.*)~', $link, $matches);
				if (!in_array($matches[1], $allowedDebugTabs)) {
					unset($_admin_menu['development']['subtabs'][$key]);
				}
			}
			if (empty($_admin_menu['development']['subtabs'])) {
				unset($_admin_menu['development']);
			} else {
				$_admin_menu['development']['default'] = $default = current(array_keys($_admin_menu['development']['subtabs']));
				$_admin_menu['development']['link'] = $_admin_menu['development']['subtabs'][$default];
			}
		}
		return $allow;
	}

	static function head() {
		global $_get_original;

		if (getOption('openAdmin_logging')) {
			$uri = explode('?', getRequestURI());
			$uri = trim(str_replace(WEBPATH, '', $uri[0]), '/');
			$uri = trim(str_replace(CORE_FOLDER, '', $uri), '/');
			$uri = trim(str_replace(CORE_PATH, '', $uri), '/');
			$uri = trim(str_replace(PLUGIN_FOLDER, '', $uri), '/');
			$uri = trim(str_replace(PLUGIN_PATH, '', $uri), '/');
			self::Logger($uri, @$_get_original['page'], @$_get_original['tab'], @$_get_original['action']);
		}
		?>
		<script type="text/javascript">
			// <!-- <![CDATA[
			window.addEventListener('load', function () {
				$(".overview_utility_buttons").attr("action", "#");
				$("#admin_logout").attr("href", "<?php echo getAdminLink('admin.php'); ?>?userlog=0");
				$("#admin_logout").attr("title", "<?php echo gettext('Show admin login form'); ?>");
				$('#login').before('<p class="notebox"><?php echo gettext('Login with valid user credentials to bypass the <em>openAdmin</em> plugin.'); ?></p>');
				$('#auth').remove();	//	disable any auth passing, currently only for uploader stuff
			}, false);
			// ]]> -->
		</script>
		<?php
	}

	static function close() {

		self::setAdmin();
		?>
		<script type="text/javascript">
			// <!-- <![CDATA[
			window.addEventListener('load', function () {
				$("#toolbox_logout").attr("href", "<?php echo getAdminLink('admin.php'); ?>?userlog=0");
				$("#toolbox_logout").attr("title", "<?php echo gettext('Show admin login form'); ?>");
			}, false);
			// ]]> -->
		</script>
		<?php
	}

	static function query($result, $sql) {
		$action = substr($sql, 0, strpos($sql, ' '));
		switch (strtolower($action)) {
			case 'select':
			case 'show':
			case 'use':
			case 'describe':
			case 'set':
				//	"read" type commands let it pass
				return $result;
				break;
		}
		return true; //	pretend the query was successsful
	}

	static function notice($html) {
		?>

		<div class="notebox">
			<br />
			<strong>
				<?php echo gettext('The administrative pages are available for demonstration purposes only. Actions that would change the state of the installation will be suppressed.');
				?>
			</strong>
			<br />
			<br />
		</div>

		<?php
	}

	static function Logger($link, $page, $tab, $action) {
		global $_authority, $_mutex;
		$ip = sanitize($_SERVER['REMOTE_ADDR']);
		if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$proxy_list = explode(",", $_SERVER['HTTP_X_FORWARDED_FOR']);
			$forwardedIP = trim(sanitize(end($proxy_list)));
			if ($forwardedIP) {
				$ip .= ' {' . $forwardedIP . '}';
			}
		}

		$file = SERVERPATH . '/' . DATA_FOLDER . '/openAdmin.log';
		$max = getOption('security_log_size'); // we are lazy, we will use this
		$_mutex->lock();
		if ($max && @filesize($file) > $max) {
			switchLog('openAdmin');
		}
		$preexists = file_exists($file) && filesize($file) > 0;
		$f = fopen($file, 'a');
		if ($f) {
			if (!$preexists) { // add a header
				@chmod($file, DATA_MOD);
				fwrite($f, gettext('date' . "\t" . 'requestor’s IP' . "\t" . 'link' . "\t" . 'page' . "\t" . 'tab' . "\t" . 'action' . "\n"));
			}
			$message = date('Y-m-d H:i:s') . "\t";
			$message .= $ip . "\t";
			$message .= $link . "\t";
			$message .= $page . "\t";
			$message .= $tab . "\t";
			$message .= $action;

			fwrite($f, $message . "\n");
			fclose($f);
			clearstatcache();
		}
		$_mutex->unlock();
	}

}
