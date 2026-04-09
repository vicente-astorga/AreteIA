<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/classes/data_provider.php');

$courseid = 4; // Based on user's URL
$name = "Test Export " . time();
$desc = "Test description";

try {
    echo "Starting export test...\n";
    $info = local_areteia\data_provider::create_assign_activity($courseid, $name, $desc);
    echo "Success! CMID: " . $info->coursemodule . "\n";
} catch (Exception $e) {
    echo "Caught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
