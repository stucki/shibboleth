<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Irene Höppner <irene.hoeppner@abezet.de>
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
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
 * [CLASS/FUNCTION INDEX of SCRIPT]
 *
 * Hint: use extdeveval to insert/update function index above.
 */

namespace TrustCnct\Shibboleth;

use TrustCnct\Shibboleth\User\UserHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service "Shibboleth Authentication" for the "shibboleth" extension.
 *
 * @author    Irene Höppner <irene.hoeppner@abezet.de>
 * @package    TYPO3
 * @subpackage    tx_shibboleth
 */

class ShibbolethAuthentificationService extends \TYPO3\CMS\Sv\AbstractAuthenticationService {

    var $prefixId = 'ShibbolethAuthentificationService';        // Same as class name
    var $scriptRelPath = 'Classes/ShibbolethAuthentificationService.php';    // Path to this script relative to the extension dir.
    var $extKey = 'shibboleth';    // The extension key.
    var $shibboleth_extConf = ''; // Extension configuration.
    var $envShibPrefix = '';      // If environment variables are prefixed, store prefix here (e.g. REDIRECT_...)
    var $hasShibbolethSession = FALSE;
    var $shibSessionIdKey = '';
    var $shibApplicationIdKey = '';
    var $primaryMode = '';
    var $forbiddenUser = array(
        'uid' => 999999,
        'username' => 'nevernameauserlikethis',
        '_allowUser' => 0
    );

    /**
     * [Put your description here]
     *
     * @return    [type]        ...
     */
    function init() {
        $available = parent::init();

        // Here you can initialize your class.
        
        // The class have to do a strict check if the service is available.
        // The needed external programs are already checked in the parent class.
        
        // If there's no reason for initialization you can remove this function.

        global $TYPO3_CONF_VARS;
        $this->shibboleth_extConf = unserialize($TYPO3_CONF_VARS['EXT']['extConf']['shibboleth']);

        $shortestPrefixLength = 65535;
        foreach ($_SERVER as $serverEnvKey => $serverEnvValue) {
            $posOfShibInKey = strpos($serverEnvKey,'Shib');
            if ($posOfShibInKey !== FALSE && $posOfShibInKey < $shortestPrefixLength) {
                $shortestPrefixLength = $posOfShibInKey;
                $this->envShibPrefix = substr($serverEnvKey, 0, $posOfShibInKey);
                $this->hasShibbolethSession = TRUE;
                $separationChar = substr($serverEnvKey, $posOfShibInKey+4,1);
                $this->shibSessionIdKey = $this->envShibPrefix . 'Shib'.$separationChar.'Session'.$separationChar.'ID';
                $this->shibApplicationIdKey = $this->envShibPrefix . 'Shib'.$separationChar.'Application'.$separationChar.'ID';
            }
        }
        /*
        // Another chance to detect Shibboleth session present; just for safety, as code before not well tested at the moment
        if (!$this->hasShibbolethSession && isset($_SERVER['AUTH_TYPE']) && $_SERVER['AUTH_TYPE'] == 'shibboleth') {
            if (isset($_SERVER['Shib_Session_ID']) && $_SERVER['Shib_Session_ID'] != '') {
                $this->hasShibbolethSession = TRUE;
                $this->envShibPrefix = '';
                $this->shibSessionIdKey = 'Shib_Session_ID';
                $this->shibApplicationIdKey = 'Shib_Application_ID';
            }
        }
        */
        
        return $available;
    }
    
    function getUser() {

        if (($this->primaryMode != '') and ($this->primaryMode != $this->mode)) {
            if($this->writeDevLog) GeneralUtility::devlog('Secondary login of mode '.$this->mode.' detected after registering primary mode'.$this->primaryMode.'. Skipping.','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0);
            return false;
        }

        if ($this->primaryMode == '') {
            $this->primaryMode = $this->mode;
        }

        if ($this->isLoggedInByNonShibboleth()) {
            if($this->writeDevLog) GeneralUtility::devlog('Existing non-Shibboleth session detected (mode '.$this->mode.'). Skipping.','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0);
            return FALSE;
        }

        if (is_object($GLOBALS['TSFE'])) {
            $isAlreadyThere = TRUE;
        }

        if($this->writeDevLog) GeneralUtility::devlog($this->mode.' ($_SERVER)','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,$_SERVER);
        // if($this->writeDevLog) GeneralUtility::devlog('getUser: mode: ' . $this->mode,'\TrustCnct\Shibboleth\ShibbolethAuthentificationService'); // subtype
        // if($this->writeDevLog) GeneralUtility::devlog('getUser: loginType: ' . $this->authInfo['loginType'],'\TrustCnct\Shibboleth\ShibbolethAuthentificationService'); // BE or FE
        // if($this->writeDevLog) GeneralUtility::devlog('getUser: (authInfo)','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,$this->authInfo);
        // if($this->writeDevLog) GeneralUtility::devlog('getUser: (loginData)','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,$this->login);

        if (($this->envShibPrefix) && ($this->writeDevLog))
            GeneralUtility::devLog(
                'Found only prefixed "Shib" environment variables. Will remove prefix "'.$this->envShibPrefix.'"',
                '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                1
            );
        // Without a valid Shibboleth session, bail out here returning FALSE
        if (!$this->applicationHasMatchingShibbolethSession()) {
            if($this->writeDevLog)
                GeneralUtility::devlog(
                    $this->mode . ': no applicable Shibboleth session recognized - see extra data for environment variables',
                    '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                    2,
                    $_SERVER
                );
            if ($this->isLoggedInByShibboleth()) {
                if($this->writeDevLog)
                    GeneralUtility::devlog(
                        $this->mode . ': have a non-matching Shibboleth user logged in - logout! - for session id see extra data',
                        '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                        3,
                        $this->authInfo['userSession']
                    );
                $this->pObj->logoff();
                return $this->forbiddenUser;
            } else {
                return FALSE;
            }
        }

        /** @var UserHandler $userhandler */
        $userhandler = GeneralUtility::makeInstance(UserHandler::class,$this->authInfo['loginType'],
            $this->db_user, $this->db_groups, $this->shibSessionIdKey, $this->writeDevLog, $this->envShibPrefix);

        $user = $userhandler->lookUpShibbolethUserInDatabase();

        if (!is_array($user)) {
                // Got no matching user from DB
            if($user !== false) {
                if($this->writeDevLog)
                    GeneralUtility::devLog(
                        $this->mode.': '.$user.' - see $_SERVER in extra data for original data',
                        '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                        3,
                        $_SERVER
                    );
                return false;
            }
            if (!$this->shibboleth_extConf[$this->authInfo['loginType'].'_autoImport']){
                    // No auto-import for this login type, no user found -> no login possible, don't return a user record.
                if($this->writeDevLog)
                    GeneralUtility::devlog(
                        $this->mode.': User not found in DB and no auto-import configured; will exit',
                        '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                        2,
                        $this->shibboleth_extConf[$this->authInfo['loginType'].'_autoImport']
                    );
                return false;
            }
        }
            // Fetched matching user successfully from DB or auto-import is allowed
            // get some basic user data from shibboleth server-variables
        $user = $userhandler->transferShibbolethAttributesToUserArray($user);
        if (!is_array($user)) {
            if($this->writeDevLog) {
                if($user === false) {
                    $msg = $this->mode . ': Error while calculating user attributes.';
                } else {
                    $msg = $this->mode . ': '. $user;
                }
                $msg = $msg . ' Check $_SERVER (extra data) and config file!';
                GeneralUtility::devlog(
                        $msg,
                        '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                        3,
                        $_SERVER
                    );
            }
            return false;
        }

        if($this->writeDevLog) GeneralUtility::devlog('getUser: offering $user for authentication','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,$user);

        if (!$isAlreadyThere) {
            unset($GLOBALS['TSFE']);
        }

        return $user;
    }

    function authUser(&$user) {
        if($this->writeDevLog) GeneralUtility::devlog('authUser: ($user); Shib-Session-ID: ' . $_SERVER[$this->shibSessionIdKey],'\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,$user);
        
        if($this->writeDevLog) GeneralUtility::devlog('authUser: ($this->authInfo)','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,$this->authInfo);
        
            // If the user comes not from shibboleth getUser, we will ignore it.
        if (!$user['tx_shibboleth_shibbolethsessionid']) {
            if($this->writeDevLog) GeneralUtility::devlog($this->mode.': This user is not for us (not Shibboleth). Exiting.','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0);
            return 100;
        }

            // For safety: Check for existing Shibboleth-Session and return FALSE, otherwise!
        if (!$this->applicationHasMatchingShibbolethSession()) {
            // With no Shibboleth session we won't authenticate anyone!
            if($this->writeDevLog) GeneralUtility::devlog('authUser: Found no Shib-Session-ID: rejecting','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',4,array($_SERVER[$this->shibSessionIdKey]));
            return FALSE;
        }

        // Check, if we have an already logged in TYPO3 user.
        if (is_array($this->authInfo['userSession'])) {
                // Some user is already logged in to TYPO3, check if it is a Shibboleth user 
            if (!$this->authInfo['userSession']['tx_shibboleth_shibbolethsessionid']) {
                    // The presently logged in user is not a shibboleth user - neutral answer
                if($this->writeDevLog) GeneralUtility::devlog('authUser: Found a logged in non-Shibboleth user - no decision','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,array($_SERVER[$this->shibSessionIdKey]));
                return 100;
            }

                // The logged in user is a Shibboleth user, and we have a Shib-Session-ID. However, we are paranoic and check, if we still have the same user.
            if ($user['username'] == $this->authInfo['userSession']['username']) {
                    // Shibboleth user name still the same.
                if($this->writeDevLog) GeneralUtility::devlog('authUser: Found our previous Shibboleth user: authenticated','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',-1);
                return 200;
            } else {
                if($this->writeDevLog) GeneralUtility::devlog('authUser: Shibboleth user changed from "'.$this->authInfo['userSession']['username'].'" to "'.$user['username'].'": reject','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',3,array($_SERVER[$this->shibSessionIdKey]));
                $this->logoffPresentUser();
                return false;
            }
            
        }
        
            // This user is not yet logged in
        if (is_array($user) && $user['_allowUser']) {
            unset ($user['_allowUser']);
                // Before we return our positiv result, we have to update/insert the user in DB
            $userhandler = GeneralUtility::makeInstance(UserHandler::class,$this->authInfo['loginType'],
                $this->db_user, $this->db_groups, $this->shibSessionIdKey, $this->writeDevLog, $this->envShibPrefix);
                // We now can auto-import; we won't be in authUser, if getUser didn't detect auto-import configuration.
            $user['uid'] = $userhandler->synchronizeUserData($user);
            if($this->writeDevLog) GeneralUtility::devlog('authUser: after insert/update DB $uid=' . $user['uid'] . '; ($user attached).','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,$user);

            if ($user[$this->db_user['username_column']] == '') {
                if($this->writeDevLog)
                    GeneralUtility::devlog(
                        $this->mode.': Username is empty string. Never do this!',
                        '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                        3,
                        $this->shibboleth_extConf[$this->authInfo['loginType'].'_autoImport']
                    );
                $this->logoffPresentUser();
                return FALSE;
            }

            if ((! $user['disable']) AND ($user['uid']>0)) {
                if ($this->writeDevLog) GeneralUtility::devLog('authUser: user authenticated','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',-1,$user);
                return 200;
            }
            if (defined('TYPO3_MODE') AND (TYPO3_MODE == 'BE') AND ($user['disable'])) {
                if ($this->writeDevLog) GeneralUtility::devLog('authUser: user created/exists, but is in state "disable"','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',2,$user);
                if ($this->shibboleth_extConf['BE_disabledUserRedirectUrl']) {
                    $redirectUrl = $this->shibboleth_extConf['BE_disabledUserRedirectUrl'];
                    if ($this->writeDevLog) {
                        GeneralUtility::devLog('authUser: redirecting to '. $redirectUrl,'\TrustCnct\Shibboleth\ShibbolethAuthentificationService');
                    }
                    // initiate Redirect here
                    header("Location: $redirectUrl");
                    exit;

                }
            }
        }
        
        if($this->writeDevLog) GeneralUtility::devlog('authUser: Refusing auth','\TrustCnct\Shibboleth\ShibbolethAuthentificationService',0,$user);
        return false; // To be safe: Default access is no access.
    }

    /**
     * @return bool
     */
    private function isLoggedInByShibboleth()
    {
        return is_array($this->authInfo['userSession']) && $this->authInfo['userSession']['tx_shibboleth_shibbolethsessionid'];
    }

    /**
     * @return bool
     */
    private function isLoggedInByNonShibboleth()
    {
        return is_array($this->authInfo['userSession']) && !$this->authInfo['userSession']['tx_shibboleth_shibbolethsessionid'];
    }

    /**
     * @return bool
     */
    private function applicationHasMatchingShibbolethSession()
    {
        if (!$this->hasShibbolethSession) {
            return false;
        }
        if (($this->shibboleth_extConf[$this->authInfo['loginType'].'_applicationID'] != '') &&
            ($this->shibboleth_extConf[$this->authInfo['loginType'].'_applicationID'] != $_SERVER[$this->shibApplicationIdKey])) {
            if($this->writeDevLog)
                GeneralUtility::devlog(
                    $this->mode . ': Shibboleth session appliation ID ' . $_SERVER[$this->shibApplicationIdKey] .
                    ' does not match required '. $this->authInfo['loginType'].'_applicationID (' .
                    $this->shibboleth_extConf[$this->authInfo['loginType'].'_applicationID'].
                    ') - see extra data for environment variables',
                    '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                    2,
                    $_SERVER);
            return false;
        }
        return true;
    }

    private function logoffPresentUser() {
        if($this->writeDevLog)
            GeneralUtility::devlog(
                $this->mode . ': have a non-matching Shibboleth user logged in - logout! - for session id see extra data',
                '\TrustCnct\Shibboleth\ShibbolethAuthentificationService',
                0,
                $this->authInfo['userSession']
            );
        $this->pObj->logoff();
    }

}



if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/shibboleth/Classes/ShibbolethAuthentificationService.php'])    {
    include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/shibboleth/Classes/ShibbolethAuthentificationService.php']);
}

?>
