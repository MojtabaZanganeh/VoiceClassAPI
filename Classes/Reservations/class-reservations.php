<?php
namespace Classes\Reservations;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Error;
use Classes\Users\Users;
use DateTime;

class Reservations extends Users
{
    use Base, Sanitizer;

}