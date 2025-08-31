<?php
namespace Classes\Courses;

use Classes\Base\Base;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Users\Users;

class Courses extends Users
{
    use Base, Sanitizer;

    private function generate_slug($input, $timestamp = '')
    {
        $output = preg_replace('/[^a-zA-Z0-9\s\-_\x{0600}-\x{06FF}]/u', '', $input);
        $output .= jdate(' d F Y', $timestamp, '', '', 'en');
        $output = preg_replace('/\s+/', '-', $output);
        $output = strtolower($output);
        $output = trim($output, '-');
        return $output;
    }
}