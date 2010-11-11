<?php
/*
Plugin Name: WP Sync
Plugin URI: https://github.com/deeeki/wp-sync
Description: Synchronize WordPress resources (DB &amp; files). ***UNIX based system only.***
Version: 0.1.1
Author: deeeki
Author URI: http://deeeki.com/
Revision Date: Nov. 11, 2010
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

class WpSync {
	const QUOTE = "'";

	public $based = array();
	public $options = array();
	public $cmd_error = '';

	public function __construct() {
		$this->based['src_dir'] = ABSPATH;
		$this->based['src_url'] = preg_replace('!https?://!', '', get_bloginfo('url'));
		$this->based['src_db'] = DB_NAME;

		$this->options = get_option('wpsync_options');

		add_action('admin_menu', array($this, 'add_admin_menu'));
	}

	public function add_admin_menu() {
		$ret = add_management_page('WP Sync', 'WP Sync', 'administrator', plugin_basename(__FILE__), array($this, 'action'));
	}

	public function action() {
		$action = (isset($_POST['action'])) ? $_POST['action'] : 'display';
		if (method_exists($this, $action)) {
			$this->$action();
		}
		else {
			echo 'error : action not found';
		}
	}

	public function display() {
?>
<div class="wrap">
	<h2>WP Sync</h2>
	<?php if (isset($this->message)): ?>
	<div id="message" class="updated fade"><p><font color="green"><?php echo $this->message ?></font></p></div>
	<?php endif; ?>
	<?php if (isset($this->error)): ?>
	<div id="error" class="updated fade"><p><font color="red"><?php echo $this->error ?></font></p></div>
	<?php endif; ?>
	<h3>Index</h3>
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
		<input type="text" name="opt_<?php echo $key ?>" value="<?php echo $val ?>" size="80" />
	</td>
	</tr>
	<?php endforeach; ?>
	</table>
	<p class="submit">
		<input type="hidden" name="action" value="update" />
		<input type="submit" name="Submit" class="button" value="<?php _e('update settings'); ?>" />
	</p>
	</form>
	<div class="submit">
	<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__); ?>">
		<input type="hidden" name="action" value="preview" />
		<input type="submit" name="Submit" class="button" value="<?php _e('preview commands'); ?>" />
	</form>
	<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=<?php echo plugin_basename(__FILE__); ?>">
		<input type="hidden" name="action" value="sync" />
		<input type="submit" name="Submit" class="button" value="<?php _e('do sync'); ?>" />
	</form>
	</div>
</div>

<?php
	}

	public function update() {
		if (isset($_POST)) {
			foreach($_POST as $key => $val) {
				if (strpos($key, 'opt_') === 0) {
					$option_key = str_replace('opt_', '', $key);
					$this->options[$option_key] = $val;
				}
			}
			update_option('wpsync_options', $this->options);
			$this->message = 'Update Setting Successfully';
		}
		$this->display();
	}

	public function preview() {
		$commands = $this->_generate_commands();
?>
<div class="wrap">
	<h2>WP Sync</h2>
	<h3>Preview</h3>
	<?php echo implode("<br />\n<br />\n", $commands); ?>
</div>
<?php
	}

	public function sync() {
		$commands = $this->_generate_commands();
		$ret = $this->_execute_sync($commands);
		if ($ret) {
			$this->message = 'Sync Successfully On ' . current_time('mysql');
		}
		else {
			$this->error = 'Sync Failed' . "<br />\n" . $this->cmd_error;
		}
		$this->display();
	}

	protected function _generate_commands() {
		$ts = date('Ymd_') . time();

		$dump_file = WP_CONTENT_DIR . '/' . $ts . '.sql';

		$commands = array();
		//dump database
		$commands[] = 'mysqldump ' . DB_NAME . ' --host=' . DB_HOST . ' -u ' . DB_USER . ' --password=' . DB_PASSWORD . ' > ' . $dump_file;
		//replace host
		$src_url = preg_replace('!https?://!', '', get_bloginfo('url'));
		$commands[] = 'sed -i ' . self::QUOTE . 's!' . $src_url . '!' . $this->options['dest_url'] . '!g' . self::QUOTE . ' ' . $dump_file;
		//replace filepath
		$commands[] = 'sed -i ' . self::QUOTE . 's!' . ABSPATH . '!' . $this->options['dest_dir'] . '!g' . self::QUOTE . ' ' . $dump_file;
		//restore database
		$commands[] = 'mysql ' .$this->options['dest_db'] . ' --host=' . DB_HOST . ' -u ' . DB_USER . ' --password=' . DB_PASSWORD . ' < ' . $dump_file;
		//remove dump file
		$commands[] = 'rm -f ' . $dump_file;
		//sync files
		$exclude = " --exclude='wp-config.php' ";
		//$exclude .= "--exclude='wp-content/plugins/wp-sync/' ";
		$backup_dir = ($this->options['backup_dir']) ? " --backup-dir='" . $this->options['backup_dir'] . $ts . "' " : '';
		$commands[] = 'rsync -brz --delete ' . $exclude . $backup_dir . ABSPATH . ' ' . $this->options['dest_dir'] . ' >> ' . dirname(__FILE__) . '/rsync.log';
		return $commands;
	}

	protected function _execute_sync($commands = array()) {
		if (!is_dir($this->options['dest_dir']) || !is_writable($this->options['dest_dir'])) {
			return false;
		}
		if ($this->options['src_dir'] == $this->options['dest_dir']) {
			return false;
		}

		foreach ($commands as $command) {
			passthru($command, $error);
			if ($error) {
				$this->cmd_error = $command . "<br />\n" . print_r($error, true);
				return false;
			}
		}
		return true;
	}
}

class WpSyncAdmin {
	public static function activate() {
		$options = array();
		$options['dest_dir'] = ABSPATH;
		$options['dest_url'] = preg_replace('!https?://!', '', get_bloginfo('url'));
		$options['dest_db'] = DB_NAME;
		$options['backup_dir'] = '';

		add_option('wpsync_options', $options);
	}

	public static function deactivate() {
		delete_option('wpsync_options');
	}
}