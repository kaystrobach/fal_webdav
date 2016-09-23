<?php

namespace TYPO3\FalWebdav\Backend;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Kay Strobach
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

class McryptWizard
{
    /**
     * @return bool
     */
    public function isInstalled() {
        return extension_loaded('mcrypt');
    }

    /**
     * @return bool
     */
    public function isNotInstalled() {
        return !$this->isInstalled();
    }

    /**
     * @param array $params
     * @param  $pObj
     * @return string
     */
    public function main($params, $pObj) {

        /** @var IconRegistry $iconRegistry */
        $iconRegistry = GeneralUtility::makeInstance('TYPO3\CMS\Core\Imaging\IconRegistry');

        return '<span title="mcrypt is not installed">'
            . $this->getIcon('status-dialog-warning')
            . '<b>PHP Extension mCrypt is missing, so storing passwords is not supported</b>'
            . '</span>';
    }

    /**
     * @param string $iconName
     * @return string mixed
     */
    protected function getIcon($iconName) {
        $iconRegistry = GeneralUtility::makeInstance(IconRegistry::class);
        if ($iconRegistry->isRegistered($iconName)) {
            $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
            return $iconFactory->getIcon($iconName, Icon::SIZE_DEFAULT)->render();
        }
    }
}