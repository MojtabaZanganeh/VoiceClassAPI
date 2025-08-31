<?php
namespace Classes\Teachers;

use Classes\Base\Base;
use Classes\Base\Error;
use Classes\Base\Response;
use Classes\Base\Sanitizer;
use Classes\Events\Categories;
use Classes\Users\Users;

class Teachers extends Users
{
    use Base, Sanitizer;
    
}
