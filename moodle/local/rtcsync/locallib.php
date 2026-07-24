<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

function local_rtcsync_profile_field_definitions(): array
{
    return [
        'rtc_user_program_id' => 'RTC User Program ID',
        'rtc_program_id' => 'RTC Program ID',
        'rtc_program_name' => 'RTC Program',
        'rtc_program_khmer' => 'RTC Program Khmer',
        'rtc_program_code' => 'RTC Program Code',
        'rtc_program_degree_level' => 'RTC Program Degree Level',
        'rtc_program_department' => 'RTC Program Department',
        'rtc_generation_id' => 'RTC Generation ID',
        'rtc_generation' => 'RTC Generation',
        'rtc_generation_years' => 'RTC Generation Years',
        'rtc_academic_year_id' => 'RTC Academic Year ID',
        'rtc_academic_year' => 'RTC Academic Year',
        'rtc_study_year' => 'RTC Study Year',
        'rtc_user_program_promotion' => 'RTC User Program Promotion',
        'rtc_enrollment_status' => 'RTC Enrollment Status',
        'rtc_enrollment_type' => 'RTC Enrollment Type',
        'rtc_previous_user_program_id' => 'RTC Previous User Program ID',
        'rtc_all_enrollments_json' => 'RTC All Enrollments JSON',
    ];
}

function local_rtcsync_ensure_profile_fields(): array
{
    global $DB;

    $categoryname = 'RTC Academic Data';
    $category = $DB->get_record('user_info_category', ['name' => $categoryname], '*', IGNORE_MISSING);
    if (!$category) {
        $category = (object) [
            'name' => $categoryname,
            'sortorder' => $DB->count_records('user_info_category') + 1,
        ];
        $category->id = $DB->insert_record('user_info_category', $category);
    }

    $fields = [];
    foreach (local_rtcsync_profile_field_definitions() as $shortname => $name) {
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname], '*', IGNORE_MISSING);
        if (!$field) {
            $field = (object) [
                'shortname' => $shortname,
                'name' => $name,
                'datatype' => 'text',
                'description' => 'Synced from RTC.',
                'descriptionformat' => FORMAT_PLAIN,
                'categoryid' => $category->id,
                'sortorder' => $DB->count_records('user_info_field', ['categoryid' => $category->id]) + 1,
                'required' => 0,
                'locked' => 1,
                'visible' => 2,
                'forceunique' => 0,
                'signup' => 0,
                'defaultdata' => '',
                'defaultdataformat' => FORMAT_PLAIN,
                'param1' => '80',
                'param2' => '20000',
                'param3' => '0',
                'param4' => '',
                'param5' => '',
            ];
            $field->id = $DB->insert_record('user_info_field', $field);
        } else {
            $updates = false;
            if ((int) $field->categoryid !== (int) $category->id) {
                $field->categoryid = $category->id;
                $updates = true;
            }
            if ($field->name !== $name) {
                $field->name = $name;
                $updates = true;
            }
            if ($field->datatype !== 'text') {
                $field->datatype = 'text';
                $updates = true;
            }
            if ($updates) {
                $DB->update_record('user_info_field', $field);
            }
        }
        $fields[$shortname] = (int) $field->id;
    }

    if (function_exists('profile_purge_user_fields_cache')) {
        profile_purge_user_fields_cache();
    }

    return $fields;
}

function local_rtcsync_save_profile_fields(int $userid, array $fields): int
{
    global $DB;

    $allowed = local_rtcsync_ensure_profile_fields();
    $saved = 0;

    foreach ($fields as $field) {
        $shortname = trim((string) ($field['shortname'] ?? ''));
        if ($shortname === '' || !array_key_exists($shortname, $allowed)) {
            continue;
        }

        $data = (string) ($field['value'] ?? '');
        $fieldid = $allowed[$shortname];
        $record = $DB->get_record('user_info_data', [
            'userid' => $userid,
            'fieldid' => $fieldid,
        ], '*', IGNORE_MISSING);

        if ($record) {
            if ((string) $record->data === $data) {
                continue;
            }
            $record->data = $data;
            $record->dataformat = FORMAT_PLAIN;
            $DB->update_record('user_info_data', $record);
        } else {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $userid,
                'fieldid' => $fieldid,
                'data' => $data,
                'dataformat' => FORMAT_PLAIN,
            ]);
        }

        $saved++;
    }

    return $saved;
}