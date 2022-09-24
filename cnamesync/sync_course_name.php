<?php

//define('CLI_SCRIPT', true);

require(__DIR__.'/../../config.php');
//require_once("$CFG->libdir/clilib.php");
require(__DIR__.'/lib.php');

/** @var enrol_database_plugin $enrol  */
$enrol = enrol_get_plugin('database');

$result = 0;

$result = $result | $enrol->sync_course_name();

exit($result);





