<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Class to extract course data for AreteIA
 */
class data_provider {

    /**
     * Get a summary of the course content
     * 
     * @param int $courseid
     * @return array
     */
    public static function get_course_summary($courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        
        $data = [
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'summary' => strip_tags($course->summary),
            'sections' => []
        ];

        foreach ($modinfo->get_section_info_all() as $section) {
            if ($section->uservisible && ($section->summary || !empty($modinfo->sections[$section->section]))) {
                $sectiondata = [
                    'name' => get_section_name($course, $section),
                    'summary' => strip_tags($section->summary),
                    'activities' => []
                ];

                if (!empty($modinfo->sections[$section->section])) {
                    foreach ($modinfo->sections[$section->section] as $cmid) {
                        $cm = $modinfo->get_cm($cmid);
                        if ($cm->uservisible) {
                            $sectiondata['activities'][] = [
                                'name' => $cm->name,
                                'type' => $cm->modname,
                                'description' => strip_tags($cm->content) // Simplificado
                            ];
                        }
                    }
                }
                $data['sections'][] = $sectiondata;
            }
        }

        return $data;
    }

    /**
     * Get all files associated with the course
     * 
     * @param int $courseid
     * @return array
     */
    public static function get_course_files($courseid, $extract_to_sync_dir = false) {
        global $CFG, $DB;
        $fs = get_file_storage();
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);
        $res = [];
        
        $base_sync_dir = '';
        if ($extract_to_sync_dir) {
            $base_sync_dir = $CFG->dataroot . '/areteia_sync/course_' . $courseid;
            if (!file_exists($base_sync_dir)) {
                mkdir($base_sync_dir, 0777, true);
            }
        }

        // 1. Files in the course context itself (Intro, general files)
        $course_context = \context_course::instance($courseid);
        $course_files = $fs->get_area_files($course_context->id, 'course', 'section');
        foreach ($course_files as $file) {
            if (!$file->is_directory()) {
                $section_folder = $base_sync_dir . '/0_General';
                $reldata = [
                    'filename' => $file->get_filename(),
                    'mimetype' => $file->get_mimetype(),
                    'size' => $file->get_filesize(),
                    'section' => 'General'
                ];
                if ($extract_to_sync_dir) {
                    if (!file_exists($section_folder)) mkdir($section_folder, 0777, true);
                    $localpath = $section_folder . '/' . $file->get_filename();
                    if (file_exists($localpath)) $localpath = $section_folder . '/' . $file->get_contenthash() . '_' . $file->get_filename();
                    if (!file_exists($localpath)) $file->copy_content_to($localpath);
                    $reldata['localpath'] = $localpath;
                }
                $res[] = $reldata;
            }
        }

        // 2. Traverse sections and modules
        foreach ($modinfo->get_section_info_all() as $section) {
            $section_name = clean_param(get_section_name($course, $section), PARAM_FILE);
            $section_folder_name = $section->section . '_' . ($section_name ?: 'Section');
            
            if (!empty($modinfo->sections[$section->section])) {
                foreach ($modinfo->sections[$section->section] as $cmid) {
                    $cm = $modinfo->get_cm($cmid);
                    if (!$cm->uservisible) continue;

                    $mod_context = \context_module::instance($cm->id);
                    
                    // Fetch all files associated with this module's context, regardless of component/filearea
                    $module_files = [];
                    $filerecords = $DB->get_records('files', ['contextid' => $mod_context->id]);
                    foreach ($filerecords as $r) {
                        if ($r->filename !== '.') {
                            try {
                                $module_files[] = $fs->get_file_instance($r);
                            } catch (\Exception $e) {
                                // Ignore missing file data
                            }
                        }
                    }
                    
                    foreach ($module_files as $file) {
                        if ($file->is_directory()) continue;
                        // Avoid system/temp files
                        if ($file->get_component() == 'user' || $file->get_filearea() == 'draft') continue;

                        $activity_name = clean_param($cm->name, PARAM_FILE);
                        $activity_folder_name = $cm->id . '_' . ($activity_name ?: 'Activity');
                        
                        $reldata = [
                            'filename' => $file->get_filename(),
                            'mimetype' => $file->get_mimetype(),
                            'size' => $file->get_filesize(),
                            'section' => $section_name,
                            'module' => $cm->name,
                            'modname' => $cm->modname
                        ];

                        if ($extract_to_sync_dir) {
                            $target_dir = $base_sync_dir . '/' . $section_folder_name . '/' . $activity_folder_name;
                            if (!file_exists($target_dir)) {
                                mkdir($target_dir, 0777, true);
                            }
                            $localpath = $target_dir . '/' . $file->get_filename();
                            if (file_exists($localpath)) $localpath = $target_dir . '/' . $file->get_contenthash() . '_' . $file->get_filename();
                            if (!file_exists($localpath)) {
                                try {
                                    $file->copy_content_to($localpath);
                                } catch (\Exception $e) {
                                    // Log or skip if fail
                                }
                            }
                            $reldata['localpath'] = $localpath;
                        }
                        $res[] = $reldata;
                    }
                }
            }
        }
        
        return $res;
    }
}
