<?php
namespace Classes\Reservations;

use Classes\Base\Base;
use Classes\Base\Sanitizer;
use Classes\Events\Events;
use Classes\Base\Response;
use Classes\Base\Error;
use DateTime;

class Discounts extends Events
{
    use Base, Sanitizer;

}