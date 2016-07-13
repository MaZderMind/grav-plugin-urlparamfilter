<?php
namespace Grav\Plugin;

use Grav\Common\Grav;

class UrlparamfilterTwigExtension extends \Twig_Extension
{
    /**
     * Returns extension name.
     *
     * @return string
     */
    public function getName()
    {
        return 'UrlparamfilterTwigExtension';
    }

    /**
     * Return a list of all filters.
     *
     * @return array
     */
    public function getFilters()
    {
        return [
            new \Twig_SimpleFilter('query', [$this, 'queryFilter']),
            new \Twig_SimpleFilter('param', [$this, 'paramFilter']),
            new \Twig_SimpleFilter('fragment', [$this, 'fragmentFilter']),
        ];
    }

    /**
     * Split a given (complete or partial) URL into pieces, also parsing out
     * query-String Arguments and Grav-Uri-Parameters.
     *
     * @param string $url complete or partial URL
     *
     * @return array Array with the Keys returned by parse_url plus ['query']
     *               being splitted and the path-sections referring to Grav-Uri-
     *               Parameters splitted into ['params'].
     *               Pieces not present in the Url will be missing.
     */
    private function splitUrl($url)
    {
        $grav = Grav::instance();
        $config = $grav['config'];

        // basic url parsing
        $pieces = parse_url($url);

        // split query-string if present
        if(isset($pieces['query']) && is_string($pieces['query']))
        {
            $query = [];
            parse_str($pieces['query'], $query);
            $pieces['query'] = $query;
        }

        // shave of Grav-Uri Params if present
        list($path, $params) = $this->splitParams($pieces['path'], $config->get('system.param_sep'));
        if(count($params) > 0)
        {
            $pieces['path'] = $path;
            $pieces['params'] = $params;
        }

        return $pieces;
    }

    /**
     * Shave of params from this URL, supports any valid delimiter.
     * Modified version of https://github.com/getgrav/grav/blob/daebf05/system/src/Grav/Common/Uri.php#L349
     *
     * @param        $uri
     * @param string $delimiter
     *
     * @return array Array with the shorened path as [0] and the Parameter-Set
     *               as [1], nicely usable within `list($path, $params) = …`
     */
    private function splitParams($uri, $delimiter = ':')
    {
        $params = [];

        if (strpos($uri, $delimiter) !== false) {
            $bits = explode('/', $uri);
            $path = [];
            foreach ($bits as $bit) {
                if (strpos($bit, $delimiter) !== false) {
                    $param = explode($delimiter, $bit);
                    if (count($param) == 2) {
                        $plain_var = filter_var(rawurldecode($param[1]), FILTER_SANITIZE_STRING);
                        $params[$param[0]] = $plain_var;
                    }
                } else {
                    $path[] = $bit;
                }
            }
            $uri = '/' . ltrim(implode('/', $path), '/');
        }

        return [$uri, $params];
    }

    /**
     * Assemble an URL from the pieces returned by splitUrl.
     *
     * @param   array $pieces URL-Pieces as returned by splitUrl.
     *
     * @return string Assembled URL
     */
    private function assembleUrl($pieces)
    {
        $grav = Grav::instance();
        $config = $grav['config'];
        $param_sep = $config->get('system.param_sep');

        if(isset($pieces['query']) && is_array($pieces['query']))
        {
            $pieces['query'] = http_build_query($pieces['query']);
        }

        if(isset($pieces['params']) && is_array($pieces['params']))
        {
            $pieces['params'] = implode('/', array_map(function($k, $v) use($param_sep) {
                return rawurlencode($k).$param_sep.rawurlencode($v);
            }, array_keys($pieces['params']), array_values($pieces['params'])));
        }

        $scheme   = isset($pieces['scheme']) ? $pieces['scheme'] . '://' : ''; 
        $host     = isset($pieces['host']) ? $pieces['host'] : ''; 
        $port     = isset($pieces['port']) ? ':' . $pieces['port'] : ''; 
        $user     = isset($pieces['user']) ? $pieces['user'] : ''; 
        $pass     = isset($pieces['pass']) ? ':' . $pieces['pass']  : ''; 
        $pass     = ($user || $pass) ? "$pass@" : ''; 
        $path     = isset($pieces['path']) ? $pieces['path'] : ''; 
        $params   = isset($pieces['params']) ? '/' . $pieces['params'] : ''; 
        $query    = isset($pieces['query']) ? '?' . $pieces['query'] : ''; 
        $fragment = isset($pieces['fragment']) ? '#' . $pieces['fragment'] : ''; 
        return implode('', [$scheme, $user, $pass, $host, $port, $path, $params, $query, $fragment]);
    }



    /**
     * Twig-Filter function `query`.
     * Actual action depends on the supplied number and types of parameters:
     *
     * ### query()
     * Returns a Dictionary with all Query-Parameters
     *
     * `{{ '/some/url?foo=bar&moo=quu' | query() | dump }}` => `array('foo' => 'bar', 'moo' => 'quu')`
     *
     * ### query(str)
     * Returns the Value of the Specified Query-Parameter
     *
     * `{{ '/some/url?foo=bar&moo=quu' | query('moo') | dump }}` => `'quu'`
     *
     * ### query(str, str)
     * Appends the Specified Key and Value to the URL
     *
     * `{{ '/some/url?foo=bar' | query('moo', 'quu') | dump }}` => `'/some/url?foo=bar&moo=quu'`
     * `{{ '/some/url?foo=bar' | query('moo', '') | dump }}` => `'/some/url?foo=bar&moo='`
     *
     * ### query(str, false)
     * Removes the Specified Key and Value from the URL
     *
     * `{{ '/some/url?foo=bar&moo=quu' | query('moo', 'false') | dump }}` => `'/some/url?foo=bar'`
     *
     * ### query(dict)
     * Appends the Specified Key-Value-Pairs to the URL
     *
     * `{{ '/some/url' | query({'foo': 'bar', 'moo': 'quu'}) | dump }}` => `'/some/url?foo=bar&moo=quu'`
     *
     * @param  string $url URL the Filter has been applied to.
     * @param  string|null $a First Parameter to the filter
     * @param  string|null $b Second Parameter to the filter
     *
     * @return string Filtered URL
     */
    public function queryFilter($url, $a=null, $b=null)
    {
        $pieces = $this->splitUrl($url);

        // read out query-arg $a from $url
        if(is_null($b) && is_string($a))
        {
            if(isset($pieces['query'][$a]))
            {
                return $pieces['query'][$a];
            }
            else
            {
                return null;
            }
        }

        // apply [$a => $b] to $url
        else if(is_string($b) && is_string($a))
        {
            if(!isset($pieces['query']))
            {
                $pieces['query'] = [$a => $b];
            }
            else
            {
                $pieces['query'][$a] = $b;
            }
            return $this->assembleUrl($pieces);
        }

        // remove key $a from $url
        else if($b === false && is_string($a))
        {
            if(isset($pieces['query']))
            {
                unset($pieces['query'][$a]);
            }
            return $this->assembleUrl($pieces);
        }

        // apply array $a to $url
        else if(is_null($b) && is_array($a))
        {
            if(!isset($pieces['query']))
            {
                $pieces['query'] = $a;
            }
            else
            {
                $pieces['query'] = array_merge($pieces['query'], $a);
            }
            return $this->assembleUrl($pieces);
        }

        // return all query params
        else if(is_null($b) && is_null($a))
        {
            return $pieces['query'];
        }

        // unknown filter arguments
        else
        {
            return $url;
        }
    }

    /**
     * Twig-Filter function `param`.
     * Actual action depends on the supplied number and types of parameters:
     *
     * ### param()
     * Returns a Dictionary with all Query-Parameters
     *
     * `{{ '/some/url/foo:bar/moo:quu' | param() | dump }}` => `array('foo' => 'bar', 'moo' => 'quu')`
     *
     * ### param(str)
     * Returns the Value of the Specified Query-Parameter
     *
     * `{{ '/some/url/foo:bar/moo=quu' | param('moo') | dump }}` => `'quu'`
     *
     * ### param(str, str)
     * Appends the Specified Key and Value to the URL
     *
     * `{{ '/some/url/foo:bar' | param('moo', 'quu') | dump }}` => `'/some/url/foo:bar/moo:quu'`
     * `{{ '/some/url/foo:bar' | param('moo', '') | dump }}` => `'/some/url/foo:bar/moo:'`
     *
     * ### param(str, false)
     * Removes the Specified Key and Value from the URL
     *
     * `{{ '/some/url/foo:bar' | param('moo', false) | dump }}` => `'/some/url/foo:bar'`
     *
     * ### param(dict)
     * Appends the Specified Key-Value-Pairs to the URL
     *
     * `{{ '/some/url' | param({'foo': 'bar', 'moo': 'quu'}) | dump }}` => `'/some/url/foo:bar/moo:quu'`
     *
     * @param  string $url URL the Filter has been applied to.
     * @param  string|null $a First Parameter to the filter
     * @param  string|null $b Second Parameter to the filter
     *
     * @return string Filtered URL
     */
    public function paramFilter($url, $a=null, $b=null)
    {
        $pieces = $this->splitUrl($url);

        // read out params-arg $a from $url
        if(is_null($b) && is_string($a))
        {
            if(isset($pieces['params'][$a]))
            {
                return $pieces['params'][$a];
            }
            else
            {
                return null;
            }
        }

        // apply [$a => $b] to $url
        else if(is_string($b) && is_string($a))
        {
            if(!isset($pieces['params']))
            {
                $pieces['params'] = [$a => $b];
            }
            else
            {
                $pieces['params'][$a] = $b;
            }
            return $this->assembleUrl($pieces);
        }

        // remove key $a from $url
        else if($b === false && is_string($a))
        {
            if(isset($pieces['params']))
            {
                unset($pieces['params'][$a]);
            }
            return $this->assembleUrl($pieces);
        }

        // apply array $a to $url
        else if(is_null($b) && is_array($a))
        {
            if(!isset($pieces['params']))
            {
                $pieces['params'] = $a;
            }
            else
            {
                $pieces['params'] = array_merge($pieces['params'], $a);
            }
            return $this->assembleUrl($pieces);
        }

        // return all params
        else if(is_null($b) && is_null($a))
        {
            return $pieces['params'];
        }

        // unknown filter arguments
        else
        {
            return $url;
        }
    }

    /**
     * Twig-Filter function `fragment`.
     * Actual action depends on the supplied number and types of parameters:
     *
     * ### fragment()
     * Returns the Fragment-Text
     * 
     * `{{ '/some/url#moo' | fragment() | dump }}` => `'moo'`
     * 
     * ### fragment(str)
     * Set the specified Fragment
     * 
     * `{{ '/some/url' | fragment('moo') | dump }}` => `'/some/url#moo'`
     *
     * @param  string $url URL the Filter has been applied to.
     * @param  string|null $a First Parameter to the filter
     * @param  string|null $b Second Parameter to the filter
     *
     * @return string Filtered URL
     */
    public function fragmentFilter($url, $a=null)
    {
        $pieces = $this->splitUrl($url);

        // return fragment string
        if(is_null($a))
        {
            return $pieces['fragment'];
        }

        // update fragment string
        else if(is_string($a))
        {
            $pieces['fragment'] = $a;
            return $this->assembleUrl($pieces);
        }

        // unknown filter arguments
        else
        {
            return $url;
        }
    }
}
