<?php

namespace Acquia\Cli\CloudApi;

use AcquiaCloudApi\Connector\ConnectorInterface;
use GuzzleHttp\Client as GuzzleClient;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Webmozart\PathUtil\Path;

/**
 * Class RefreshConnector
 */
class RefreshTokenConnector implements ConnectorInterface {

  /**
   * @var string The base URI for Acquia Cloud API.
   */
  protected $baseUri;

  /**
   * @var GenericProvider The OAuth 2.0 provider to use in communication.
   */
  protected $provider;

  /**
   * @var GuzzleClient The client used to make HTTP requests to the API.
   */
  protected $client;

  /**
   * @var AccessTokenInterface The generated OAuth 2.0 access token.
   */
  protected $accessToken;

  /**
   * @var mixed|string
   */
  protected $refreshToken;

  /**
   * @var \Symfony\Component\Cache\Adapter\FilesystemAdapter
   */
  private $cache;

  /**
   * @inheritdoc
   */
  public function __construct(array $config, string $base_uri = NULL) {
    $this->baseUri = ConnectorInterface::BASE_URI;
    if ($base_uri) {
      $this->baseUri = $base_uri;
    }
    $cache_directory = sprintf('%s%s%s', Path::getHomeDirectory(), \DIRECTORY_SEPARATOR, '.acquia-php-sdk-v2');
    $this->cache = new FilesystemAdapter('cache', 300, $cache_directory);

    $this->refreshToken = $config['refreshToken'];
    $this->provider = new GenericProvider([
      'urlAuthorize' => '',
      'urlAccessToken' => self::URL_ACCESS_TOKEN,
      'urlResourceOwnerDetails' => '',
    ]);

    $this->client = new GuzzleClient();
  }

  /**
   * @return string
   */
  public function getBaseUri(): string {
    return $this->baseUri;
  }

  /**
   * @inheritdoc
   */
  public function createRequest($verb, $path) {
    if (!isset($this->accessToken) || $this->accessToken->hasExpired()) {
      $accessToken = $this->cache->get('cloudapi-token', function () {
        return $this->provider->getAccessToken('refresh_token', [
          'refresh_token' => $this->refreshToken,
        ]);
      });

      $this->accessToken = $accessToken;
    }

    return $this->provider->getAuthenticatedRequest(
      $verb,
      $this->baseUri . $path,
      $this->accessToken
    );
  }

  /**
   * @inheritdoc
   */
  public function sendRequest($verb, $path, $options) {
    $request = $this->createRequest($verb, $path);
    return $this->client->send($request, $options);
  }

  /**
   * @param \League\OAuth2\Client\Provider\GenericProvider $provider
   */
  public function setProvider(GenericProvider $provider): void {
    $this->provider = $provider;
  }

  /**
   * @param \GuzzleHttp\Client $client
   */
  public function setClient(GuzzleClient $client): void {
    $this->client = $client;
  }

  /**
   * @param \Symfony\Contracts\Cache\CacheInterface $cache
   */
  public function setCache(CacheInterface $cache): void {
    $this->cache = $cache;
  }

}
