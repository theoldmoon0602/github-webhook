<?php

$config_file = 'config.json';

// -- Utility functions -- //
function base_branch_name($s)
{
	return substr($s, strlen('/refs/heads/') - 1);
}

function write_log($msg) {
  $log_file = './webhook.log';
	return error_log($msg, 3, $log_file);
}

// check is it webhook from github? //
if (strpos($_SERVER["HTTP_USER_AGENT"], "GitHub") === FALSE)
{ 
	write_log("Not Webhook Access\n");
  write_log($_SERVER["HTTP_USER_AGENT"] . "\n");
	exit("ERR");
}

// load payload and config json file //
$payload = json_decode(file_get_contents("php://input"), true);
$config = json_decode(file_get_contents($config_file), true);

// check branch name //
$branch_name = base_branch_name($payload['ref']);
if (! isset($config[$branch_name])) {
	write_log("Unknown Branch Name " . $payload['ref'] . "\n");
  exit("ERR");
}

// alias //
$config = $config[$branch_name];

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
  exit("ERR");
}

