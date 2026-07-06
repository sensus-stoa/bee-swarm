<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * AtomDefinitions — конфигурация атомов (не логика).
 * Вынесено из AtomRegistry для соблюдения SOLID S.
 */
class AtomDefinitions
{
    public const UNARY = [
        'abs','sqrt','sin','cos','tan','asin','acos','atan',
        'sinh','cosh','tanh','exp','log','log10','log1p',
        'floor','ceil','round','deg2rad','rad2deg',
        'sq','cube','inv','neg','sign','relu','not',
    ];

    public const BINARY = [
        'add','sub','mul','div','mod',
        'min','max','hypot','pow','fmod',
        'gt','lt','eq','neq','and','or',
        '+' => 'add', '−' => 'sub', '×' => 'mul', '/' => 'div',
    ];

    /** Функции, исключаемые из алфавита среды (I/O, строки, etc.) */
    public const ENV_SKIP_PREFIXES = [
        'set_','ini_','header','session','ob_','error_report','trigger_error',
        'define','class_','function_','method_','trait_','interface_',
        'stream','socket','curl','exec','proc_','pcntl','posix',
        'mysql','pg_','oci_','odbc','sqlite','pdo','mongo',
        'image','gd_','exif','openssl','hash','password','crypt',
        'xml_encode','xml_decode','simplexml','dom_',
        'mb_','iconv','locale','date_default','timezone',
        'apache','fastcgi','php_ini','zend_','opcache','xdebug',
        'readline','ncurses','newt',
        'print','echo','printf','sprintf','vprintf','vsprintf',
        'var_dump','var_export','print_r','debug_','highlight_',
        'json_encode','json_decode',
    ];
}
