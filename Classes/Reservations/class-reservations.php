<?php
namespace Classes\Reservations;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Conversations\Conversations;
use Classes\Events\Events;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Base\Error;
use DateTime;

class Reservations extends Events
{
    use Base, Sanitizer;

}