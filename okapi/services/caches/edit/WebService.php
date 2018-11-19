<?php

namespace okapi\services\caches\edit;

use Exception;
use okapi\core\Okapi;
use okapi\core\Db;
use okapi\core\Exception\BadRequest;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Request\OkapiRequest;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\OkapiServiceRunner;
use okapi\Settings;
use okapi\services\attrs\AttrHelper;


class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    public static function call(OkapiRequest $request)
    {
        $cache_code = $request->get_parameter('cache_code');
        if ($cache_code == null)
            throw new ParamMissing('cache_code');
        $cache = OkapiServiceRunner::call(
            'services/caches/geocache',
            new OkapiInternalRequest(
                $request->consumer,
                $request->token,
                array(
                    'cache_code' => $cache_code,
                    'fields' => 'internal_id|type|date_created|location|type|size2|difficulty|terrain|trip_time|trip_distance|attr_acodes|names|name'
                )
            )
        );
        $cache_internal_id_escaped = Db::escape_string($cache['internal_id']);
        $cache_internal = Db::select_row("
            select node, user_id from caches where cache_id='".$cache_internal_id_escaped."'
        ");
        if ($cache_internal['node'] != Settings::get('OC_NODE_ID')) {
            throw new Exception(
                "This site's database contains the geocache '$cache_code' which has been"
                . " imported from another OC node. OKAPI is not prepared for that."
            );
        }
        if ($cache_internal['user_id'] != $request->token->user_id)
            throw new BadRequest("Only own caches may be edited.");

        $problems = [];
        $change_sqls_escaped = [];

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $langprefs = explode("|", $langpref);

        Okapi::gettext_domain_init($langprefs);
        try
        {
            # name

            $name = $request->get_parameter('name');
            if ($name !== null) {
                $old_name = $request->get_parameter('old_name');
                if ($old_name === null)
                    throw new ParamMissing('old_name');
                elseif (count($cache['names']) != 1)
                    throw new Exception("Unexpected cache name count");
                elseif ($old_name != $cache['name'])
                    throw new InvalidParam('old_name', "'".$old_name."' does not match the cache name.");
                else
                    $change_sqls_escaped[] = "name = '".Db::escape_string($name)."'";
            }

            # type

            $type = $request->get_parameter('type');
            if ($type !== null) {
                if (!in_array($type, Okapi::get_local_okapi_cache_types()))
                    throw new InvalidParam('type');
                elseif ($type != $cache['type'])
                    $change_sqls_escaped[] = "type = ".Okapi::cache_type_name2id($type);
            } else {
                $type = $cache['type'];
            }

            $capabilities = OkapiServiceRunner::call(
                'services/caches/capabilities',
                new OkapiInternalRequest(
                    $request->consumer,
                    $request->token,
                    ['type' => $type, 'fields' => 'sizes|password_max_length']
                )
            );

            # location

            $location = $request->get_parameter('location');
            if ($location !== null)
            {
                $coords = Okapi::parse_location($location);
                if ($coords === null)
                    throw new InvalidParam('location');
                if ($coords[0] < -90 || $coords[0] > 90)
                    $problems['location'] = _("Latitude degrees must range between -90 and 90.");
                elseif ($coords[1] < -180 || $coords[1] > 180)
                    $problems['location'] = _("Longitude degrees must range between -180 and 180.");
                else {
                    $old_coords = Okapi::parse_location($cache['location']);
                    if ($coords != $old_coords) {
                        $change_sqls_escaped[] = "
                            latitude='".Db::escape_float($coords[0])."',
                            longitude='".Db::escape_float($coords[1])."'
                        ";
                    }
                }
                unset($old_coords);
                unset($coords);
            }

            # size -- DEPENDS ON TYPE

            $size = $request->get_parameter('size');
            if ($size !== null)
            {
                if (!in_array($size, Okapi::get_local_cache_sizes()))
                    throw new InvalidParam('size');
                if (!in_array($size, $capabilities['sizes']))
                    $problems['size'] = _("This size is not available for this type of cache.");
                elseif ($size != $cache['size2'])
                    $change_sqls_escaped[] = "size = ".Okapi::cache_size2_to_sizeid($size);
            }
            elseif ($type !== $cache['type'] && !in_array($cache['size2'], $capabilities['sizes']))
            {
                if (count($capabilities['sizes']) == 1) {
                    # Enforce the only valid cache size after a type change.
                    $change_sqls_escaped[] = "size = ".Okapi::cache_size2_to_sizeid($capabilities['sizes'][0]);
                } else {
                    $problems['type'] = _("Cache type does not match cache size.");
                }
            }

            # difficulty, terrain

            foreach (['difficulty', 'terrain'] as $property)
            {
                $tmp = $request->get_parameter($property);
                if ($tmp != null)
                {
                    if (!preg_match('/^[0-9](\.[0-9])?$/', $tmp))
                        throw new InvalidParam($property);
                    elseif (!in_array($tmp, [1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5]))
                        throw new InvalidParam($property);
                    elseif ($tmp != $cache[$property])
                        $change_sqls_escaped[] = $property." = ".(2 * $tmp);
                }
            }

            # trip_time, trip_distance

            foreach (
                ['trip_time' => 'search_time', 'trip_distance' => 'way_length']
                as $property => $db_field_sql
            ) {
                $tmp = $request->get_parameter($property);
                if ($tmp != null)
                {
                    # OC websites can handle numbers >= 0.01 for both properties,
                    # so we accept only those. Also impose some reasonable upper limit
                    # (but retain higher values that were entered on the OC website).

                    $max_value = max($cache[$property], $property == 'trip_time' ? 999 : 99999);

                    if (!preg_match('/^(null|[0-9]+\.?[0-9]*)$/', $tmp)) {
                        throw new InvalidParam($property);
                    } elseif ($tmp != 'null' && ($tmp < 0.01 || $tmp > $max_value)) {
                        $problems[$property] = (
                            $property == 'trip_time'
                            ? sprintf(_("Invalid trip time; must range between 1 minute and %d hours."), $max_value)
                            : sprintf(_("Invalid trip distance; must range between 0.01 and %d km."), $max_value)
                        );
                    } else {
                        $change_sqls_escaped[] = $db_field_sql." = ".(0 + $tmp);  # 'null' => 0
                    }
                    unset($max_value);
                }
            }

            # passwd -- DEPENDS ON TYPE

            $passwd = $request->get_parameter('passwd');
            if ($passwd !== null)
            {
                if (
                    $passwd != '' &&
                    Settings::get('OC_BRANCH') == 'oc.pl' &&
                    $type == 'Traditional' &&
                    $cache['date_created'] > '2010-06-18 20:03:18'
                ) {
                    # We won't bother the user with the creation date thing here.
                    # The *current* rule is that OCPL sites do not allow tradi passwords.
                    # For older caches, the user won't see this message.

                    $problems['passwd'] = sprintf(
                        _('%s does not allow log passwords for traditional caches.'),
                        Okapi::get_normalized_site_name()
                    );
                } elseif (strlen($passwd) > $capabilities['password_max_length']) {
                    $problems['passwd'] = sprintf(_(
                        'The password must not be longer than %d characters.'),
                        $capabilities['password_max_length']
                    );
                } else {
                    $oldpw = Db::select_value("select logpw from caches where cache_id='".$cache_internal_id_escaped."'");
                    if ($passwd != $oldpw)
                        $change_sqls_escaped[] = "logpw = '".Db::escape_string($passwd)."'";
                    unset($oldpw);
                }
            } elseif (
                $type != $cache['type'] && $type != 'Traditional' &&
                Settings::get('OC_BRANCH') == 'oc.pl'
            ) {
                # Remove password after type change.
                $change_sqls_escaped[] = "logpw = ''";
            }

            # gc_code

            $gc_code = $request->get_parameter('gc_code');
            if ($gc_code !== null)
            {
                if ($gc_code == '') {
                    throw new InvalidParam(
                        'gc_code',
                        "Must not be empty. Supply 'null' if you want to remove the GC code."
                    );
                }
                if ($gc_code == 'null')
                    $gc_code = '';

                # Correct frequent misspelling.
                $gc_code = str_replace('O', '0', $gc_code);

                if (!preg_match('/^(|^GC[0-9A-HJKMNPQRTV-Z]{2,})$/', $gc_code))
                    $problems['gc_code'] = _("Invalid GC code");
                else
                    $change_sqls_escaped[] = "wp_gc = '".Db::escape_string($gc_code)."'";
            }

            # attributes

            # The add/remove logic ensures that we will not remove local attributes
            # that are not available (yet) in OKAPI. It also prevents accidential
            # deletions.

            $acodes_to_remove = [];
            $acodes_to_add = [];

            $attributes = $request->get_parameter('attributes');
            if ($attributes !== null)
            {
                $available_acodes = OkapiServiceRunner::call(
                    'services/attrs/attribute_index',
                    new OkapiInternalRequest(
                        $request->consumer,
                        $request->token,
                        [
                            'fields' => 'name|is_addable|incompatible_acodes',
                            'only_locally_used' => 'true',
                            'langpref' => $langpref
                        ]
                    )
                );
                foreach (explode('|', $attributes) as $acode)
                {
                    $remove = (substr($acode, 0, 1) == '-');
                    if ($remove)
                        $acode = substr($acode, 1);
                    if (!isset($available_acodes[$acode]))
                        throw new InvalidParam('attributes', "Invalid A-Code: '".$acode."'");
                    if ($remove)
                        $acodes_to_remove[] = $acode;
                    elseif ($available_acodes[$acode]['is_addable'])
                        $acodes_to_add[] = $acode;
                }
                unset($remove);
                unset($acode);

                # test for conflicts

                if ($tmp = array_intersect($acodes_to_remove, $acodes_to_add)) {
                    throw new InvalidParam(
                        'attributes',
                        "Contraticting operations for ".implode(' and ', $tmp)
                    );
                }
                $effective_acodes = array_merge(
                    array_diff($cache['attr_acodes'], $acodes_to_remove),
                    $acodes_to_add
                );
                foreach ($acodes_to_add as $acode)
                    foreach ($available_acodes[$acode]['incompatible_acodes'] as $incompatible)
                        if (in_array($incompatible, $effective_acodes)) {
                            $problems['attributes'] = sprintf(
                                _("The attributes '%s' and '%s' contradict."),
                                $available_acodes[$acode]['name'],
                                $available_acodes[$incompatible]['name']
                            );
                            break 2;
                        }
                unset($effective_acodes);
            }

            # descriptions and hint

            $description = $request->get_parameter('description');
            $short_description = $request->get_parameter('short_description');
            $hint = $request->get_parameter('hint');
            $desc_changes = ($description !== null || $short_description !== null || $hint !== null);

            if ($desc_changes)
            {
                $language = $request->get_parameter('language');
                if ($language === null) {
                    throw new ParamMissing('language');
                }
                $tmp = OkapiServiceRunner::call(
                    'services/caches/capabilities',
                    new OkapiInternalRequest(
                        $request->consumer, $request->token, ['fields' => 'languages'])
                    );
                if (!isset($tmp['languages'][$language]))
                    throw new InvalidParam('language', "Invalid language code: '".$language."'");

                # purify texts

                if ($description != '') {
                    list($description, $value_for_desc_html_field)
                        = Okapi::purify_html($description);
                }
                if ($short_description != '') {
                    $short_description = preg_replace('/[\r\n\t]+/', ' ', $short_description);
                    $short_description = trim($short_description);
                }
                if ($hint != '') {
                    $hint = preg_replace('/\r\n?/', '\n', $hint);
                    $hint = preg_replace('/\t/', ' ', $hint);
                    $hint = trim($hint);
                }

                # validate texts

                $language_upper_escaped = Db::escape_string(strtoupper($language));
                $is_new_language = !Db::select_value("
                    select 1 from cache_desc
                    where cache_id = '".$cache_internal_id_escaped."'
                    and language ='".$language_upper_escaped."'
                ");
                if ($is_new_language && $description.$short_description.$hint == '') {
                    if ($description !== null)
                        $problems['description'] = _("Please enter some text.");
                    elseif ($short_description !== null)
                        $problems['short_description'] = _("Please enter some text.");
                    else
                        $problems['hint'] = _("Please enter some text.");
                }
            }

            Okapi::gettext_domain_restore();
        }
        catch (Exception $e)
        {
            Okapi::gettext_domain_restore();
            throw $e;
        }

        # save changes

        if (!$problems && ($change_sqls_escaped || $acodes_to_add || $acodes_to_remove || $desc_changes))
        {
            Db::execute("start transaction");

            # description, short_description, hint

            if ($desc_changes)
            {
                if ($is_new_language)
                {
                    # There is a slight chance that since calculation of $is_new_language,
                    # a description of this language already was inserted - e.g. by an
                    # erroneously double-submit. To be on the safe side, we recalculate
                    # $is_new_language inside the transaction. Will go quick from DB
                    # cache, if cache_desc table was not changed.

                    $is_new_language = !Db::select_value("
                        select 1 from cache_desc
                        where cache_id = '".$cache_internal_id_escaped."'
                        and language ='".$language_upper_escaped."'
                    ");
                    if (!$is_new_language)
                    {
                        # Probably a duplicate submission - ignore.
                    }
                    else
                    {
                        Db::execute("
                            insert into cache_desc (
                                uuid, node, cache_id, language,
                                `desc`, desc_html, desc_htmledit, hint, short_desc,
                                date_created, last_modified
                            )
                            values (
                                '".Okapi::create_uuid()."',
                                '".Db::escape_string(Settings::get('OC_NODE_ID'))."',
                                '".$cache_internal_id_escaped."',
                                '".$language_upper_escaped."',
                                '".Db::escape_string($description)."',
                                '".Db::escape_string($value_for_desc_html_field)."',
                                '".Db::escape_string(Okapi::get_default_value_for_text_htmledit($request->token->user_id))."',
                                '".Db::escape_string($hint)."',
                                '".Db::escape_string($short_description)."',
                                NOW(),
                                NOW()
                            )
                        ");
                        $desc_internal_id = Db::last_insert_id();
                        Db::execute("
                            insert into okapi_submitted_objects (object_type, object_id, consumer_key)
                            values (
                                ".Okapi::OBJECT_TYPE_CACHE_DESCRIPTION.",
                                '".Db::escape_string($desc_internal_id)."',
                                '".Db::escape_string($request->consumer->key)."'
                            )
                        ");
                        unset($desc_internal_id);
                    }
                }
                else
                {
                    $is_only_language = Db::select_value("
                        select count(*)
                        from cache_desc
                        where cache_id = '".$cache_internal_id_escaped."'
                    ") <= 1;
                    if (!$is_only_language && $description.$short_description.$hint == '')
                    {
                        Db::execute("
                            delete from cache_desc
                            where cache_id = '".$cache_internal_id_escaped."'
                            and language = '".$language_upper_escaped."'
                        ");
                    }
                    else
                    {
                          $desc_change_sqls_escaped = [];
                          if ($description !== null)
                              $desc_change_sqls_escaped[] = "`desc` = '".Db::escape_string($description)."'";
                          if ($short_description !== null)
                              $desc_change_sqls_escaped[] = "short_desc = '".Db::escape_string($short_description)."'";
                          if ($hint !== null)
                              $desc_change_sqls_escaped[] = "hint = '".Db::escape_string($hint)."'";
                          Db::execute("
                              update cache_desc
                              set ".implode(", ", $desc_change_sqls_escaped)."
                              where cache_id = '".$cache_internal_id_escaped."'
                              and language = '".$language_upper_escaped."'
                          ");
                    }
                    unset($is_only_language);
                }
            }

            # attributes

            if ($acodes_to_add || $acodes_to_remove)
            {
                $acode2id = AttrHelper::get_acode_to_internal_id_mapping();
                foreach ($acodes_to_add as $acode) {
                    Db::execute("
                        insert ignore into caches_attributes (cache_id, attrib_id)
                        values (
                            '".$cache_internal_id_escaped."',
                            '".Db::escape_string($acode2id[$acode])."'
                        )
                    ");
                }
                $ids_to_remove_escaped = [];
                foreach ($acodes_to_remove as $acode) {
                    $ids_to_remove_escaped[] = Db::escape_string($acode2id[$acode]);
                }
                if ($ids_to_remove_escaped) {
                    Db::execute("
                        delete from caches_attributes
                        where cache_id = '".$cache_internal_id_escaped."'
                        and attrib_id in ('".implode("','", $ids_to_remove_escaped)."')
                    ");
                }
                unset($ids_to_remove_escaped);
                unset($acode2id);
            }

            # other changes

            if (Settings::get('OC_BRANCH') == 'oc.pl') {
                $change_sqls_escaped[] = "last_modified = now()";
                # OCDE does this via trigger
            }
            if ($change_sqls_escaped) {
                Db::execute("
                    update caches
                    set ".implode(", ", $change_sqls_escaped)."
                    where cache_id = '".$cache_internal_id_escaped."'
                ");
            }

            Db::execute("commit");
        }

        $result = ['success' => count($problems) == 0, 'messages' => $problems];

        return Okapi::formatted_response($request, $result);
    }
}
