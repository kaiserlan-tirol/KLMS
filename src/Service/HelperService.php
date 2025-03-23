<?php

namespace App\Service;

use DateTime;

class HelperService
{
    // define the input and output formats
    final public const DATE_INPUT_FORMAT = 'd.m.Y H:i';

    public function toShortDate($inputString, $outputFormat = 'd.m.Y'): string
    {
        // Create a DateTime object from the input string
        $dateTime = DateTime::createFromFormat(HelperService::DATE_INPUT_FORMAT, $inputString);
    
        if ($dateTime instanceof DateTime) {
            // Format the DateTime object to output in the desired format
            return $dateTime->format($outputFormat);
        } else {
            // Handle invalid input format
            return "Invalid input format";
        }
    }
}
