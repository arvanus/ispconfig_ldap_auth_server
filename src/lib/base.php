<?php

namespace ISPLDAP\lib;

class Base {
    /**
   * Get data of returning user by uid or mailaddress
   *
   * @param string $loginName Login Name to check
   * @return ISPDomainUser|bool Found domainuser from database or false
   * @throws \OC\DatabaseException
   */
  public function getUserData($loginName)
  {
    /*list($mailbox, $domain) = array_pad(preg_split('/@/', $loginName), 2, false);
    $stmnt = 'SELECT `uid`, `mailbox`, `domain` FROM `*PREFIX*users_ispconfig`'
        . ' WHERE `uid` = ?';
    if ($mailbox && $domain) {
      $stmnt .= ' OR (`mailbox` = ? AND `domain` = ?)';
      $user = OC_DB::executeAudited($stmnt,
          array($loginName, $mailbox, $domain)
      )->fetchRow();
    } else {
      $user = OC_DB::executeAudited($stmnt,
          array($loginName)
      )->fetchRow();
    }

*/
return false;
    //return $user ? new ISPDomainUser($user['uid'], $user['mailbox'], $user['domain']) : false;
  }

  
  /**
   * Create user record in database
   *
   * @param string $uid The username
   * @param string $displayname Users displayname
   * @param string|bool $quota Amount of quota for new created user or false
   * @param string[]|bool $groups string-array of groups for new created user or false
   *
   * @return void
   * @throws \OC\DatabaseException
   */
  protected function storeUser($uid, $mailbox, $domain, $displayname, $quota = false, $groups = false, $preferences = false)
  {
    /*if (!$this->userExists($uid)) {
      OC_DB::executeAudited(
          'INSERT INTO `*PREFIX*users_ispconfig` ( `uid`, `displayname`, `mailbox`, `domain` )'
          . ' VALUES( ?, ?, ?, ? )',
          array($uid, $displayname, $mailbox, $domain)
      );

      $this->setInitialUserProfile($uid, "$mailbox@$domain", $displayname);
      if ($quota)
        $this->setUserQuota($uid, $quota);
      if ($groups)
        foreach ($groups AS $gid) {
          $this->addUserToGroup($uid, $gid);
        }
      if ($preferences)
        foreach ($preferences AS $app => $options)
          foreach ($options AS $configkey => $value)
            $this->setUserPreference($uid, $app, $configkey, $value);
    }*/
  }
}
