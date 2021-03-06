<?php

ini_set('display_errorors', 0);

$config_file = 'config.json';
$log_file = 'webhook.log';

/**
 * Minimal Logging Class
 */
class Logger
{
	private $logfile;
	public function __construct($logfile_name) {
		$this->logfile = new SplFileObject($logfile_name, "a");
	}

	public function write($msg) {
		$this->logfile->fwrite($msg . "\n");
	}
	public function write_with_date($msg) {
		$this->write((new DateTime)->format('[Y-m-d H:i:s]')  . $msg);
	}

	public function write_log($msg) {
		$this->logfile->fwrite("[+]");
		$this->write_with_date($msg);
	}
	public function write_error($msg) {
		$this->logfile->fwrite("[-]");
		$this->write_with_date($msg);
	}
}

// -- Utility functions -- //
function base_branch_name($s)
{
	return substr($s, strlen('/refs/heads/') - 1);
}

function startswith($heystack, $needle)
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
	$name = $body['repository']['full_name'];

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
	$repo = $body['repository']['full_name'];

	return $config[$repo][$branch];
}

function create_pull_command($config, $payload)
{
	$branch = base_branch_name($body['ref']);
	if (!isset($config['ssh_key'])) {
		return 'git pull ' . $payload['repository']['clone_url'] . " $branch:$branch";
	}
	return "eval `ssh-agent`;" .
		"ssh-add {$config['ssh_key']}; " .
		"git pull git@github.com:{$payload['repository']['full_name']}.git {$branch}:{$branch}; " .
		"ssh-add -d {$config['ssh_key']};";
}

// -- main -- //


$log = new Logger($log_file);

if (! file_exists($config_file)) {
	$log->write_log("There is no exist $config_file.");
	exit("Internal Error.");
}

$payload = null;
$config = null;

try {
	$payload = json_decode(file_get_contents("php://input"), true);
	$config = json_decode(file_get_contents($config_file), true);
}
catch (Exception $e) {
	$log->write_error("Failed to Load JSON. " . $e->getMessage());
	exit("Internal Error.");
}

if (is_null($config)) {
	$log->write_error("Incomplete config file: $config_file.");
	exit("Internal Error.");
}

if (! check_validity($config))
{
	$log->write_error("Invalid Request:");
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
$branch_name = base_branch_name($payload['ref']);

// create command to register skip-worktree files //
$skip_files = "";
foreach($config['skip_files'] as $f) {
	$skip_files .= "git update-index --skip-worktree " . $f . ";";
}
$noskip_files = "";
foreach($config['skip_files'] as $f) {
	$noskip_files .= "git update-index --no-skip-worktree " . $f . ";";
}

// create git pull command //
$cmd = 
	"cd {$config['path']};" .
	"{$skip_files}" . // apply `git update-index --skip-worktree`
	create_pull_command($config, $payload).
	"{$noskip_files}";

// exec shell after escaping //
exec($cmd, $out, $return_code);
if ($return_code != 0) {
	$log->write_error("shell command execution error\n");
	exit("Internal Error");
}

$log->write_log("Accepted Webhook, {$payload['repository']['full_name']}:{$branch_name} {$bayload['after']}");

