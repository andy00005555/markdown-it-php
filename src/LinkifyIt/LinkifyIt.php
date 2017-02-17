<?php
/**
 * Copyright (c) 2015 Vitaly Puzrin.
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 *
 *  @see https://github.com/markdown-it/linkify-it
 */
/**
 * Copyright (c) 2016 Kaoken
 *
 * This software is released under the MIT License.
 * http://opensource.org/licenses/mit-license.php
 *
 *
 *
 * use javascript version 2.0.2
 * @see https://github.com/markdown-it/linkify-it/tree/2.0.3
 */

namespace Kaoken\LinkifyIt;


/**
 * class LinkifyIt
 **/
class LinkifyIt
{
    /**
     * @var Re
     */
    public $re;

    /**
     * @var array
     */
    protected $__opts__;

  // Cache last tested result. Used to skip repeating steps on next `match` call.
    protected $__index__          = -1;
    protected $__last_index__     = -1; // Next scan position
    protected $__schema__         = '';
    protected $__text_cache__     = '';

    /**
     * @var array
     */
    protected $__schemas__ = [];
    /**
     * @var array
     */
    protected $__compiled__ =[];
    /**
     * @var array
     */
    protected $__tlds__           = [];
    protected $__tlds_replaced__  = false;

    // RE pattern for 2-character tlds (autogenerated by ./support/tlds_2char_gen.js)
    protected $tlds_2ch_src_re = 'a[cdefgilmnoqrstuwxz]|b[abdefghijmnorstvwyz]|c[acdfghiklmnoruvwxyz]|d[ejkmoz]|e[cegrstu]|f[ijkmor]|g[abdefghilmnpqrstuwy]|h[kmnrtu]|i[delmnoqrst]|j[emop]|k[eghimnprwyz]|l[abcikrstuvy]|m[acdeghklmnopqrstuvwxyz]|n[acefgilopruz]|om|p[aefghklmnrstwy]|qa|r[eosuw]|s[abcdeghijklmnortuvxyz]|t[cdfghjklmnortvwz]|u[agksyz]|v[aceginu]|w[fs]|y[et]|z[amw]';

    /**
     * Creates new linkifier instance with optional additional schemas.
     * Can be called without `new` keyword for convenience.
     *
     * By default understands:
     *
     * - `http(s)://...` , `ftp://...`, `mailto:...` & `//...` links
     * - "fuzzy" links and emails (example.com, foo@bar.com).
     *
     * `schemas` is an object, where each key/value describes protocol/rule:
     *
     * - __key__ - link prefix (usually, protocol name with `:` at the end, `skype:`
     *   for example). `linkify-it` makes shure that prefix is not preceeded with
     *   alphanumeric char and symbols. Only whitespaces and punctuation allowed.
     * - __value__ - rule to check tail after link prefix
     *   - _String_ - just alias to existing rule
     *   - _Object_
     *     - _validate_ - validator function (should return matched length on success),
     *       or `RegExp`.
     *     - _normalize_ - optional function to normalize text & url of matched result
     *       (for example, for @twitter mentions).
     *
     * `options`:
     *
     * - __fuzzyLink__ - recognige URL-s without `http(s):` prefix. Default `true`.
     * - __fuzzyIP__ - allow IPs in fuzzy links above. Can conflict with some texts
     *   like version numbers. Default `false`.
     * - __fuzzyEmail__ - recognize emails without `mailto:` prefix.
     *
     * LinkifyIt constructor.
     * @param string|array $schemas  Optional. Additional schemas to validate (prefix/validator)
     * @param array $options { fuzzyLink|fuzzyEmail|fuzzyIP: true|false }
     */
    public function __construct($schemas=[], $options=[])
    {
        $def = new Def($this);


        if (empty($options) && is_array($options) ) {
            if ($this->isOptionsObj($schemas, $def)) {
                $options = $schemas;
                $schemas = [];
            }
        }
        // DON'T try to make PRs with changes. Extend TLDs with LinkifyIt.tlds() instead
        $this->__tlds__           = $def->getTldsDefault();
        
        $this->__opts__         = $this->assign([], $def->getOption(), $options);
        $this->__schemas__     = $this->assign([], $def->getSchemas(), $schemas);

        $this->compile();
    }

    /**
     * Add new rule definition. See constructor description for details.
     *
     * @param string        $schema rule name (fixed pattern prefix)
     * @param string|object $definition schema definition
     * @return $this
     */
    public function add($schema, $definition)
    {
        if(is_array($definition)) $definition = (object)$definition;
        $this->__schemas__[$schema] = $definition;
        $this->compile();
        return $this;
    }


    /**
     * Set recognition options for links without schema.
     *
     * @param array $options [fuzzyLink|fuzzyEmail|fuzzyIP=> true|false ]
     * @return $this
     */
    public function set($options)
    {
        $this->__opts__ = $this->assign($this->__opts__, $options);
        return $this;
    }


    /**
     * Searches linkifiable pattern and returns `true` on success or `false` on fail.
     *
     * @param string $text
     * @return bool
     */
    public function test($text) {
        // Reset scan cache
        $this->__text_cache__ = $text;
        $this->__index__      = -1;

        if (strlen ($text) === 0) { return false; }


        // try to scan for link with schema - that's the most simple rule
        if (preg_match_all($this->re->schema_test, $text, $m, PREG_SET_ORDER|PREG_OFFSET_CAPTURE )) {
            $re = $this->re->schema_search;
            for ($i=0, $l=count($m); $i < $l; $i++) {
                $a = &$m[$i];
                $index = $a[2][1];
                $lastIdex = $index+strlen ($a[2][0]);
                $len = $this->testSchemaAt($text, $a[2][0], $lastIdex);
                if ($len) {
                    $this->__schema__     = $a[2][0];
                    $this->__index__      = $index;
                    $this->__last_index__ = $index + strlen ($a[2][0]) + $len;
                    break;
                }
            }
        }

        if ($this->__opts__["fuzzyLink"] && isset($this->__compiled__['http:'])) {
            // guess schemaless links
            if (preg_match($this->re->host_fuzzy_test, $text, $m,PREG_OFFSET_CAPTURE)) {
                // if tld is located after found link - no need to check fuzzy pattern
                if ($this->__index__ < 0 || $m[0][1] < $this->__index__) {
                    if (preg_match($this->__opts__["fuzzyIP"] ? $this->re->link_fuzzy : $this->re->link_no_ip_fuzzy, $text, $ml,PREG_OFFSET_CAPTURE )) {

                        $shift = $ml[0][1] + strlen ($ml[1][0]);

                        if ($this->__index__ < 0 || $shift < $this->__index__) {
                            $this->__schema__     = '';
                            $this->__index__      = $shift;
                            $this->__last_index__ = $ml[0][1] + strlen ($ml[0][0]);
                        }
                    }
                }
            }
        }

        if ($this->__opts__["fuzzyEmail"] && isset($this->__compiled__['mailto:']) ) {
            // guess schemaless emails
            $at_pos = strpos($text, '@');
            if ($at_pos >= 0) {
                // We can't skip this check, because this cases are possible:
                // 192.168.1.1@gmail.com, my.in@example.com
                if (preg_match($this->re->email_fuzzy, $text, $me)) {

                    $i = strpos($text, $me[0]);
                    $shift = $i + strlen ($me[1]);
                    $next  = $i + strlen ($me[0]);

                    if ($this->__index__ < 0 || $shift < $this->__index__ ||
                        ($shift === $this->__index__ && $next > $this->__last_index__)) {
                        $this->__schema__     = 'mailto:';
                        $this->__index__      = $shift;
                        $this->__last_index__ = $next;
                    }
                }
            }
        }

        return $this->__index__ >= 0;
    }


    /**
     * Very quick check, that can give false positives. Returns true if link MAY BE
     * can exists. Can be used for speed optimization, when you need to check that
     * link NOT exists.
     *
     * @param string $text
     * @return boolean
     */
    public function pretest($text)
    {
        // this.re.pretest.test(text);
        return preg_match($this->re->pretest,$text) !== false;
    }


    /**
     * Similar to [[LinkifyIt#test]] but checks only specific protocol tail exactly
     * at given position. Returns length of found pattern (0 on fail).
     * 
     * @param string  $text    text to scan
     * @param string  $schema  rule (schema) name
     * @param integer $pos     text offset to check from
     * @return int
     */
    public function testSchemaAt($text, $schema, $pos)
    {
        // If not supported schema check requested - terminate
        if (!isset($this->__compiled__[strtolower($schema)])) {
            return 0;
        }
        $fn = $this->__compiled__[strtolower($schema)]->validate;
        return $fn($text, $pos);
    }


    /**
     * Returns array of found link descriptions or `null` on fail. We strongly
     * recommend to use [[LinkifyIt#test]] first, for best speed.
     *
     * ##### Result match description
     *
     * - __schema__ - link schema, can be empty for fuzzy links, or `//` for
     *   protocol-neutral  links.
     * - __index__ - offset of matched text
     * - __lastIndex__ - index of next char after mathch end
     * - __raw__ - matched text
     * - __text__ - normalized text
     * - __url__ - link, generated from matched text
     *
     * @param string $text
     * @return array|null
     */
    public function match($text)
    {
        $shift = 0;
        $result = [];

        // Try to take previous element from cache, if .test() called before
        if ($this->__index__ >= 0 && $this->__text_cache__ === $text) {
            $result[] = new Match($this, $shift);
            $shift = $this->__last_index__;
        }

        // Cut head if cache was used
        $tail = $shift !== 0 ? substr($text, $shift) : $text;

        // Scan string until end reached
        while ($this->test($tail)) {
            $result[] = new Match($this, $shift);

            $tail = substr($tail, $this->__last_index__);
            $shift += $this->__last_index__;
        }

        if (count($result) !== 0) {
            return $result;
        }

        return null;
    }


    /**
     * Load (or merge) new tlds list. Those are user for fuzzy links (without prefix)
     * to avoid false positives. By default this algorythm used:
     *
     * - hostname with any 2-letter root zones are ok.
     * - biz|com|edu|gov|net|org|pro|web|xxx|aero|asia|coop|info|museum|name|shop|рф
     *   are ok.
     * - encoded (`xn--...`) root zones are ok.
     *
     * If list is replaced, then exact match for 2-chars root zones will be checked.
     *
     * @param string|array $list    list of tlds
     * @param boolean      $keepOld merge with current list if `true` (`false` by default)
     * @return $this
     */
    public function tlds($list, $keepOld=null)
    {
        $list = is_array($list) ? $list : [ $list ];

        if (!isset($keepOld)) {
            $this->__tlds__ = array_slice($list,0);
            $this->__tlds_replaced__ = true;
            $this->compile();
            return $this;
        }

        $this->__tlds__ = array_merge($this->__tlds__, $list);
        array_unique($this->__tlds__);
        rsort($this->__tlds__);

        $this->compile();
        return $this;
    }

    /**
     * Default normalizer (if schema does not define it's own).
     *
     * @param Match $match
     */
    public function normalize(&$match)
    {
        // Do minimal possible changes by default. Need to collect feedback prior
        // to move forward https://github.com/markdown-it/linkify-it/issues/1

        if (empty($match->schema)) { $match->url = 'http://' . $match->url; }

        if ($match->schema === 'mailto:' && !preg_match("/^mailto:/ui", $match->url)) {
            $match->url = 'mailto:' . $match->url;
        }
    }


    /**
     * LinkifyIt#onCompile()
     *
     * Override to modify basic RegExp-s.
     **/
    public function onCompile() {}

    /**
     * @return integer
     */
    public function getIndex() { return $this->__index__; }
    /**
     * @return integer
     */
    public function getLastIndex() { return $this->__last_index__; }
    /**
     * @return string
     */
    public function getTextCache() { return $this->__text_cache__; }
    /**
     * @return string
     */
    public function getSchema() { return $this->__schema__; }
    /**
     * @param string $schema
     * @param Match $match
     * @return array
     */
    public function normalizeFromCompiled($schema, $match)
    {
        $fn = $this->__compiled__[$schema]->normalize;
        return $fn($match);
    }



    //###############################################################################################################
    //###############################################################################################################
    //##
    //##
    //##
    //###############################################################################################################
    //###############################################################################################################
    ////////////////////////////////////////////////////////////////////////////////
    // Helpers
    protected function assign($obj, ...$args)
    {
        if (is_array($obj)) {
            foreach ($args as &$source) {
                if (!is_array($source)) continue;
                foreach ($source as $key => &$val) {
                    $obj[$key] = $source[$key];
                }
            }
        }
        return $obj;
    }
    ////////////////////////////////////////////////////////////////////////////////


    /**
     * @param array  $option
     * @param object $def
     * @return boolean
     */
    private function isOptionsObj( &$option, &$def )
    {
        return array_reduce(array_keys($option),function ($acc, $k) use($def){
            return $acc || isset($def->getOption()[$k]);
        },false);
    }


    ////////////////////////////////////////////////////////////////////////////////

    private function resetScanCache()
    {
        $this->__index__ = -1;
        $this->__text_cache__   = '';
    }

    private function createValidator($re)
    {
        return function ($text, $pos) use($re) {
            $tail = substr($text, $pos);

            if (preg_match($re, $tail, $match)) {
                return strlen ($match[0]);
            }
            return 0;
        };
    }

    private function createNormalizer()
    {
        return function ($match) {
            $this->normalize($match);
        };
    }

    /**
     * Schemas compiler. Build regexps.
     */
    private function compile()
    {
        $this->re = new Re($this->__opts__);

        // Define dynamic patterns
        $tlds = array_slice($this->__tlds__, 0);

        $this->onCompile();

        if (!$this->__tlds_replaced__) {
            $tlds[] = $this->tlds_2ch_src_re;
        }
        $tlds[] = $this->re->src_xn;

        $this->re->src_tlds = join('|', $tlds);

        $untpl = function(&$tpl){ return preg_replace('/%TLDS%/u', $this->re->src_tlds, $tpl); };

        $this->re->email_fuzzy      = '/' . $untpl($this->re->tpl_email_fuzzy) . '/ui';
        $this->re->link_fuzzy       = '/' . $untpl($this->re->tpl_link_fuzzy) . '/ui';
        $this->re->link_no_ip_fuzzy = '/' . $untpl($this->re->tpl_link_no_ip_fuzzy) . '/ui';
        $this->re->host_fuzzy_test  = '/' . $untpl($this->re->tpl_host_fuzzy_test) . '/ui';

        //
        // Compile each schema
        //

        $aliases = [];
        $this->__compiled__ = []; // Reset compiled data

        $schemaError = function (&$name, &$val) {
            throw new \Exception('(LinkifyIt) Invalid schema "' . $name . '": '. gettype ($val));
        };

        foreach($this->__schemas__ as $name => &$val) {
            if ($val === null) { continue; }

            $compiled = new \stdClass();
            $compiled->validate = null;
            $compiled->link = null;

            $this->__compiled__[$name] = $compiled;

            if (is_object($val)) {
                if( !isset($val->validate)){
                    $schemaError($name, $val);
                } else if (is_string($val->validate)) {
                    $compiled->validate = $this->createValidator($val->validate);
                } else if (is_callable($val->validate)) {
                    $compiled->validate = $val->validate;
                } else {
                    $schemaError($name, $val);
                }

                if (!isset($val->normalize)) {
                    $compiled->normalize = $this->createNormalizer();
                } else if (is_callable($val->normalize)) {
                    $compiled->normalize = $val->normalize;
                } else {
                    $schemaError($name, $val);
                }

                continue;
            }

            if (is_string($val)) {
                $aliases[] = $name;
                continue;
            }

            $schemaError($name, $val);
        }

        //
        // Compile postponed aliases
        //
        foreach($aliases as &$alias){
            if (isset($this->__schemas__[$alias]) && !isset($this->__compiled__[$this->__schemas__[$alias]])) {
                // Silently fail on missed schemas to avoid errons on disable.
                // $schemaError(alias, self.__schemas__[alias]);
                continue;
            }

            $this->__compiled__[$alias]->validate =
                $this->__compiled__[$this->__schemas__[$alias]]->validate;
            $this->__compiled__[$alias]->normalize =
                $this->__compiled__[$this->__schemas__[$alias]]->normalize;
        }

        //
        // Fake record for guessed links
        //
        $fake = new \stdClass();
        $fake->validate = null;
        $fake->normalize = $this->createNormalizer();
        $this->__compiled__[''] = $fake;

        /**
         * Merge objects
         * @param $str
         * @return mixed
         */
        $escapeRE = function($str) {
            return preg_replace("/([\.\?\*\+\^\$\[\]\\\(\)\{\}\|\-\/\^])/u", '\\\\${1}', $str);
        };

        //
        // Build schema condition
        //
        $slist = join('|',
            array_map($escapeRE,
                array_filter(
                    array_keys($this->__compiled__),
                    function ($name) {
                        // Filter disabled & fake schemas
                        return strlen ($name) > 0 && isset($this->__compiled__[$name]);
                    }
                )
            )
        );
//        var slist = Object.keys(self.__compiled__)
//            .filter(function (name) {
//                // Filter disabled & fake schemas
//                return name.length > 0 && self.__compiled__[name];
//            })
//            .map(escapeRE)
//            .join('|');

        // (?!_) cause 1.5x slowdown
        $this->re->schema_test   = "/(^|(?!_)(?:[><\u{ff5c}]|" . $this->re->src_ZPCc . '))(' . $slist . ')/ui';
        $this->re->schema_search = "/(^|(?!_)(?:[><\u{ff5c}]|" . $this->re->src_ZPCc . '))(' . $slist . ')/ui';

        $this->re->pretest       =
            '/((^|(?!_)(?:[><]|' . $this->re->src_ZPCc . '))(' . $slist . '))|' .
            '((^|(?!_)(?:[><]|' . $this->re->src_ZPCc . '))(' . $slist . '))|' .
            '@/ui';

        //
        // Cleanup
        //

        $this->resetScanCache();
    }
}