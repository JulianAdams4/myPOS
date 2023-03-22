<?php

namespace App\Helpers\PrintService;

// @codingStandardsIgnoreLine
abstract class Command
{
    const PRINT_TEXT = 1;
    const PRINT_IMAGE = 2;
    const ALIGN = 3;
    const LINE_SPACING = 4;
    const PRINT_MODE = 5;
    const CUT = 6;
    const PULSE = 7;
    const FEED = 8;
    const PRINT_TEXT_JUSTIFY = 9;
    const JAVA_PRINT_MODE = 10;
}