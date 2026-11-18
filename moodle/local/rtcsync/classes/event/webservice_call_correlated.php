<?php

namespace local_rtcsync\event;

defined('MOODLE_INTERNAL') || die();

/**
 * An RTC Sync webservice request received with a validated correlation ID.
 *
 * @package    local_rtcsync
 */
final class webservice_call_correlated extends \core\event\base
{
    private const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

    private const FUNCTION_PATTERN = '/\Alocal_rtcsync_[a-z0-9_]+\z/';

    /** Return whether a value is a canonical UUID string. */
    public static function is_valid_correlation_id(string $correlationid): bool
    {
        return preg_match(self::UUID_PATTERN, $correlationid) === 1;
    }

    /** Return whether a value names an RTC Sync external function. */
    public static function is_valid_function_name(string $function): bool
    {
        return preg_match(self::FUNCTION_PATTERN, $function) === 1;
    }

    protected function init()
    {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->context = \context_system::instance();
    }

    public function get_description()
    {
        return get_string(
            'eventwebservicecallcorrelateddesc',
            'local_rtcsync',
            (object) $this->other
        );
    }

    public static function get_name()
    {
        return get_string('eventwebservicecallcorrelated', 'local_rtcsync');
    }

    protected function validate_data()
    {
        parent::validate_data();

        if (!is_array($this->other)) {
            throw new \coding_exception('The correlation ID and function name must be set in other.');
        }

        $keys = array_keys($this->other);
        sort($keys, SORT_STRING);
        if ($keys !== ['correlation_id', 'function']
                || !is_string($this->other['correlation_id'])
                || !self::is_valid_correlation_id($this->other['correlation_id'])
                || !is_string($this->other['function'])
                || !self::is_valid_function_name($this->other['function'])) {
            throw new \coding_exception(
                'The correlation event may contain only a valid UUID and RTC Sync function name.'
            );
        }

        if ((int) $this->userid !== self::USER_NOTLOGGEDIN || (int) $this->anonymous !== 1) {
            throw new \coding_exception('The correlation event must not identify a Moodle user.');
        }
    }

    public static function get_other_mapping()
    {
        return false;
    }
}
