<?php

$config_file = 'config.json';

// -- Utility functions -- //
function base_branch_name($s)
{
	return substr($s, strlen('/refs/heads/') - 1);
}

function write_log($msg)
{
	$log_file = './webhook.log';
	return error_log($msg, 3, $log_file);
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

$payload = json_decode(file_get_contents("php://input"), true);
$config = json_decode(file_get_contents($config_file), true);

if (! check_validity($config))
{
	write_log("Invalid Request:");
	write_log("\tHeader:");
	foreach (getallheaders() as $k => $v) {
		write_log("\t\t$k: $v");
	}

	write_log("\tBody:");
	foreach (explode("\n", var_export($payload, true)) as $l) {
		write_log("\t\t$l");
	}
	write_log("");

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
	write_log("shell command execution error\n");
	exit("Internal Error");
}

