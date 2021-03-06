<?php
/*
Plugin Name: WP Sync
Plugin URI: https://github.com/deeeki/wp-sync
Description: Synchronize WordPress resources (DB & files). ***UNIX based system only.***
Version: 0.2.2
Author: deeeki
Author URI: http://deeeki.com/
Revision Date: Dec. 3, 2010
Tested up to: WordPress 3.0.1
*/

//Windows disabled
if (substr(PHP_OS, 0, 3) == 'WIN') {
	return;
}
if (is_admin()) {
	$WpSync = new WpSync();
	add_action('activate_' . plugin_basename(__FILE__), array('WpSyncAdmin', 'activate'));
	add_action('deactivate_' . plugin_basename(__FILE__), array('WpSyncAdmin', 'deactivate'));
}

/**
 * sync class
 */
class WpSync {
	const QUOTE = "'";

	public $based = array();
	public $options = array();
	public $action = 'index';
	public $cmd_error = '';
	public $exclude_from = '';

	/**
	 * constructor
	 */
	public function __construct() {
		$this->based['src_dir'] = ABSPATH;
		$this->based['src_url'] = preg_replace('!https?://!', '', get_bloginfo('url'));
		$this->based['src_db'] = DB_NAME;

		$this->options = get_option('wpsync_options');
		$this->exclude_from = dirname(__FILE__) . '/excludes';

		add_action('admin_menu', array(&$this, 'add_admin_menu'));
		add_filter('plugin_action_links', array(&$this, 'add_plugin_action_links'), 10, 2);
	}

	/**
	 * insert into Tool menu
	 */
	public function add_admin_menu() {
		$ret = add_management_page('WP Sync', 'WP Sync', 'administrator', plugin_basename(__FILE__), array($this, 'action'));
	}

	/**
	 * insert setting link into plugin page
	 */
	public function add_plugin_action_links($links, $file) {
		if ($file == plugin_basename(__FILE__)) {
			$setting_link = '<a href="tools.php?page=' . plugin_basename(__FILE__) . '">' . __('Settings') . '</a>';
			array_unshift($links, $setting_link);
		}
		return $links;
	}

	/**
	 * action dispatcher
	 */
	public function action() {
		$action = (isset($_REQUEST['action'])) ? $_REQUEST['action'] : 'index';
		if (method_exists($this, $action)) {
			$this->$action();
		}
		else {
			echo 'error : action not found';
		}
	}

	/**
	 * render header
	 */
	public function head() {
		$setting_class = $sync_class = '';
		if ($_REQUEST['action'] == 'view' || $_REQUEST['action'] == 'sync') {
			$sync_class = 'class="current"';
		}
		else {
			$setting_class = 'class="current"';
		}
?>
	<h2>WP Sync</h2>

	<?php if (isset($this->message)): ?>
	<div id="message" class="updated fade"><p><font color="green"><?php echo $this->message ?></font></p></div>
	<?php endif; ?>
	<?php if (isset($this->error)): ?>
	<div id="error" class="updated fade"><p><font color="red"><?php echo $this->error ?></font></p></div>
	<?php endif; ?>

	<ul class="subsubsub">
	<li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__); ?>" <?php echo $setting_class ?>>Setting</a> | </li>
	<li><a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__); ?>&action=view" <?php echo $sync_class ?>>Sync</a></li>
	</ul>
	<div class="clear"></div>
<?php
	}

	/**
	 * render index(settings) page
	 */
	public function index() {
?>
<div class="wrap">
	<?php $this->head() ?>

	<h3>Setting</h3>
	<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__); ?>">
	<table>
	<?php foreach ($this->based as $key => $val): ?>
	<tr>
	<th><?php echo $key ?></th>
	<td><?php echo $val ?></td>
	</tr>
	<?php endforeach; ?>
	<?php foreach ($this->options as $key => $val): ?>
	<tr>
	<th><?php echo $key ?></th>
	<td>
		<?php if ($key == 'excludes'): ?>
		<textarea name="opt_<?php echo $key ?>" cols="64" rows="5"><?php echo esc_html($val) ?></textarea><br />
		<?php _e('(input exclude path with new line that used --exclude-from option)') ?>
		<?php else: ?>
		<input type="text" name="opt_<?php echo $key ?>" value="<?php echo esc_html($val) ?>" size="80" />
		<?php endif; ?>
	</td>
	</tr>
	<?php endforeach; ?>
	</table>
	<p class="submit">
		<input type="hidden" name="action" value="update" />
		<input type="submit" name="Submit" class="button" value="<?php _e('update setting'); ?>" />
	</p>
	</form>
</div>

<?php
	}

	/**
	 * process updating settings and render index page
	 */
	public function update() {
		if (isset($_POST)) {
			foreach($_POST as $key => $val) {
				if (strpos($key, 'opt_') !== 0) {
					continue;
				}
				$option_key = str_replace('opt_', '', $key);
				if ($option_key == 'excludes') {
					$this->options[$option_key] = $val;
					if (!file_put_contents($this->exclude_from, $val)) {
						$this->error = 'Cannot write ' . $this->exclude_from;
					}
				}
				else {
					$this->options[$option_key] = escapeshellcmd(str_replace("\0", '', $val));
				}
			}
			update_option('wpsync_options', $this->options);
			$this->message = 'Update Setting Successfully';
		}
		$this->index();
	}


	/**
	 * render preview page
	 */
	public function view() {
		$commands = $this->_generate_commands();
?>
<div class="wrap">
	<?php $this->head() ?>

	<h3>Sync Preview</h3>
	<div style="background-color: #ffffff; border: 1px solid #999999;">
	<pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html(implode("\n\n", $commands)); ?></pre>
	</div>

	<div class="submit">
	<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__); ?>">
		<input type="hidden" name="action" value="sync" />
		<input type="submit" name="Submit" class="button-primary" value="<?php _e('do sync'); ?>" />
	</form>
	</div>
</div>
<?php
	}

	/**
	 * process synchronizing and render preview page
	 */
	public function sync() {
		$commands = $this->_generate_commands();
		$ret = $this->_execute_sync($commands);
		if ($ret) {
			$this->message = 'Sync Successfully On ' . current_time('mysql');
		}
		else {
			$this->error = 'Sync Failed' . "<br />\n" . $this->cmd_error;
		}
		$this->view();
	}

	/**
	 * generate sync commands
	 */
	protected function _generate_commands() {
		$ts = date('Ymd_') . time();

		$dump_sql = WP_CONTENT_DIR . '/' . DB_NAME . '_' . $ts . '.sql';

		$commands = array();
		//backup dest database
		if ($this->options['backup_dir']) {
			$backup_sql = $this->options['backup_dir'] . $this->options['dest_db'] . '_' . $ts . '.sql';
			$commands[] = 'mysqldump ' . $this->options['dest_db'] . ' --host=' . DB_HOST . ' -u ' . DB_USER . ' --password=' . DB_PASSWORD . ' > ' . $backup_sql;
		}
		//dump src database
		$commands[] = 'mysqldump ' . DB_NAME . ' --host=' . DB_HOST . ' -u ' . DB_USER . ' --password=' . DB_PASSWORD . ' > ' . $dump_sql;
		//replace host
		$src_url = preg_replace('!https?://!', '', get_bloginfo('url'));
		$commands[] = 'sed -i ' . self::QUOTE . 's!' . $src_url . '!' . $this->options['dest_url'] . '!g' . self::QUOTE . ' ' . $dump_sql;
		//replace filepath
		$commands[] = 'sed -i ' . self::QUOTE . 's!' . ABSPATH . '!' . $this->options['dest_dir'] . '!g' . self::QUOTE . ' ' . $dump_sql;
		//restore dest database
		$commands[] = 'mysql ' . $this->options['dest_db'] . ' --host=' . DB_HOST . ' -u ' . DB_USER . ' --password=' . DB_PASSWORD . ' < ' . $dump_sql;
		//remove dump sql file
		$commands[] = 'rm -f ' . $dump_sql;
		//sync files
		$exclude = " --exclude='wp-config.php' ";
		$exclude_from = '';
		if ($this->options['excludes'] && is_readable($this->exclude_from)) {
			$exclude_from = " --exclude-from='" . $this->exclude_from . "' ";
		}
		$backup_dir = ($this->options['backup_dir']) ? " --backup-dir='" . $this->options['backup_dir'] . $ts . "' " : '';
		$commands[] = 'rsync -brz --delete ' . $exclude . $exclude_from . $backup_dir . ABSPATH . ' ' . $this->options['dest_dir'] . ' >> ' . dirname(__FILE__) . '/rsync.log';

		return $commands;
	}

	/**
	 * execute sync commands
	 */
	protected function _execute_sync($commands = array()) {
		if (!is_dir($this->options['dest_dir']) || !is_writable($this->options['dest_dir'])) {
			return false;
		}
		if ($this->options['src_dir'] == $this->options['dest_dir']) {
			return false;
		}

		foreach ($commands as $command) {
			exec($command, $output, $return_var);
			if ($return_var) {
				$error = array();
				$error[] = '[command]';
				$error[] = $command;
				$error[] = '[output]';
				$error[] = implode("\n", $output);
				$error[] = '[return_var]';
				$error[] = print_r($return_var, true);
				$this->cmd_error = implode("<br />\n", $error);
				return false;
			}
		}
		return true;
	}
}

/**
 * static functions when this plugin activated/deactivated
 */
class WpSyncAdmin {
	public static function activate() {
		$options = array();
		$options['dest_dir'] = ABSPATH;
		$options['dest_url'] = preg_replace('!https?://!', '', get_bloginfo('url'));
		$options['dest_db'] = DB_NAME;
		$options['backup_dir'] = '';
		$options['excludes'] = implode("\n", array(
			'- .svn',
			'- .htaccess',
			'- wp-content/backup-db', //for WP-DBManager
		));

		add_option('wpsync_options', $options);
	}

	public static function deactivate() {
		delete_option('wpsync_options');
	}
}
