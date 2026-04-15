<?php
namespace local_areteia;

defined('MOODLE_INTERNAL') || die();

/**
 * Class to extract course data for AreteIA
 */
class data_provider {
 
    /** Allowed file extensions for RAG processing */
    private const ALLOWED_EXTS = ['pdf', 'ppt', 'pptx', 'docx', 'doc'];

    /** Allowed module types: only Resources, not Activities */
    private const RESOURCE_MODULES = ['resource', 'folder', 'page', 'url', 'book', 'label', 'imscp'];

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
            self::delete_sync_dir($courseid);
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
                $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                if (!in_array($ext, self::ALLOWED_EXTS)) {
                    continue;
                }
                
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
                    if (!in_array($cm->modname, self::RESOURCE_MODULES)) continue;

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

                        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                        if (!in_array($ext, self::ALLOWED_EXTS)) {
                            continue;
                        }

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
                            if (file_exists($localpath)) {
                                $localpath = $target_dir . '/' . $file->get_contenthash() . '_' . $file->get_filename();
                            }
                            if (!file_exists($localpath)) {
                                try {
                                    $file->copy_content_to($localpath);
                                } catch (\Exception $e) {
                                    file_put_contents($CFG->dataroot . '/areteia_sync/debug.txt', "Error copying " . $file->get_filename() . ": " . $e->getMessage() . "\n", FILE_APPEND);
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

    /**
     * Get a hierarchical tree of course materials (Course > Sections > Activities > Files).
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_materials_tree(int $courseid): array {
        global $DB;
        $fs = get_file_storage();
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course);

        $tree = [
            'id'       => $courseid,
            'name'     => $course->fullname,
            'type'     => 'course',
            'sections' => []
        ];

        // 1. Files in the course context itself (Intro / General files)
        $course_context = \context_course::instance($courseid);
        $course_files = $fs->get_area_files($course_context->id, 'course', 'section');
        $general_files = [];
        foreach ($course_files as $file) {
            if ($file->is_directory()) continue;
            $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTS)) continue;

            $general_files[] = [
                'id'       => $file->get_id(),
                'name'     => $file->get_filename(),
                'type'     => 'file',
                'relpath'  => '0_General/' . $file->get_filename()
            ];
        }

        if (!empty($general_files)) {
            $tree['sections'][] = [
                'id'         => 0,
                'name'       => 'Materiales generales del curso',
                'type'       => 'section',
                'activities' => [
                    [
                        'id'    => 'gen',
                        'name'  => 'Archivos intro',
                        'type'  => 'activity',
                        'files' => $general_files
                    ]
                ]
            ];
        }

        // 2. Traverse sections and activities
        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) continue;
            if (empty($modinfo->sections[$section->section])) continue;

            $section_name = get_section_name($course, $section);
            $section_node = [
                'id'         => $section->id,
                'name'       => $section_name ?: "Sección {$section->section}",
                'type'       => 'section',
                'activities' => []
            ];

            foreach ($modinfo->sections[$section->section] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible) continue;
                if (!in_array($cm->modname, self::RESOURCE_MODULES)) continue;

                $activity_node = [
                    'id'    => $cm->id,
                    'name'  => $cm->name,
                    'type'  => 'activity',
                    'files' => []
                ];

                // Use DB to get all files in this module's context, regardless of filearea
                try {
                    $mod_context = \context_module::instance($cm->id);
                    $filerecords = $DB->get_records('files', ['contextid' => $mod_context->id]);
                    
                    foreach ($filerecords as $r) {
                        try {
                            $file = $fs->get_file_instance($r);
                        } catch (\Exception $e) {
                            continue;
                        }

                        if ($file->is_directory()) continue;
                        if ($file->get_component() === 'user' || $file->get_filearea() === 'draft') continue;

                        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                        if (!in_array($ext, self::ALLOWED_EXTS)) continue;

                        $activity_node['files'][] = [
                            'id'      => $file->get_id(),
                            'name'    => $file->get_filename(),
                            'type'    => 'file',
                            'relpath' => $section->section . '_' . clean_param($section_name, PARAM_FILE) . '/' .
                                         $cm->id . '_' . clean_param($cm->name, PARAM_FILE) . '/' . $file->get_filename()
                        ];
                    }
                } catch (\Exception $e) {
                    continue; // Skip activities with context errors
                }

                if (!empty($activity_node['files'])) {
                    $section_node['activities'][] = $activity_node;
                }
            }

            if (!empty($section_node['activities'])) {
                $tree['sections'][] = $section_node;
            }
        }

        return $tree;
    }

    /**
     * Create a new Moodle Assign activity programmatically
     * 
     * @param int $courseid
     * @param string $name
     * @param string $description
     * @return object The created course module info
     */
    public static function create_assign_activity($courseid, $name, $description) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $module = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);
        
        // Find or create a valid section
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        $sectionid = 0;
        foreach ($sections as $s) {
            if ($s->section > 0) {
                $sectionid = $s->id;
                $sectionnum = $s->section;
                break;
            }
        }
        
        // 1. Create assignment instance
        $assign = new \stdClass();
        $assign->course = $courseid;
        $assign->name = $name;
        $assign->intro = $description;
        $assign->introformat = FORMAT_MARKDOWN;
        $assign->grade = 100;
        $assign->timemodified = time();
        $assign->id = $DB->insert_record('assign', $assign);
        
        // 2. Add course module
        $cm = new \stdClass();
        $cm->course = $courseid;
        $cm->module = $module->id;
        $cm->instance = $assign->id;
        $cm->section = $sectionid;
        $cm->id = add_course_module($cm);
        
        // 3. Add cm to section
        course_add_cm_to_section($courseid, $cm->id, $sectionnum);
        
        // 4. Rebuild cache
        rebuild_course_cache($courseid, true);
        
        return (object)['coursemodule' => $cm->id];
    }

    /**
     * Recursively delete the sync directory for a course.
     *
     * @param int $courseid
     */
    public static function delete_sync_dir($courseid) {
        global $CFG;
        $base_sync_dir = $CFG->dataroot . '/areteia_sync/course_' . $courseid;
        if (file_exists($base_sync_dir)) {
            self::rrmdir($base_sync_dir);
        }
    }

    /**
     * Internal recursive directory removal helper.
     */
    private static function rrmdir($dir) {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
