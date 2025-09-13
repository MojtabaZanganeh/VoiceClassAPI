<?php
namespace Classes\Orders;

use Classes\Base\Base;
use Classes\Base\Database;
use Classes\Base\Sanitizer;
use Classes\Base\Response;
use Classes\Base\Error;
use DateTime;

class Discounts extends Orders
{
    use Base, Sanitizer;

}