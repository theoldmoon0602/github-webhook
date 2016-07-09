<?php

$config_file = 'config.json';
$log_file = 'webhook.log';

/**
 * Minimal Logging Class
 */
class logger
{
	private $logfile;
	public __construct($logfile_name) {
		$this->logfile = new SplFileObject($logfile_name, "a");
	}

	public write($msg) {
		$this->logfile->fwrite($msg . "\n");
	}

	public write_log($msg) {
		$this->write((new DateTime)->format('[Y-m-d H:i:s]')  . $msg);
	}
}

// -- Utility functions -- //
function base_branch_name($s)
{
	return substr($s, strlen('/refs/heads/') - 1);
}

function startswidth($heystack, $needle)
{
	return (substr($heystack, 0, strlen($needle)) === $needle);
}

function endswith($heystack, $needle)
{
	return (bool)preg_match("/{$needle}$/", $heystack);
}

function check_validity($config) {
	$header = getallheaders();

	// check is webhook request from github? //
	if (! startswith($header['User-Agent'], 'GitHub')) {
		return false;
	}

	// check is request occured with push event? //
	if (! $header['X-GitHub-Event'] === 'push') {
		return false;
	}

	$body = json_decode(file_get_contents('php://input'), true);
	$name = $body['repository']['name'];

	// check is repository name registered? //
	if (! in_array($name, array_keys($config), true)) {
		return false;
	}

	// check is branch name registered? //
	if (! in_array(base_branch_name($body['ref']), array_keys($config[$name]), true)) {
		return false;
	}

	return true;
}

function get_config_part($config, $body)
{
	$branch = base_branch_name($body['ref']);
	$repo = $body['repository']['name'];

	return $config[$repo][$branch];
}

// -- main -- //


$log = new Logging($log_file);

if (! file_exists($config_file)) {
	$log->write_log("There is no exist $config_file.");
	exit("Internal Error.");
}

$payload = json_decode(file_get_contents("php://input"), true);
$config = json_decode(file_get_contents($config_file), true);

if (! check_validity($config))
{
	$log->write_log("Invalid Request:");
	$log->write("\tHeader:");
	foreach (getallheaders() as $k => $v) {
		$log->write("\t\t$k: $v");
	}

	$log->write("\tBody:");
	foreach (explode("\n", var_export($payload, true)) as $l) {
		$log->write("\t\t$l");
	}
	$log->write("");

	exit("Invalid Request");
}

// alias //
$config = get_config_part($config, $payload);

// create command to register skip-worktree files //
$skip_files = "";
foreach($config['skip_files'] as $f) {
	$skip_files .= "git update-index --skip-worktree " . $f;
}

// create git pull command //
$cmd = 
	"cd {$config['path']};" .
	"{$skip_files};" . // apply `git update-index --skip-worktree`
	"git pull origin {$branch_name}:{$branch_name}";

// exec shell after escaping //
$return_code = shell_exec(escapeshellcmd($cmd));
if ($return_code != 0) {
	$log->write_log("shell command execution error\n");
	exit("Internal Error");
}

