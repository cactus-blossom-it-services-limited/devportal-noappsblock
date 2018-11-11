<?php

/********************************************************* {COPYRIGHT-TOP} ***
 * Licensed Materials - Property of IBM
 * 5725-L30, 5725-Z22
 *
 * (C) Copyright IBM Corporation 2018
 *
 * All Rights Reserved.
 * US Government Users Restricted Rights - Use, duplication or disclosure
 * restricted by GSA ADP Schedule Contract with IBM Corp.
 ********************************************************** {COPYRIGHT-END} **/

namespace Drupal\auth_apic\Service\Mocks;

use Drupal\Core\State\State;
use Drupal\externalauth\ExternalAuthInterface;
use Drupal\externalauth\AuthmapInterface;

use Drupal\auth_apic\UserManagerResponse;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\user\Entity\User;

use Drupal\auth_apic\Service\Interfaces\UserManagerInterface;
use Drupal\ibm_apim\Service\Interfaces\ManagementServerInterface;

use Drupal\auth_apic\JWTToken;
use Drupal\ibm_apim\ApicType\ApicUser;

/**
 * Mock of the ApicUserManager service.
 *
 * This mock doesn't call out to the management node so that we can run our
 * tests against a standalone portal appliance.
 */
class MockUserManager implements UserManagerInterface {

  /**
   * Used to include the externalauth service from the external_auth module.
   *
   * @var \Drupal\externalauth\ExternalAuthInterface
   */
  protected $externalAuth;

  /**
   * An auth map service object.
   *
   * @var \Drupal\externalauth\AuthmapInterface
   */
  protected $authMap;

  /**
   * Temp store for session data.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $sessionStore;

  /**
   * Management server.
   *
   * @var \Drupal\ibm_apim\Service\ManagementServerInterface
   */
  protected $mgmtServer;
  protected $state;

  protected $provider = 'auth_apic';

  /**
   * ApicUserManager constructor.
   *
   * @param \Drupal\externalauth\ExternalAuthInterface $externalAuth
   *   The external auth interface.
   * @param \Drupal\externalauth\AuthmapInterface $authMap
   *   The auth map interface.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   Tempstore factory interface for session data..
   * @param \Drupal\ibm_apim\Service\ManagementServerInterface $mgmtInterface
   *   Management server interface.
   */
  public function __construct(ExternalAuthInterface $externalAuth, AuthmapInterface $authMap, PrivateTempStoreFactory $tempStoreFactory, ManagementServerInterface $mgmtInterface, State $state) {
    $this->externalAuth = $externalAuth;
    $this->authMap = $authMap;
    $this->sessionStore = $tempStoreFactory->get('ibm_apim');
    $this->mgmtServer = $mgmtInterface;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function registerInvitedUser(JWTToken $token, ApicUser $invitedUser = NULL) {
    // TODO: Implement registerInvitedUser() method.
    \Drupal::logger("apictest")->error("Implementation of MockUserManager::registerInvitedUser() is missing!");
  }

  /**
   * @inheritDoc
   */
  public function userManagedSignUp(ApicUser $new_user) {
    $mgmtResponse = $this->mgmtServer->postSignUp($new_user);
    $userManagerResponse = new UserManagerResponse();

    if($mgmtResponse == NULL) {
      $userManagerResponse = NULL;
    }
    else if ($mgmtResponse->getCode() === 204) {
      $this->registerApicUser($new_user->getUsername(), \Drupal::service('ibm_apim.apicuser')->getUserAccountFields($new_user));

      $userManagerResponse->setSuccess(TRUE);

      \Drupal::logger("apictest")->notice('sign-up processed for %username', [
        '%username' => $new_user->getUsername(),
      ]);

      $userManagerResponse->setMessage(t('Your account was created successfully. You will receive an email with activation instructions.'));
      $userManagerResponse->setRedirect('<front>');
    }

    return $userManagerResponse;
  }

  /**
   * @inheritDoc
   */
  public function nonUserManagedSignUp(ApicUser $new_user) {
    // TODO: Implement nonUserManagedSignUp() method.
    \Drupal::logger("apictest")->error("Implementation of MockUserManager::nonUserManagedSignUp() is missing!");
  }


  /**
   * @inheritDoc
   */
  public function login(ApicUser $user) {
    // check for authcode first, is so then we are an oidc user - so mock out test responses.
    if ($authcode = $user->getAuthcode()) {
      return $this->oidcLogin($user);
    }

    drupal_set_message('MOCKED: MockUserManager->login()');
    // otherwise we are a non-oidc user.
    $password = $user->getPassword();
    $username = $user->getUsername();

    $umResponse = new UserManagerResponse();

    // If password is 'invalidPassword', return 0 as the user id.
    if ($password == 'invalidPassword') {
      $umResponse->setSuccess(FALSE);
      $umResponse->setUid(0);
      return $umResponse;
    }

    // Search the user database for the matching user.
    $ids = \Drupal::entityQuery('user')->execute();
    $users = User::loadMultiple($ids);

    // Return the id of the user if the user account is found.
    foreach ($users as $user) {
      if ($user->getUsername() == $username) {
        user_login_finalize($user);
        $umResponse->setSuccess(TRUE);
        $umResponse->setUid($user->id());

        if ($user->id() != 1) {
          \Drupal::service('ibm_apim.user_utils')->setCurrentConsumerorg();
          \Drupal::service('ibm_apim.user_utils')->setOrgSessionData();
        }

        return $umResponse;
      }
    }

    // Return an invalid user id if the user doesn't exist.
    $umResponse->setSuccess(FALSE);
    $umResponse->setUid(0);
    return $umResponse;
  }

  private function oidcLogin(ApicUser $user) {
    $response = new UserManagerResponse();

    switch ($user->getAuthCode()) {
      case "validauthcode":
        $response->setSuccess(TRUE);
        break;
      case "failwithmessage":
        $response->setSuccess(FALSE);
        $response->setMessage("Mocked error message from apim login() call");
        break;
      case "fail":
        $response->setSuccess(FALSE);
        break;
    }

    return $response;
  }

  /**
   * @inheritDoc
   */
  public function registerApicUser($username = NULL, array $fields) {
    if (function_exists('ibm_apim_entry_trace')) {
      ibm_apim_entry_trace(__CLASS__ . '::' . __FUNCTION__, $username);
    }
    $returnValue = NULL;
    try {

      // The code inside this if statement isn't valid in the unit test environment where we have no Drupal instance
      if (!isset($GLOBALS['__PHPUNIT_BOOTSTRAP']) && \Drupal::hasContainer()) {

        // Check if the account already exists before creating it
        // This supports the ibmsocial_login case where users are created in drupal before
        // we register them with the mgmt appliance (this is out of our control)
        $ids = \Drupal::entityQuery('user')->execute();
        $users = User::loadMultiple($ids);

        foreach ($users as $user) {
          if ($user->getUsername() == $username) {
            // Ensure that there is an authmap entry for the user. There isn't if the account was created by OIDC.
            $this->externalAuth->linkExistingAccount($username, $this->provider, $user);
            $returnValue = $user;
          }
        }
      }
      if ($returnValue == NULL) {
        $account = $this->externalAuth->register($username, $this->provider, $fields);
        $returnValue = $account;
      }
    } catch (ExternalAuthRegisterException $e) {
      throw $e;
    }

    if (function_exists('ibm_apim_exit_trace')) {
      ibm_apim_exit_trace(__CLASS__ . '::' . __FUNCTION__, NULL);
    }
    return $returnValue;
  }

  /**
   * @inheritDoc
   */
  public function updateApicAccount(ApicUser $user) {
    return $this->updateLocalAccount($user);
  }

  /**
   * @inheritDoc
   */
  public function updateLocalAccount(ApicUser $user) {
    // Update the user directly in drupal db
    $ids = \Drupal::entityQuery('user')->execute();
    $users = User::loadMultiple($ids);

    foreach ($users as $dbuser) {
      if ($dbuser->getUsername() === $user->getUsername()) {
        $dbuser->set("first_name", $user->getFirstname());
        $dbuser->set("last_name", $user->getLastname());
        $dbuser->set("mail", $user->getMail());
        $dbuser->save();
        break;
      }
    }

    drupal_set_message("MOCKED SERVICE:: Your account has been updated.");
  }


  /**
   * @inheritDoc
   */
  public function updateLocalAccountRoles(ApicUser $user, $roles) {
    // Update the user directly in drupal db
    $ids = \Drupal::entityQuery('user')->execute();
    $users = User::loadMultiple($ids);

    foreach ($users as $dbuser) {
      if ($dbuser->getUsername() === $user->getUsername()) {
        // Splat all of the old roles
        $existingRoles = $dbuser->getRoles();
        foreach ($existingRoles as $role) {
          $dbuser->removeRole($role);
        }

        // Add all of the new roles
        unset($roles['authenticated']);          // This isn't a 'proper' role so remove it
        foreach ($roles as $role) {
          if ($role != 'authenticated') {
            $dbuser->addRole($role);
          }
        }
      }
    }
  }

  public function resetPassword(JWTToken $obj, $password) {

    drupal_set_message("MOCKED SERVICE:: In resetPassword, returned:" . $obj->getOrg() . ":" . $obj->getEnv());
    if ($obj->getOrg() === 'testorg' && $obj->getEnv() === 'testcatalog') {
      drupal_set_message("MOCKED SERVICE:: In resetPassword return 200");
      return 200;
    }
  }

  /**
   * @inheritDoc
   */
  public function changePassword($username, $old_password, $new_password) {
    if ($old_password === 'thisiswrong') {
      // In the real form we rely on the message we get back from the management server to inform the user that the
      // pw is incorrect, we'll put the message out ourselves.
      drupal_set_message('MOCKED SERVICE:: The old password is incorrect');
      return FALSE;
    }
    elseif ($new_password === 'thisisinvalid') {
      drupal_set_message('MOCKED SERVICE:: Password must contain characters from 3 of the 4 following categories: 1. upper-case, 2. lower-case, 3. numeric, and 4. punctuation (for example, !, $, #, %)');
      return FALSE;
    }
    return TRUE;
  }

  public function deleteLocalAccount(ApicUser $user) {
    return NULL;
  }

  public function acceptInvite(JWTToken $token, ApicUser $acceptingUser) {
    return NULL;
  }

  public function findUserInDatabase($username) {
    return NULL;
  }

  public function findUserByUrl($url) {
    return NULL;
  }

  public function deleteUser() {
    return NULL;
  }
}
