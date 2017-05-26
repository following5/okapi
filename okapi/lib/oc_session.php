<?php

namespace okapi;

/**
 * Use this class to access OC session variables. This is especially useful if
 * you want to determine which user is currently logged in to OC.
 */
class OCSession
{
    /** Return ID of currently logged in user or NULL if no user is logged in. */
    public static function get_user_id()
    {
        static $cached_result = false;
        if ($cached_result !== false)
            return $cached_result;

        $cookie_name = Settings::get('OC_COOKIE_NAME');
        if (!isset($_COOKIE[$cookie_name]))
            return null;
        $OC_data = self::a_bit_safer_unserialize(base64_decode($_COOKIE[$cookie_name]));
        if (!isset($OC_data['sessionid']))
            return null;
        $OC_sessionid = $OC_data['sessionid'];
        if (!$OC_sessionid)
            return null;

        return Db::select_value("
            select sys_sessions.user_id
            from
                sys_sessions,
                user
            where
                sys_sessions.uuid = '".Db::escape_string($OC_sessionid)."'
                and user.user_id = sys_sessions.user_id
                and user.is_active_flag = 1
        ");
    }

    private static function a_bit_safer_unserialize($str) {
        // Related OCPL issue: https://github.com/opencaching/opencaching-pl/issues/1020
        if (PHP_MAJOR_VERSION > 7) {
            return unserialize($str, array(
                'allowed_classes' => false
            ));
        } else {
            return unserialize($str);
        }
    }
}
