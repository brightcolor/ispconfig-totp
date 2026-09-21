<?php
/*
 * Stand-in for interface/lib/config.inc.php. The module's pages require it
 * as '../../lib/config.inc.php' relative to their working directory, which
 * the router sets to harness/web/totpauth.
 */
define('HARNESS', dirname(__DIR__));
define('ISPC_ROOT_PATH', HARNESS . '/interface'); // key file: HARNESS/totpauth/secret.key
$conf = array('language' => 'de', 'theme' => 'default');
