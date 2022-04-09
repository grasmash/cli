<?php

namespace Acquia\Cli\Command\Api;

use Acquia\Cli\Command\CommandBase;
use AcquiaCloudApi\Exception\ApiErrorException;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Validator\Validation;

/**
 * Class ApiCommandBase.
 */
class ApiCommandBase extends CommandBase {
  /**
   * @var string
   */
  protected $method;

  /**
   * @var array
   */
  protected $responses;

  /**
   * @var array
   */
  protected $servers;

  /**
   * @var string
   */
  protected $path;

  /**
   * @var array
   */
  private $queryParams = [];

  /**
   * @var array
   */
  private $postParams = [];

  /** @var array  */
  private $pathParams = [];

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   * @throws \Exception
   * @throws \Psr\Cache\InvalidArgumentException
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    parent::interact($input, $output);
    $params = array_merge($this->queryParams, $this->postParams, $this->pathParams);
    foreach ($this->getDefinition()->getArguments() as $argument) {
      if ($argument->isRequired() && !$input->getArgument($argument->getName())) {
        $this->io->note([
          "{$argument->getName()} is a required argument.",
          $argument->getDescription(),
        ]);
        // Choice question.
        if (array_key_exists($argument->getName(), $params)
          && array_key_exists('schema', $params[$argument->getName()])
          && array_key_exists('enum', $params[$argument->getName()]['schema'])) {
          $choices = $params[$argument->getName()]['schema']['enum'];
          $answer = $this->io->choice("Please select a value for {$argument->getName()}", $choices, $argument->getDefault());
        }
        // Free form.
        else {
          $validator = $this->createCallableValidator($argument, $params);
          $question = new Question("Please enter a value for {$argument->getName()}", $argument->getDefault());
          $question->setValidator($validator);
          // Allow unlimited attempts.
          $question->setMaxAttempts(NULL);
          $answer = $this->io->askQuestion($question);
        }
        $input->setArgument($argument->getName(), $answer);
      }
    }
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return int 0 if everything went fine, or an exit code
   * @throws \Exception
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    // Build query from non-null options.
    $acquia_cloud_client = $this->cloudApiClientService->getClient();
    if ($this->queryParams) {
      foreach ($this->queryParams as $key => $param_spec) {
        // We may have a queryParam that is used in the path rather than the query string.
        if ($input->hasOption($key) && $input->getOption($key) !== NULL) {
          $acquia_cloud_client->addQuery($key, $input->getOption($key));
        }
        elseif ($input->hasArgument($key) && $input->getArgument($key) !== NULL) {
          $acquia_cloud_client->addQuery($key, $input->getArgument($key));
        }
      }
    }
    if ($this->postParams) {
      foreach ($this->postParams as $param_name => $param_spec) {
        $param = $this->getParamFromInput($input, $param_name);
        if (!is_null($param)) {
          $param_name = ApiCommandHelper::restoreRenamedParameter($param_name);
          if ($param_spec) {
            $param = $this->castParamType($param_spec, $param);
          }
          if ($param_spec && array_key_exists('format', $param_spec) && $param_spec["format"] === 'binary') {
            $acquia_cloud_client->addOption('multipart', [
              [
                'name'     => $param_name,
                'contents' => Utils::tryFopen($param, 'r'),
              ],
            ]);
          }
          else {
            $acquia_cloud_client->addOption('json', [$param_name => $param]);
          }
        }
      }
    }

    $path = $this->getRequestPath($input);
    $acquia_cloud_client->addOption('headers', [
      'Accept' => 'application/json',
    ]);

    try {
      if ($this->output->isVeryVerbose()) {
        $acquia_cloud_client->addOption('debug', $this->output);
      }
      $response = $acquia_cloud_client->request($this->method, $path);
      $exit_code = 0;
    }
    catch (ApiErrorException $exception) {
      $response = $exception->getResponseBody();
      $exit_code = 1;
    }
    // @todo Add syntax highlighting to json output.
    $contents = json_encode($response, JSON_PRETTY_PRINT);
    $this->output->writeln($contents);

    return $exit_code;
  }

  /**
   * @param string $method
   */
  public function setMethod($method): void {
    $this->method = $method;
  }

  /**
   * @param array $responses
   */
  public function setResponses($responses): void {
    $this->responses = $responses;
  }

  /**
   * @param array $servers
   */
  public function setServers($servers): void {
    $this->servers = $servers;
  }

  /**
   * @param string $path
   */
  public function setPath($path): void {
    $this->path = $path;
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *
   * @return string
   */
  protected function getRequestPath(InputInterface $input): string {
    $path = $this->path;

    $arguments = $input->getArguments();
    // The command itself is the first argument. Remove it.
    array_shift($arguments);
    foreach ($arguments as $key => $value) {
      $token = '{' . $key . '}';
      if (strpos($path, $token) !== FALSE) {
        $path = str_replace($token, $value, $path);
      }
    }

    return $path;
  }

  /**
   * @return string
   */
  public function getMethod(): string {
    return $this->method;
  }

  /**
   * @param $param_name
   * @param $value
   */
  public function addPostParameter($param_name, $value): void {
    $this->postParams[$param_name] = $value;
  }

  /**
   * @param $param_name
   * @param $value
   */
  public function addQueryParameter($param_name, $value): void {
    $this->queryParams[$param_name] = $value;
  }

  /**
   * @return string
   */
  public function getPath(): string {
    return $this->path;
  }

  /**
   * @param string $param_name
   * @param $value
   */
  public function addPathParameter($param_name, $value): void {
    $this->pathParams[$param_name] = $value;
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param $param_name
   *
   * @return bool|string|string[]|null
   */
  protected function getParamFromInput(InputInterface $input, $param_name) {
    if ($input->hasArgument($param_name)) {
      $param = $input->getArgument($param_name);
    }
    else {
      $param = $input->getOption($param_name);
    }
    return $param;
  }

  /**
   * @param array $param_spec
   * @param string $value
   *
   * @return mixed
   */
  protected function castParamType($param_spec, $value) {
    $type = $this->getParamType($param_spec);
    if (!$type) {
      return $value;
    }

    switch ($type) {
      case 'int':
      case 'integer':
        $value = (int) $value;
        break;

      case 'bool':
      case 'boolean':
        $value = (bool) $value;
        break;
    }

    return $value;
  }

  /**
   * @param array $param_spec
   *
   * @return null|string
   */
  protected function getParamType($param_spec): ?string {
    // @todo File a CXAPI ticket regarding the inconsistent nesting of the 'type' property.
    if (array_key_exists('type', $param_spec)) {
      return $param_spec['type'];
    }
    elseif (array_key_exists('schema', $param_spec) && array_key_exists('type', $param_spec['schema'])) {
      return $param_spec['schema']['type'];
    }
    return NULL;
  }

  /**
   * @param \Symfony\Component\Console\Input\InputArgument $argument
   * @param array $params
   *
   * @return callable|null
   */
  protected function createCallableValidator(InputArgument $argument, array $params): ?callable {
    $validator = NULL;
    if (array_key_exists($argument->getName(), $params)) {
      $param_spec = $params[$argument->getName()];
      $constraints = [
        new NotBlank(),
      ];
      if ($type = $this->getParamType($param_spec)) {
        $constraints[] = new Type($type);
      }
      if (array_key_exists('schema', $param_spec)) {
        $schema = $param_spec['schema'];
        $constraints = $this->createLengthConstraint($schema, $constraints);
        $constraints = $this->createRegexConstraint($schema, $constraints);
      }
      $validator = function ($value) use ($constraints) {
        $violations = Validation::createValidator()->validate($value, $constraints);
        if (count($violations)) {
          throw new ValidatorException($violations->get(0)->getMessage());
        }
        return $value;
      };
    }
    return $validator;
  }

  /**
   * @param $schema
   * @param array $constraints
   *
   * @return array
   */
  protected function createLengthConstraint($schema, array $constraints): array {
    if (array_key_exists('minLength', $schema) || array_key_exists('maxLength', $schema)) {
      $length_options = [];
      if (array_key_exists('minLength', $schema)) {
        $length_options['min'] = $schema['minLength'];
      }
      if (array_key_exists('maxLength', $schema)) {
        $length_options['max'] = $schema['maxLength'];
      }
      $constraints[] = new Length($length_options);
    }
    return $constraints;
  }

  /**
   * @param $schema
   * @param array $constraints
   *
   * @return array
   */
  protected function createRegexConstraint($schema, array $constraints): array {
    if (array_key_exists('format', $schema)) {
      switch ($schema['format']) {
        case 'uuid';
          $constraints[] = CommandBase::getUuidRegexConstraint();
          break;
      }
    }
    elseif (array_key_exists('pattern', $schema)) {
      $constraints[] = new Regex([
        'pattern' => '/' . $schema['pattern'] . '/',
        'message' => 'It must match the pattern ' . $schema['pattern'],
      ]);
    }
    return $constraints;
  }

}
