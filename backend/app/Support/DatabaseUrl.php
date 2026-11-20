<?php

namespace App\Support;

class DatabaseUrl
{
    /**
     * Remove a URL-level timezone option so the explicit connection timezone
     * cannot be replaced by ConfigurationUrlParser query options.
     */
    public static function withoutTimezoneOverride(mixed $url): mixed
    {
        if (! is_string($url) || $url === '') {
            return $url;
        }

        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $fragmentStart = strpos($url, '#', $queryStart);
        $queryEnd = $fragmentStart === false ? strlen($url) : $fragmentStart;
        $queryString = substr($url, $queryStart + 1, $queryEnd - $queryStart - 1);
        parse_str($queryString, $options);

        if (! array_key_exists('timezone', $options)) {
            return $url;
        }

        unset($options['timezone']);

        $base = substr($url, 0, $queryStart);
        $fragment = $fragmentStart === false ? '' : substr($url, $fragmentStart);
        $remainingQuery = http_build_query($options, '', '&', PHP_QUERY_RFC3986);

        return $base.($remainingQuery === '' ? '' : '?'.$remainingQuery).$fragment;
    }
}
