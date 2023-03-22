<?php
if (! function_exists('spacesBetween')) {
    /**
     * Format number
     *
     * @param $value
     * @param $attribute
     * @param $data
     * @return boolean
     */
    function spacesBetween($stringLength, $maxLengthLine)
    {
        $excessLength = $maxLengthLine - $stringLength;
        $spaces = "";
        if ($excessLength > 0) {
            for ($i = 0; $i < $excessLength; $i++) {
                $spaces = $spaces . "&nbsp;";
            }
        }
        return $spaces;
    }
}
