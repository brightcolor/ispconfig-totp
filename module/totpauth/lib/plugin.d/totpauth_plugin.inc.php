<?php
/*
 * totpauth_plugin — hooks the authenticator app into ISPConfig's login.
 *
 * ISPConfig loads every *.inc.php in web/<module>/lib/plugin.d/ and calls
 * onLoad(), which registers the events below in the plugin cache of the
 * session. login/index.php raises "login" once the password is right and
 * just before it sends the browser into the panel.
 */

require_once __DIR__ . '/../common.inc.php';

class totpauth_plugin
{
	public function onLoad()
	{
		global $app;
		$app->plugin->registerEvent('login', 'totpauth_plugin', 'on_login', 'totpauth');
		$app->plugin->registerEvent('tools:user_settings:on_after_update', 'totpauth_plugin', 'on_settings_saved', 'totpauth');
		$app->plugin->registerEvent('admin:users:on_after_update', 'totpauth_plugin', 'on_settings_saved', 'totpauth');
	}

	/*
	 * Parks the fresh session the way ISPConfig does for its email code and
	 * sends the browser to the code page. The panel opens only after the
	 * code page moves the session back.
	 */
	public function on_login($event_name, $username)
	{
		global $app;

		// "Log in as" from the admin or reseller area: the admin already
		// proved who they are, the customer's app is not theirs to use.
		if (isset($_SESSION['s_old'])) {
			return;
		}
		$user = $_SESSION['s']['user'] ?? null;
		if (!totpauth_eligible($user)) {
			return;
		}
		$uid = (int) $user['userid'];
		if (!totpauth_app_store()->enabled($uid)) {
			return;
		}

		$_SESSION['s_pending'] = $_SESSION['s'];
		unset($_SESSION['s']);
		$_SESSION['totpauth'] = array(
			'userid' => $uid,
			'attempts' => 0,
			'started' => time(),
		);
		header('Location: ../totpauth/verify.php');
		die();
	}

	/*
	 * The email code and the app do not combine: after a good email code
	 * ISPConfig opens the session without raising "login", so the app would
	 * be skipped. While an app is set up, the email code stays off, whatever
	 * the form sent.
	 */
	public function on_settings_saved($event_name, $page_form)
	{
		global $app;
		$uid = (int) ($page_form->id ?? 0);
		if ($uid > 0 && totpauth_app_store()->enabled($uid)) {
			$app->db->query("UPDATE sys_user SET otp_type = 'none' WHERE userid = ?", $uid);
		}
	}
}
