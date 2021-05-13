<?php

namespace Acquia\Cli\CloudApi;

use League\OAuth2\Client\Token\AccessToken;

/**
 * Class Connector.
 */
class Connector extends \AcquiaCloudApi\Connector\Connector {

  /**
   * @inheritdoc
   */
  public function createRequest($verb, $path) {
    if (getenv('ACLI_ACCESS_TOKEN')) {
      $this->accessToken = new AccessToken([
        'access_token' => getenv('ACLI_ACCESS_TOKEN'),
      ]);
    }

    return parent::createRequest($verb, $path);
  }

}
