<?php

$newPath = t3lib_extMgm::extPath('fal_webdav') . 'Resources/Php/';
set_include_path($newPath . PATH_SEPARATOR . get_include_path());

?>