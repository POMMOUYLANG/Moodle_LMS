<?php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/rtcsync:manageformative', $context);

$url = new moodle_url('/local/rtcsync/formative.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('formativetitle', 'local_rtcsync'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd('local_rtcsync/formative', 'init', [[
    'statusOn' => get_string('formativestatuson', 'local_rtcsync'),
    'statusOff' => get_string('formativestatusoff', 'local_rtcsync'),
    'helpOn' => get_string('formativeonhelp', 'local_rtcsync'),
    'helpOff' => get_string('formativeoffhelp', 'local_rtcsync'),
    'summary' => get_string('formativesummaryjs', 'local_rtcsync'),
]]);

$gradeitems = grade_item::fetch_all(['courseid' => $courseid]) ?: [];
$gradeitems = array_filter($gradeitems, static fn(grade_item $item): bool =>
    $item->itemtype === 'mod'
    && !empty($item->itemmodule)
    && (float) $item->grademax > 0
);
usort($gradeitems, static fn(grade_item $left, grade_item $right): int =>
    ((int) $left->sortorder <=> (int) $right->sortorder) ?: ((int) $left->id <=> (int) $right->id)
);

$config = $DB->get_record('local_rtcsync_formcfg', ['courseid' => $courseid]);
$saveditems = $DB->get_records('local_rtcsync_formitem', ['courseid' => $courseid], '', '*', 0, 0);
$saveditems = array_combine(
    array_map(static fn(stdClass $item): int => (int) $item->itemid, $saveditems),
    array_values($saveditems)
) ?: [];
$errors = [];
$submitted = (bool) data_submitted();
if ($submitted) {
    require_sesskey();
}
$enabled = $submitted ? optional_param('enabled', 0, PARAM_BOOL) : (int) ($config->enabled ?? 0);
$includedinput = $submitted ? optional_param_array('included', [], PARAM_INT) : [];
$labelinput = $submitted ? optional_param_array('label', [], PARAM_TEXT) : [];
$weightinput = $submitted ? optional_param_array('weight', [], PARAM_RAW_TRIMMED) : [];

if ($submitted) {
    $validids = array_map(static fn(grade_item $item): int => (int) $item->id, $gradeitems);
    $includedids = array_values(array_intersect(array_map('intval', array_keys($includedinput)), $validids));
    $totalweight = 0.0;

    if ($enabled && !$includedids) {
        $errors[] = get_string('formativeerrornoitems', 'local_rtcsync');
    }
    foreach ($includedids as $itemid) {
        $weight = $weightinput[$itemid] ?? null;
        if (!is_numeric($weight) || (float) $weight <= 0 || (float) $weight > 100) {
            $errors[] = get_string('formativeerrorweight', 'local_rtcsync');
            break;
        }
        $totalweight += (float) $weight;
    }
    if ($includedids && abs($totalweight - 100) > 0.01) {
        $errors[] = get_string('formativeerrortotal', 'local_rtcsync', format_float($totalweight, 2));
    }

    if (!$errors) {
        $transaction = $DB->start_delegated_transaction();
        $now = time();
        $configrecord = (object) [
            'courseid' => $courseid,
            'enabled' => $enabled,
            'timemodified' => $now,
        ];
        if ($config) {
            $configrecord->id = $config->id;
            $DB->update_record('local_rtcsync_formcfg', $configrecord);
        } else {
            $configrecord->timecreated = $now;
            $configrecord->id = $DB->insert_record('local_rtcsync_formcfg', $configrecord);
        }

        foreach ($gradeitems as $gradeitem) {
            $itemid = (int) $gradeitem->id;
            $existing = $saveditems[$itemid] ?? null;
            $label = trim((string) ($labelinput[$itemid] ?? $gradeitem->itemname ?? ''));
            if ($label === '') {
                $label = (string) ($gradeitem->itemname ?? get_string('formativeunnamed', 'local_rtcsync'));
            }
            $record = (object) [
                'courseid' => $courseid,
                'itemid' => $itemid,
                'included' => in_array($itemid, $includedids, true) ? 1 : 0,
                'label' => $label,
                'weight' => is_numeric($weightinput[$itemid] ?? null) ? round((float) $weightinput[$itemid], 2) : 0,
                'timemodified' => $now,
            ];
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('local_rtcsync_formitem', $record);
            } else {
                $record->timecreated = $now;
                $DB->insert_record('local_rtcsync_formitem', $record);
            }
        }
        $transaction->allow_commit();

        redirect($url, get_string($enabled ? 'formativeenabledsuccess' : 'formativedisabledsuccess', 'local_rtcsync'));
    }
}

echo $OUTPUT->header();

$selectedcount = 0;
$selectedweight = 0.0;
foreach ($gradeitems as $gradeitem) {
    $itemid = (int) $gradeitem->id;
    $isincluded = $submitted
        ? array_key_exists($itemid, $includedinput)
        : !empty($saveditems[$itemid]->included);
    if ($isincluded) {
        $selectedcount++;
        $selectedweight += (float) ($submitted
            ? ($weightinput[$itemid] ?? 0)
            : ($saveditems[$itemid]->weight ?? 0));
    }
}

echo html_writer::start_div('local-rtcsync-formative', ['data-region' => 'formative-settings']);
echo html_writer::start_div('local-rtcsync-formative__intro');
echo html_writer::div(get_string('formativeeyebrow', 'local_rtcsync'), 'local-rtcsync-formative__eyebrow');
echo html_writer::tag('h2', get_string('formativetitle', 'local_rtcsync'));
echo html_writer::tag('p', get_string('formativeintro', 'local_rtcsync'));
echo html_writer::end_div();

foreach ($errors as $error) {
    echo $OUTPUT->notification($error, 'notifyproblem');
}

echo html_writer::start_tag('form', [
    'action' => $url,
    'method' => 'post',
    'data-region' => 'formative-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$statusclass = $enabled ? 'is-enabled' : 'is-disabled';
echo html_writer::start_div("local-rtcsync-formative__status {$statusclass}");
echo html_writer::start_div('local-rtcsync-formative__status-copy');
echo html_writer::tag('span', get_string($enabled ? 'formativestatuson' : 'formativestatusoff', 'local_rtcsync'), [
    'class' => 'local-rtcsync-formative__badge',
    'data-region' => 'sync-badge',
    'aria-live' => 'polite',
]);
echo html_writer::tag('strong', get_string('formativedestination', 'local_rtcsync'));
echo html_writer::tag('p', get_string($enabled ? 'formativeonhelp' : 'formativeoffhelp', 'local_rtcsync'), [
    'data-region' => 'sync-help',
]);
echo html_writer::end_div();
echo html_writer::start_tag('label', ['class' => 'local-rtcsync-formative__switch']);
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'enabled',
    'value' => '1',
    'checked' => $enabled ? 'checked' : null,
    'data-action' => 'toggle-sync',
]);
echo html_writer::tag('span', '', ['class' => 'local-rtcsync-formative__switch-track', 'aria-hidden' => 'true']);
echo html_writer::tag('span', get_string('formativeenablelabel', 'local_rtcsync'), ['class' => 'local-rtcsync-formative__switch-label']);
echo html_writer::end_tag('label');
echo html_writer::end_div();

echo html_writer::start_div('local-rtcsync-formative__toolbar');
echo html_writer::div(
    get_string('formativesummary', 'local_rtcsync', (object) [
        'count' => $selectedcount,
        'weight' => format_float($selectedweight, 2),
    ]),
    'local-rtcsync-formative__summary',
    ['data-region' => 'selection-summary', 'aria-live' => 'polite']
);
echo html_writer::tag('button', get_string('formativebalance', 'local_rtcsync'), [
    'type' => 'button',
    'class' => 'btn btn-secondary',
    'data-action' => 'balance-weights',
]);
echo html_writer::end_div();

if (!$gradeitems) {
    echo html_writer::div(get_string('formativenoactivities', 'local_rtcsync'), 'alert alert-info');
} else {
    echo html_writer::start_div('local-rtcsync-formative__items');
    foreach ($gradeitems as $gradeitem) {
        $itemid = (int) $gradeitem->id;
        $saved = $saveditems[$itemid] ?? null;
        $isincluded = $submitted ? array_key_exists($itemid, $includedinput) : !empty($saved->included);
        $label = $submitted ? ($labelinput[$itemid] ?? '') : ($saved->label ?? $gradeitem->itemname ?? '');
        $weight = $submitted ? ($weightinput[$itemid] ?? '') : ($saved->weight ?? '');
        $rowclasses = 'local-rtcsync-formative__item' . ($isincluded ? ' is-included' : '');

        echo html_writer::start_div($rowclasses, [
            'data-region' => 'activity-row',
            'data-item-id' => $itemid,
        ]);
        echo html_writer::start_div('local-rtcsync-formative__include');
        echo html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'id' => "include-{$itemid}",
            'name' => "included[{$itemid}]",
            'value' => '1',
            'checked' => $isincluded ? 'checked' : null,
            'data-action' => 'include-activity',
        ]);
        echo html_writer::start_tag('label', ['for' => "include-{$itemid}"]);
        echo html_writer::tag('strong', format_string($gradeitem->itemname ?: get_string('formativeunnamed', 'local_rtcsync')));
        echo html_writer::tag('span', get_string('formativesource', 'local_rtcsync', ucfirst((string) $gradeitem->itemmodule)));
        echo html_writer::end_tag('label');
        echo html_writer::end_div();

        echo html_writer::start_div('local-rtcsync-formative__field');
        echo html_writer::tag('label', get_string('formativelabel', 'local_rtcsync'), ['for' => "label-{$itemid}"]);
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'class' => 'form-control',
            'id' => "label-{$itemid}",
            'name' => "label[{$itemid}]",
            'value' => $label,
            'maxlength' => 255,
            'data-field' => 'label',
            'disabled' => $isincluded ? null : 'disabled',
        ]);
        echo html_writer::end_div();

        echo html_writer::start_div('local-rtcsync-formative__field local-rtcsync-formative__weight');
        echo html_writer::tag('label', get_string('formativeweight', 'local_rtcsync'), ['for' => "weight-{$itemid}"]);
        echo html_writer::start_div('input-group');
        echo html_writer::empty_tag('input', [
            'type' => 'number',
            'class' => 'form-control',
            'id' => "weight-{$itemid}",
            'name' => "weight[{$itemid}]",
            'value' => $weight,
            'min' => '0.01',
            'max' => '100',
            'step' => '0.01',
            'inputmode' => 'decimal',
            'data-field' => 'weight',
            'disabled' => $isincluded ? null : 'disabled',
        ]);
        echo html_writer::tag('span', '%', ['class' => 'input-group-text']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

echo html_writer::start_div('local-rtcsync-formative__footer');
echo html_writer::tag('p', get_string('formativefooterhelp', 'local_rtcsync'));
echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();

echo $OUTPUT->footer();
