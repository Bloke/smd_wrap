<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_wrap';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.3.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Conditionally transform and wrap stuff with tags and labels';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_wrap
 *
 * A Textpattern CMS plugin for wrapping content with HTML tags, labels and attributes.
 *  -> Adds wraptag / class / html_id / label support round any tag
 *  -> Permits a range of formatting options for manipulating the item
 *     (e.g. trim, escape, sanitize, change case, linkify, format date,
 *     strip tags, split / combine, process with textile, etc).
 *  -> If the content is empty, nothing is displayed.
 *  -> Supports <txp:else />
 *
 * @author Stef Dawson
 * @link   https://stefdawson.com/
 * @todo
 * * Security: https://forum.textpattern.com/viewtopic.php?pid=255560#p255560
 * * Allow hidden pref to determine default transforms?
 * * Conditionals: https://forum.textpattern.com/viewtopic.php?pid=256465#p256465
 */

if (class_exists('\Textpattern\Tag\Registry')) {
    Txp::get('\Textpattern\Tag\Registry')
        ->register('smd_wrap')
        ->register('smd_wrap_all')
        ->register('smd_wrap_info');
}

/**
 * Wrap content with stuff and perform optional transforms on it.
 *
 * @param  array  $atts  Tag attribute name-value pairs
 * @param  string $thing Contained content
 * @return string
 */
function smd_wrap($atts, $thing = NULL)
{
    global $smd_wrap_data, $prefs, $yield;

    extract(lAtts(array(
        'data_mode'   => 'txp_tags', // txp_tags, all
        'item'        => '',
        'wraptag'     => '',
        'class'       => '',
        'html_id'     => '',
        'label'       => '',
        'labeltag'    => '',
        'attr'        => '',
        'prefix'      => '',
        'suffix'      => '',
        'format'      => '', // convenience only: same as transform
        'transform'   => '',
        'form'         => '',
        'delim'       => ',',
        'param_delim' => '|',
        'trim'        => 1,
        'debug'       => 0,
    ), $atts));

    // item attribute trumps container
    $data = ($item) ? $item : ((empty($form)) ? $thing : fetch_form($form));
    $has_yield = ($form && $thing);
    $out = '';
    $tag_mode = ($data_mode == 'txp_tags');

    if ($format) {
        trigger_error("smd_wrap: format attribute deprecated: use transform attribute instead.", E_USER_NOTICE);
        $transform = $format;
    }

    // Grab the true portion of any container
    $truePart = EvalElse($data, 1);

    if ($debug) {
        echo '++ TO WRAP ++';
        dmp($data);
    }

    if ($data) {
        // Handle custom attributes
        if ($attr) {
            $custom_atts = array();
            $attribs = do_list($attr);

            foreach($attribs as $attdef) {
                list($key, $val) = do_list($attdef, $param_delim);
                $custom_atts[] = $key . '="' . $val . '"';
            }

            $attr = ' ' . join(' ', $custom_atts);
        }

        if ($tag_mode) {
            // Regex to match Txp tags, stolen from the parser
            $tagz = '@(</?txp:\w+(?:\s+\w+\s*=\s*(?:"(?:[^"]|"")*"|\'(?:[^\']|\'\')*\'|[^\s\'"/>]+))*\s*/?'.chr(62).')@s';

            // Strip Txp tags from the content and jot down the remaining content length
            $size_without_txp_tags = mb_strlen(preg_replace($tagz, '', $truePart));
        }

        // Run the Txp parser, taking into account any txp:yield
        if ($has_yield) {
            $input = $trim ? trim($thing) : $thing;
            $yield[] = parse($input);
        }

        $out = parse($truePart);
        $has_data = false;

        if ($tag_mode) {
            $size_after_parsing = mb_strlen($out);
            $has_data = ($size_after_parsing > $size_without_txp_tags);
        }

        if ($has_yield) {
            array_pop($yield);
        }

        $out = $trim ? trim($out) : $out;

        switch ($data_mode) {
            case 'all':
                $has_content = $out;
                break;
            case 'txp_tags':
            default:
                $has_content = $has_data;
                break;
        }

        if ($has_content) {
            // Top and tail the output
            $out = $prefix.$out.$suffix;

            // Reformat the item with any of the following transformations, in the supplied order
            if ($transform) {
                if ($debug) {
                    echo '++ BEFORE TRANSFORM ++';
                    dmp($out);
                }
                // Use explode because do_list() kills trailing spaces which may be part of a replace transform
                $formats = explode($delim, $transform);

                foreach ($formats as $xformlist) {
                    // Use explode() again, as above
                    $xform = explode($param_delim, $xformlist);
                    $xtype = ltrim(array_shift($xform)); // ltrim required to get rid of leading space in comma-separated transform chains

                    switch ($xtype) {
                        case 'add':
                            $pos = array_shift($xform);
                            $val = $xform[0];
                            $out = (($pos == 'before' || $pos == 'both') ? $val : '') . $out . (($pos == 'after' || $pos == 'both') ? $val : '');
                            break;
                        case 'case':
                            foreach ($xform as $arg) {
                                if ($arg == "upper") {
                                    $out = mb_strtoupper($out);
                                } elseif ($arg == "lower") {
                                    $out = mb_strtolower($out);
                                } elseif ($arg == "ucfirst") {
                                    $out = mb_ucfirst($out);
                                } elseif ($arg == "ucwords") {
                                    $out = mb_convert_case($out, MB_CASE_TITLE);
                                } elseif ($arg == "title") {
                                    $out = smd_wrap_title_case($out);
                                }
                            }

                            break;
                        case 'currency':
                            $fmt = (isset($xform[0])) ? $xform[0] : '%.2n';

                            if (isset($xform[1])) {
                                $lcl = setlocale(LC_MONETARY, "0"); // Preserve current monetary locale
                                setlocale(LC_MONETARY, $xform[2]);
                            }

                            $out = money_format($fmt, (float)$out);

                            if (isset($xform[1])) {
                                // Restore locale
                                setlocale(LC_MONETARY, $lcl);
                            }

                            break;
                        case 'cut':
                            $truncated = false;

                            foreach ($xform as $arg) {
                                // Split the argument into numeric and alpha parts [c]hars, [w]ords.
                                // If it matches at least one of them it's a number of items to split at (default: chars)
                                // If it matches none of them then it's a continuation char sequence (e.g. &hellip;)
                                preg_match('/(\d+)([a-zA-Z])?/', $arg, $cmatch);
                                if ($cmatch) {
                                    $items = isset($cmatch[1]) ? $cmatch[1] : '0';
                                    $plier = isset($cmatch[2]) ? $cmatch[2] : 'c';

                                    switch ($plier) {
                                        case 'w':
                                            // Crude split: will probably break under UTF-8 strings
                                            $words = preg_split("/[\s]+/", $out);
                                            if ((count($words) > $items) && ($items > 0)) {
                                                $out = implode(' ', array_slice($words, 0, $items));
                                                $truncated = true;
                                            }

                                            break;
                                        case 'c':
                                            $chars = mb_strlen($out);

                                            if (($chars > $items) && ($items > 0)) {
                                                $out = mb_substr($out, 0, $items);
                                                $truncated = true;
                                            }
                                        default:
                                            break;
                                    }
                                } elseif ($truncated) {
                                    // Tack on the continuation string
                                    $out .= $arg;
                                }
                            }

                            break;
                        case 'date':
                        case 'time':
                            $nd = (is_numeric($out)) ? $out : strtotime($out);

                            if ($nd !== false) {
                                $out = safe_strftime($xform[0], $nd);
                            }

                            break;
                        case 'escape':
                            $flags = 0;
                            foreach ($xform as $arg) {
                                switch ($arg) {
                                    case 'no_quotes':
                                        $flags |= ENT_NOQUOTES;
                                    break;
                                    case 'all_quotes':
                                        $flags |= ENT_QUOTES;
                                    break;
                                    case 'double_quotes':
                                        $flags |= ENT_COMPAT;
                                    break;
                                    default:
                                        $flags |= $arg;
                                    break;
                                }
                            }
                            $out = htmlspecialchars($out, $flags);

                            break;
                        case 'fordb':
                            $out = doSlash($out);

                            break;
                        case 'form':
                            foreach ($xform as $arg) {
                                $content = fetch_form($arg);
                                $smd_wrap_data = $out;
                                $reps = array(
                                    '{smd_wrap_it}' => $out,
                                );
                                $out = parse(strtr($content, $reps));
                            }

                            break;
                        case 'link':
                            // From http://web.archive.org/web/20120211121539/codesnippets.joyent.com/posts/show/2104
                            $pat = "@\b(https?://)?(([0-9a-zA-Z_!~*'().&=+$%-]+:)?[0-9a-zA-Z_!~*'().&=+$%-]+\@)?(([0-9]{1,3}\.){3}[0-9]{1,3}|([0-9a-zA-Z_!~*'()-]+\.)*([0-9a-zA-Z][0-9a-zA-Z-]{0,61})?[0-9a-zA-Z]\.[a-zA-Z]{2,6})(:[0-9]{1,4})?((/[0-9a-zA-Z_!~*'().;?:\@&=+$,%#-]+)*/?)@";
                            $text = (isset($xform[0]) && $xform[0] != '') ? $xform[0] : '$0';
                            $out = preg_replace($pat, '<a href="$0">'.$text.'</a>', $out);

                            break;
                        case 'no_widow':
                            $no_widow = isset($xform[0]) ? $xform[0] : @$prefs['title_no_widow'];
                            $out = ($no_widow) ? noWidow($out) : $out;

                            break;
                        case 'number':
                            $lcl = NULL;

                            if ( isset($xform[0]) && !is_numeric($xform[0]) ) {
                                // Locale first, so preserve the current one
                                $lcl = setlocale(LC_NUMERIC, "0");
                                setlocale(LC_NUMERIC, $xform[0]);
                                array_shift($xform);
                            }

                            if (isset($xform[0])) {
                                $lcnv = localeconv();
                                $dec = $xform[0];
                                $dec_point = (isset($xform[1])) ? $xform[1] : $lcnv['decimal_point'];
                                $thou_sep = (isset($xform[2])) ? $xform[2] : $lcnv['thousands_sep'];
                                $out = number_format((float)$out, $dec, $dec_point, $thou_sep);
                            } else {
                                $out = number_format((float)$out);
                            }

                            if ($lcl) {
                                // Restore locale
                                setlocale(LC_NUMERIC, $lcl);
                            }

                            break;
                        case 'replace':
                            $type = $xform[0] ? $xform[0] : 'string'; // string / regex
                            $from = $xform[1];
                            $to = isset($xform[2]) ? $xform[2] : '';
                            $out = ($type=='regex') ? preg_replace($from, $to, $out) : str_replace($from, $to, $out);

                            break;
                        case 'round':
                            if (isset($xform[0])) {
                                if ($xform[0] == "down") {
                                    $out = floor((float)$out);
                                } elseif ($xform[0] == "up") {
                                    $out = ceil((float)$out);
                                } elseif ($xform[0] == "positive") {
                                    $out = abs($out);
                                } else {
                                    // Assume any second arg is the precision
                                    $out = round((float)$out, (int)$xform[0]);
                                }
                            } else {
                                $out = round((float)$out);
                            }

                            break;
                        case 'sanitize':
                            if ($xform[0] == "url") {
                                $out = sanitizeForUrl($out);
                            } elseif ($xform[0] == "file") {
                                $out = sanitizeForFile($out);
                            } elseif ($xform[0] == "url_title") {
                                $out = stripSpace($out, 1);
                            } elseif ($xform[0] == "encode") {
                                $out = urlencode($out);
                            } elseif ($xform[0] == "raw") {
                                $out = rawurlencode($out);
                            }

                            break;
                        case 'split':
                            $parts = explode($xform[0], $out);
                            array_shift($xform); // Throw away the split character
                            $joinchar = array_shift($xform);

                            // Grab the specified parts to return
                            $retstr = array();
                            $numParts = count($parts);

                            foreach ($xform as $idx) {
                                $addit = true;

                                if ($idx == 'all') {
                                    $retstr = array_merge($retstr, $parts);
                                    $addit = false;
                                } elseif ($idx == 'last') {
                                    $idx = $numParts;
                                } elseif ($idx == 'rand') {
                                    $idx = mt_rand(1, $numParts);
                                } elseif (mb_strpos($idx, '-') === 0) {
                                    // Negative offset: count from the end: -1 = last, -2 = penultimate, etc
                                    // The +1 is to counter the fact we subtract one in a moment. Damn zero indices
                                    $idx = $numParts - mb_substr($idx, 1) + 1;
                                } elseif (mb_strpos($idx, '>') === 0) {
                                    $retstr = array_merge($retstr, array_slice($parts, mb_substr($idx, 1)));
                                    $addit = false;
                                } elseif (mb_strpos($idx, '<') === 0) {
                                    $retstr = array_merge($retstr, array_slice($parts, 0, mb_substr($idx, 1) - 1 ));
                                    $addit = false;
                                }

                                // Subtract one because the input is 'human'
                                // e.g. split|.|+|1|3 == return the 1st and 3rd parts == $parts[0] + $parts[2]
                                if ($addit) {
                                    $retstr[] = $parts[$idx-1];
                                }
                            }

                            $out = join($joinchar, $retstr);

                            break;
                        case 'strip_tags':
                            $out = strip_tags($out);

                            break;
                        case 'textile':
                            $textile = new \Textpattern\Textile\Parser();
                            $out = $textile->parse($out);

                            break;
                        case 'trim':
                            $charlist = isset($xform[0]) ? $xform[0] : '';
                            $out = ($charlist) ? trim($out, $charlist) : trim($out);

                            break;
                    }

                    if ($debug) {
                        echo '++ AFTER ' . $xtype . ' ++';
                        dmp($out);
                    }
                }
            }
        } else {
            return parse(EvalElse($data, 0));
        }
    }

    return ($out) ? doLabel($label, $labeltag).doTag($out, $wraptag, $class, $attr, $html_id) : '';
}

/**
 * Convenience to emulate v0.1.0 functionality.
 *
 * @param  array  $atts  Tag attribute name-value pairs
 * @param  string $thing Contained content
 * @return string
 */
function smd_wrap_all($atts, $thing = null)
{
    $atts['data_mode'] = 'all';

    return smd_wrap($atts, $thing);
}

/**
 * Display the contents of the previous output in chained transform="form".
 *
 * @param  array  $atts  Tag attribute name-value pairs
 * @param  string $thing Contained content
 * @return string
 */ 
function smd_wrap_info($atts, $thing = null)
{
    global $smd_wrap_data;

    extract(lAtts(array(
        'debug'   => 0,
    ), $atts));

    if ($debug) {
        echo '++ AVAILABLE WRAP INFO ++';
        dmp($smd_wrap_data);
    }

    return $smd_wrap_data;
}

/**
 * Fake ucfirst() for multi-byte strings.
 */
if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst($str)
    {
        return mb_strtoupper(mb_substr($str, 0, 1)) . mb_substr($str, 1);
    }
}

/**
 * Perform a more intelligent multibyte title case.
 *
 * original Title Case script © John Gruber <daringfireball.net>
 * javascript port © David Gouch <individed.com>
 * PHP port of the above by Kroc Camen <camendesign.com>
 * From http://camendesign.co.uk/code/title-case
 *
 * @param  string $title The text to convert to title case
 */
function smd_wrap_title_case($title)
{
    // Remove HTML, storing it for later
    //        HTML elements to ignore    | tags  | entities
    $regx = '/<(code|var)[^>]*>.*?<\/\1>|<[^>]+>|&\S+;/';
    preg_match_all($regx, $title, $html, PREG_OFFSET_CAPTURE);
    $title = preg_replace($regx, '', $title);

    // Find each word (including punctuation attached).
    preg_match_all('/[\w\p{L}&`\'‘’"“\.@:\/\{\(\[<>_]+-? */u', $title, $m1, PREG_OFFSET_CAPTURE);
    $smalls = get_pref('smd_wrap_small_words', 'a(nd?|s|t)?|b(ut|y)|en|for|i[fn]|o[fnr]|t(he|o)|vs?\.?|via');
    $smallre = '/^(' . $smalls . ')[ \-]/i';

    foreach ($m1[0] as &$m2) {
        // Shorthand these- "match" and "index"
        list($m, $i) = $m2;

        // Correct offsets for multi-byte characters (`PREG_OFFSET_CAPTURE` returns *byte*-offset).
        // Fix this by recounting the text before the offset using multi-byte aware `strlen`.
        $i = mb_strlen(substr($title, 0, $i), 'UTF-8');

        // Find words that should always be lowercase
        // (never on the first word, and never if preceded by a colon).
        $m = ($i > 0) && mb_substr($title, max(0, $i-2), 1, 'UTF-8') !== ':' &&
            !preg_match('/[\x{2014}\x{2013}] ?/u', mb_substr($title, max(0, $i-2), 2, 'UTF-8')) &&
             preg_match($smallre, $m)
        ?   //... and convert them to lowercase.
            mb_strtolower($m, 'UTF-8')

        // Else: brackets and other wrappers.
        : ( preg_match('/[\'"_{(\[‘“]/u', mb_substr($title, max(0, $i-1), 3, 'UTF-8'))
        ?   // Convert first letter within wrapper to uppercase.
            mb_substr($m, 0, 1, 'UTF-8').
            mb_strtoupper(mb_substr($m, 1, 1, 'UTF-8'), 'UTF-8').
            mb_substr($m, 2, mb_strlen($m, 'UTF-8')-2, 'UTF-8')

        // Else: do not uppercase these cases.
        : ( preg_match('/[\])}]/', mb_substr($title, max(0, $i-1), 3, 'UTF-8')) ||
            preg_match('/[A-Z]+|&|\w+[._]\w+/u', mb_substr($m, 1, mb_strlen($m, 'UTF-8')-1, 'UTF-8'))
        ?   $m
            // If all else fails, then no more fringe-cases: uppercase the word.
        :   mb_strtoupper(mb_substr($m, 0, 1, 'UTF-8'), 'UTF-8').
            mb_substr($m, 1, mb_strlen($m, 'UTF-8'), 'UTF-8')
        ));

        // Resplice the title with the change (substr_replace() is not multi-byte aware).
        $title = mb_substr($title, 0, $i, 'UTF-8').$m.
             mb_substr($title, $i + mb_strlen($m, 'UTF-8'), mb_strlen($title, 'UTF-8'), 'UTF-8');
    }

    // Restore the HTML.
    foreach ($html[0] as &$tag) {
        $title = substr_replace($title, $tag[0], $tag[1], 0);
    }

    return $title;
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_wrap / smd_wrap_all

Wrap text with HTML, class, prefix, suffix, and apply tranforms to it in the process (linkify, format as date, trim, escape, sanitize, etc).

If the Textpattern tags inside the item you are intending to wrap returns nothing then no wrap is applied and nothing is displayed. This makes it an ideal candidate for placing around other tags and saves having to do the "assign content to @<txp:variable>@, check if variable exists" dance. @<txp:else />@ is supported so you may take action if the contained content is empty.

If you want the plugin to wrap your content if there is _any_ content in its form/container (e.g. if you are using it to wrap around a @{replacement}@ tag or something) then use @<txp:smd_wrap_all />@ instead.

h2. Installation / uninstallation

Download the plugin from either "GitHub":https://github.com/Bloke/smd_wrap/releases, or the "software page":https://stefdawson.com/sw, paste the code into the TXP _Admin->Plugins_ pane, install and enable the plugin.

To uninstall the plugin, simply delete the plugin from the _Admin->Plugins_ page

Visit the "forum thread":https://forum.textpattern.com/viewtopic.php?id=37167 for more info or to report on the success or otherwise of the plugin.

h2. Tags: @<txp:smd_wrap>@ / @<txp:smd_wrap_all>@

Wrap the @item@ or tag's container with standard Txp @wraptag@ paraphernalia. The content is parsed so you can include other Txp tags. If the results of the parse return nothing, the plugin does nothing, though you may fire an optional @<txp:else />@ if you wish inside your container. The following attributes configure the plugin:

; *item*
: The thing to wrap. Can be text, a tag-in-tag or some @{replacement}@ from other smd_ plugins. If not specifed, the container is used as input unless @form@ is given. If you use this attribute you should probably self-close @<txp:smd_wrap />@.
; *form*
: An alternative to @item@ for specifying the thing to wrap. If not used, and no @item@ is given, the container is used as input.
: If you employ a container as well, you can use @<txp:yield />@ inside your @form@ to plug the contained content in. Any contained content is trimmed (if @trim="1"@) and parsed before insertion.
; *wraptag*
: Wrap this HTML tag around the item. Specify it without angle brackets, e.g. @wraptag="span"@
; *class*
: Assign this CSS class to the wraptag. Unused if @wraptag@ is empty.
; *html_id*
: Assign this HTML @id@ value to the wraptag. Unused if @wraptag@ is empty.
; *attr*
: Supply your own name=value pairs to add to the wraptag. For example, @attr="rel|external, title|My useful popup title"@ would add @rel="external" title="My useful popup title"@ as attributes to the wraptag.
; *label*
: Put this text as a heading / label before the item.
; *labeltag*
: Wrap the label with this HTML tag. Specify without angle brackets, e.g. @labeltag="h4"@
; *trim*
: Strip leading and trailing whitespace from the item _before_ it has prefix and suffix applied. Use @trim="0"@ to turn this feature off.
: Default: 1
; *prefix*
: Put this text before the item. It is prepended _prior_ to the item being passed through any @transform@ chain.
; *suffix*
: Put this text after the item. It is appended _prior_ to the item being passed through any @transform@ chain.
; *transform*
: Apply transformations to the item. Transforms can be chained and are applied in order, each one separated by @delim@. Transform configuration parameters are separated by @param_delim@. The following transforms are defined (see the examples below for more information) :
:: *add* -- append some text to the item. This is _not_ the same as using prefix / suffix because the @add@ transform can be inserted into the chain at any point. Use the second argument to determine in which position to add the new text; the text itself is the third argument. Here are the positional options:
::: *before* -- add the text at the start of the item.
::: *after* -- add the text at the end of the item.
::: *both* -- add the text at both start and end of the item.
:: *case* -- change the case of the item. You may chain case transforms in sequence, e.g. @transform="case|lower|ucfirst"@. Choose from:
::: *upper* -- upper case.
::: *lower* -- lower case.
::: *ucfirst* -- upper case first character of sentence.
::: *ucwords* -- upper case first character of every word.
::: *title* -- intelligent(ish) version of ucwords that ignores small words and tries to leave intentional first-lower-case words (e.g. iPhone) alone. Works best on English but does support Unicode up to a point. You can specify your own list of words by creating a hidden pref called *smd_wrap_small_words*. List each word separated by a pipe. You may use regex strings if you wish but _no sanitization is done_ so be careful! The default list of ignored short words are: @a|an|and|as|at|but|by|en|for|if|in|of|on|or|the|to|vs?[.]?|via@
:: *currency* -- format as a monetary value. Optional parameters:
::: format string as defined in PHP's "money_format":https://uk.php.net/manual/en/function.money-format.php. If not set, @%.2n@ is used
::: locale string. Current locale used if not set
:: *cut* -- chop the item at the given character/word limit, providing it is longer than the passed value. Note that if the item contains HTML tags then the output is likely to be spurious (wrong count, or with unclosed tags) so it's best to employ @strip_tags@ first. See "example 7":#smd_wrap_eg7. Arguments can be of the following form:
::: _some_number_ (plus optional @c@): chop at the given number of characters
::: _some_number_ (plus @w@): chop at the given number of words
::: _some_string_: the continuation character(s) to add to the end of the item if it has been truncated (e.g. @...@ or @&hellip;@)
:: *date* or *time* -- treat the item as a date and/or time, formatting it according to the "strftime()":https://php.net/manual/en/function.strftime.php compatible string given as the first argument. The item can either be a numeric (UNIX) timestamp or an _English_ date string in an acceptable format. For datetime fields you could split the string first just to get the date portion.
:: *escape* -- run the item through @htmlspecialchars()@. Use additional parameters to list optional "flags":https://php.net/manual/en/function.htmlspecialchars.php or use any of the following special values:
::: *no_quotes* -- @ENT_NOQUOTES@
::: *double_quotes* -- @ENT_COMPAT@
::: *all_quotes* -- @ENT_QUOTES@
:: *fordb* -- run the item through Txp's @doSlash()@. Always consider using this if you are posting the item back to the database (beware that other plugins may do this for you, so be careful not to duplicate it or you'll get backslashes where there shouldn't be backslashes).
:: *form* -- pass the item through the nominated forms. Specify each form as an argument, and inside those forms place @<txp:smd_wrap_info />@ where you wish the item to be inserted. You may also use @{smd_wrap_it}@ but this may not be as secure as using the tag.
:: *link* -- turn the item into a clickable URL anchor. Without an argument, the link text will be the link itself. If you specify an argument, the link text will be set to whatever you choose.
:: *no_widow* -- whether to allow the last word of the item to word-wrap to the next line. If you specify 0 as the argument, word-wrap will be allowed. Specifying 1 means the last word will never be allowed to wrap on its own. Without an argument, the Advanced Pref determines the behaviour.
:: *number* -- treat the item as numeric. The first parameter is optional and specifies the locale to assume (default: current locale). After that, three further optional parameters may be supplied:
::: 1: decimal places
::: 2: decimal point character
::: 3: thousands separator character
:: *replace* -- find a string or regex and replace it with something else. Additional parameters are:
::: 1: _type_: either @string@ (or omitted) for a regular replace, or @regex@ for a regular expression
::: 2: _from_: the string to search for. If using a regex, you MUST specify the regular expression delimiters as well or you'll get an error
::: 3: _to_: the text with which to replace any found strings. If omited, the found strings will simply be removed
:: *round* -- treat the item as a number and round it to the nearest whole number or positive value. One optional argument can either be:
::: *down* -- round down
::: *up* -- round up
::: *positive* -- ignore sign
::: _some number_ -- the decimal precision to round the value to
:: *sanitize* -- clean the item either for:
::: *url* -- address bar use.
::: *file* -- filename use.
::: *url_title* -- use as a URL title.
::: *encode* -- percent-encoded URL use
::: *raw* -- raw percent-encoded URL use compliant with "RFC 3986":http://www.faqs.org/rfcs/rfc3986.html
:: *split* -- split the item at the given character(s) and return one or more parts, optionally joined by the given characters. Parameters:
::: 1: split character(s)
::: 2: join character(s)
::: 3+: which pieces to return, each separated by @param_delim@. You can either use numeric indices to specify discrete parts, e.g. @1|4@ returns the first and 4th parts. You can specify negative numbers to get parts starting from the end, e.g. @-1|-2@ returns the last and penultimate elements. @last@ is a synonym for @-1@, @all@ returns every piece, and @rand@ chooses one piece at random. In addition you can also use greater-than and less-than symbols before a value to only return elements  higher or lower than the given value, e.g. @>2@ returns the 3rd, 4th, 5th,... elements. See "example 3":#smd_wrap_eg3 for details.
:: *strip_tags* -- remove all HTML / PHP tags from the item.
:: *textile* -- pass the item through the Textile processor.
:: *trim* -- remove leading and trailing characters from the item. Specify a list of characters as the argument. Without an argument, whitespace will be removed.
; *delim*
: The plugin delimiter.
: Default: comma (,)
; *param_delim*
: The argument delimiter.
: Default: pipe (|)
; *debug*
: Display the item the tag is trying to wrap.

h2(#smd_wrap_eg1). Example 1: Link anchor

bc. <txp:variable name="docs" value="https://docs.textpattern.com/" />
<txp:smd_wrap wraptag="div" class="external"
     transform="link|Textpattern documentation">
   <txp:variable name="wiki" />
</txp:smd_wrap>

Returns:

bc. <a href="https://docs.textpattern.com/">Textpattern documentation</a>

h2(#smd_wrap_eg2). Example 2: Date formatting

Notice that because we're using a comma in the formatted string, the @delim@ needs to be altered to some other (in this case arbitrary) string:

bc. <txp:smd_wrap delim="@"
     transform="date|%A, %d %B, %Y %l:%M%p">
2011-9-12 05:30:00
</txp:smd_wrap>

Returns: @Monday, 12 September, 2011 5:30AM@

h2(#smd_wrap_eg3). Example 3: Split and join

h3. 3a: Get a filename extension

Notice no join character is specified in the third argument (although it doesn't hurt to include one; it won't be used in this case):

bc. <txp:smd_wrap transform="split|.||last"
     item="/path/to/some/really.big.file.name.jpg" />

Returns: @jpg@

h3. 3b: Get the file path and filename extension

Separated by colon (@last@ is the same as @-1@) :

bc. <txp:smd_wrap transform="split|.|:|1|-1">
/path/to/some/really.big.file.name.jpg
</txp:smd_wrap>

Returns: @/path/to/some/really:jpg@

h3. 3c: Split the date portion of a combined datetime

bc. <txp:smd_wrap delim="@"
     transform="split| ||1 @ date|%A, %d %B, %Y">
2011-9-12 05:30:00
</txp:smd_wrap>

Returns: @Monday, 12 September, 2011@

h3. 3d: Make an HTML list from some other markup system

bc. <txp:smd_wrap wraptag="ul"
     transform="split|#|</li><li>|>1, add|before|<li>, add|after|</li>">
# First
# Second
# Third
# Fourth
</txp:smd_wrap>

Returns:

bc. <ul>
   <li> First</li>
   <li> Second</li>
   <li> Third</li>
   <li> Fourth</li>
</ul>

A couple of things to note about this example:

* The @add@ transform is used instead of prefix / suffix. This is because the latter pair become part of the item/container and are then split with the @transform@ attribute. In this case we need to add the opening and closing tags afterwards.
* Splitting at '#' renders an empty element before the "First" entry, so the transform is instructed to return items @>1@.

h2(#smd_wrap_eg4). Example 4: with smd_query {replacements}

Wrap the custom_6 value with a div and sanitize it for use in a URL. If custom_6 is empty, nothing would be displayed.

bc. <txp:smd_query query="SELECT * FROM wherever">
   <txp:smd_wrap item="{custom_6}" wraptag="div"
     transform="sanitize|url" />
</txp:smd_query>

If you wanted to trap the condition when custom_6 might be empty:

bc. <txp:smd_query query="SELECT * FROM wherever">
   <txp:smd_wrap wraptag="div" transform="sanitize|url">
      {custom_6}
   <txp:else />
      <p>Sorry, custom_6 is empty</p>
   </txp:smd_wrap>
</txp:smd_query>

h2(#smd_wrap_eg5). Example 5: find / replace

bc. <txp:smd_wrap
     transform="replace||fox|badger">
Farewell my fox, it's been fun!
</txp:smd_wrap>

Returns: @Farewell my badger, it's been fun!@

Note that the 2nd parameter ('type') has been omitted. You could specify 'string' here if you wished.

h2(#smd_wrap_eg6). Example 6: form + container + yield

If pulling data from the user table from, say, smd_query you could reformat it:

bc. <txp:smd_wrap form="wrap_me"
     transform="replace||agent_hunt|Ethan Hunt">
{author}, is to explain the use of smd_wrap
</txp:smd_wrap>

where @wrap_me@ contains:

bc. Your mission, <txp:yield />.
This message will self-destruct in 5 seconds.

That will:

* stuff the @{author}@ into the container from the surrounding plugin's replacement tag
* inject the container into the form where the yield tag is
* run the transform so if the author was agent_hunt the entire message would read: "Your mission, Ethan Hunt, is to explain the use of smd_wrap. This message will self-destruct in 5 seconds."

h2(#smd_wrap_eg7). Example 7: truncate to excerpt

Useful for excerpting data:

bc. <txp:smd_wrap_all
     transform="strip_tags, cut|180|25w|&hellip;">
Bacon ipsum dolor sit amet shoulder drumstick boudin
frankfurter. Andouille shoulder pastrami, rump cow
sausage ribeye shankle swine. Tail meatball cow pork.
Rump ham salami meatloaf sirloin beef ribs.
</txp:smd_wrap_all>

That will write out the string and cut it first at 180 characters, then make sure there are no more than 25 words in it. Thus it will end at: @Rump ham salami…@

If you omitted the @25w@ or your chosen word count was higher than the number of remaining words you would see the following: @Rump ham salami meatlo…@

h2. Author / credits

Written by "Stef Dawson":https://stefdawson.com/contact. Thanks to the jakob for making a business case for creating this plugin, and of course the adi_wrap plugin for inspiration.
# --- END PLUGIN HELP ---
-->
<?php
}
?>
