<?php

// SCORM Content migration // CLI script

define('CLI_SCRIPT', 1);

// Run from /admin/cli dir
require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once($CFG->dirroot.'/mod/scorm/locallib.php');

// Where are the SCORM .zip files?
$scormfolder = '/'; // Include trailing slash

// Open report file
$reportfile = fopen('scorm_migration_report.txt', 'w');

$found = array();
$notfound = array();

$fs = get_file_storage();

$file_record = array(
    'component' => 'mod_scorm',
    'filearea' => 'package',
    'filepath' => '/'
);

$scormcontent = simplexml_load_file('scorm_content.xml') or die('wtf');

// Move SCORM content into the file area
foreach ($scormcontent->children() as $scormdetails) {

    $courseid = (int) $scormdetails->field['0'];
    $cmid = (int) $scormdetails->field['1'];
    $scormid = (int) $scormdetails->field['2'];
    $coursename = (string) $scormdetails->field['3']; // unused
    $scormname = (string) $scormdetails->field['4'];
    $filename = (string) $scormdetails->field['5'];

    if (file_exists($scormfolder . $filename)) {

        $found[] = $filename;

        // Set context
        $context = context_module::instance($cmid);

        xlog("Clearing any existing SCORM content for module ID {$cmid}: '{$scormname}' of course ID {$courseid}: '{$coursename}'");
        $fs->delete_area_files($context->id, 'mod_scorm', 'package');

        // Move into Moodle filesystem
        $file_record['contextid'] = $context->id;
        $file_record['filename'] = $filename;
        $file_record['itemid'] = '0';
        $file_record['timecreated'] = time();
        $file_record['timemodified'] = time();

        xlog("Migrating SCORM content file '{$filename}' into module ID {$cmid}: '{$scormname}' of course ID {$courseid}: '{$coursename}'");
        $fs->create_file_from_pathname($file_record, $scormfolder . $filename);

        // Update {scorm} table where id = $scormid
        // Retrieve the hash from the file
        $hash = $DB->get_field('files', 'contenthash',
                array('filename' => $filename, 'contextid' => $context->id));

        $scorm = new stdClass();
        $scorm->id = $scormid;
        $scorm->reference = $filename;
        $scorm->md5hash = '';
        $scorm->sha1hash = $hash;

        $DB->update_record('scorm', $scorm);

        // 2. The 'instance' field in the {course_modules} table of the record,
        // where ('id' => $scorm->coursemodule) is set to the new record ID from step 1
        $DB->set_field('course_modules', 'instance', $scormid, array('id' => $cmid));

        // Get the whole SCORM object data
        $scorm = $DB->get_record('scorm', array('id' => $scormid));

        // Extra fields required in grade related functions.
        $scorm->course     = $courseid;
        $scorm->cmidnumber = '';
        $scorm->cmid       = $cmid;

        xlog("Configuring SCORM module '{$scormname}'");
        scorm_parse($scorm, true);
        scorm_grade_item_update($scorm);

        // Specific settings for the SCORM package
        $scormsettings = new stdClass();
        $scormsettings->id = $scormid;
        $scormsettings->introformat = 0;
        $scormsettings->maxgrade = 100;
        $scormsettings->grademethod = 1;
        $scormsettings->whatgrade = 0;
        $scormsettings->maxattempt = 0;
        $scormsettings->forcecompleted = 1;
        $scormsettings->forcenewattempt = 1;
        $scormsettings->lastattemptlock = 0;
        $scormsettings->displayattemptstatus = 0;
        $scormsettings->displaycoursestructure = 0;
        $scormsettings->updatefreq = 0;
        $scormsettings->skipview = 2;
        $scormsettings->hidebrowse = 1;
        $scormsettings->hidetoc = 3;
        $DB->update_record('scorm', $scormsettings);

        xlog("SCORM module '{$scormname}' configured successfully");
    } else {

        $details = "SCORM .zip file '{$filename}' intended for SCORM module '{$scormname}' in course '{$coursename}' is missing";
        $notfound[] = $details;
        xlog($details);
    }
}

xlog('Scripting finished');
xlog(count($found) . ' files were found and processed successfully');
xlog(count($notfound) . ' files were not found');

function xlog($message) {
    global $reportfile;

    // Output to screen
    mtrace($message);

    // Write to report file
    fwrite($reportfile, $message . "\n");

}
// Close the report file
fclose($reportfile);
