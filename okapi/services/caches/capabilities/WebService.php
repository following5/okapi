<?php

namespace okapi\services\caches\capabilities;

use okapi\core\Cache;
use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Okapi;
use okapi\core\Request\OkapiRequest;
use okapi\Settings;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    private static $valid_field_names = [
        'types', 'sizes', 'statuses', 'has_ratings', 'languages', 'primary_languages',
        'password_max_length'
    ];
    private static $language_dict = null;
    private static $primary_langs = null;

    public static function call(OkapiRequest $request)
    {
        $result = [];

        # evaluate parameters

        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "types|sizes|statuses|has_ratings";
        $fields = explode("|", $fields);
        foreach ($fields as $field)
            if (!in_array($field, self::$valid_field_names))
                throw new InvalidParam('fields', "'$field' is not a valid field code.");

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);

        $cache_type = $request->get_parameter('cache_type');
        if ($cache_type !== null)
            if (!in_array($cache_type, Okapi::get_local_cachetypes()))
                throw new InvalidParam('type');

        # default fields

        if (in_array('types', $fields)) {
            $result['types'] = Okapi::get_local_cachetypes();
        }
        if (in_array('sizes', $fields)) {
            $result['sizes'] = Okapi::get_local_cachesizes($cache_type);
        }
        if (in_array('has_ratings', $fields)) {
            $result['has_ratings'] = (Settings::get('OC_BRANCH') == 'oc.pl');
        }

        # 'languages' field

        if (in_array('languages', $fields))
        {
            if (self::$language_dict === null)
            {
                # TODO: cache the $language_dict

                $langdict = [];
                if (Settings::get('OC_BRANCH') == 'oc.pl')
                {
                    $tmp = Db::select_all("
                        select lower(short) as lang, pl, en, nl
                        from languages
                    ");
                    foreach ($tmp as $row)
                        foreach (['en', 'nl', 'pl'] as $lang)
                            $langdict[$row['lang']][$lang] = $row[$lang];
                }
                else
                {
                    $tmp = Db::select_all("
                        select
                            lower(languages.short) as lang,
                            lower(sys_trans_text.lang) trans_lang,
                            ifnull(sys_trans_text.text, languages.name) as name
                        from languages
                        left join sys_trans on languages.trans_id = sys_trans.id
                        left join sys_trans_text on sys_trans.id = sys_trans_text.trans_id
                    ");
                    foreach ($tmp as $row)
                        $langdict[$row['lang']][$row['trans_lang']] = $row['name'];
                }
                self::$language_dict = $langdict;
            }

            $result['languages'] = self::$language_dict;
            foreach (self::$language_dict as $lang => $trans)
                $result['languages'][$lang] = Okapi::pick_best_language($trans, $langprefs);
            asort($result['languages']);
        }

        # 'primary_languages' field

        if (in_array('primary_languages', $fields))
        {
            # TODO: cache the $primary_langs

            if (self::$primary_langs === null)
            {
                # Get the languages that are used by the most number of owners
                # of active caches.

                $language_stats = Db::select_all("
                    select lower(language) as lang, count(distinct caches.user_id) as count
                    from cache_desc
                    join caches on caches.cache_id = cache_desc.cache_id
                    where caches.status = 1
                    and cache_desc.node='".Db::escape_string(Settings::get('OC_NODE_ID'))."'
                    group by language
                    order by count desc, language
                ");
                $total_owners = 0;
                foreach ($language_stats as $row)
                    $total_owners += $row['count'];

                # Do some educated guess of significant languages, after anlaysis
                # of OCDE and OCPL data and estimations for small OC sites.

                if ($total_owners == 0) {
                    $primary_langs = [Okapi::Settings('SITELANG')];
                } else {
                    $threshold = floor(log($total_owners) - 1);
                    self::$primary_langs = [];
                    foreach ($language_stats as $row)
                        if ($row['count'] >= $threshold)
                            self::$primary_langs[] = $row['lang'];
                    unset($threshold);
                }
                unset($total_owners);
            }
            $result['primary_languages'] = self::$primary_langs;
        }

        # 'password_max_length' field

        if (in_array('password_max_length', $fields)) {
            if (Settings::get('OC_BRANCH') == 'oc.pl' && $cache_type == 'Traditional')
                $result['password_max_length'] = 0;
            else
                $result['password_max_length'] = Db::field_length('caches', 'logpw') + 0;
        }

        return Okapi::formatted_response($request, $result);
    }
}
