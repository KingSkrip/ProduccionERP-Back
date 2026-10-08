<?php

if (! function_exists('tb')) {
    /**
     * tb('CLIE') => CLIE03 (o CLIE04 cuando cambies el .env)
     */
    function tb(string $base): string
    {
        return $base . config('firebird.company');
    }
}