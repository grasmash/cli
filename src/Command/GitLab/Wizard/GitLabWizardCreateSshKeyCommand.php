<?php

namespace Acquia\Cli\Command\GitLab\Wizard;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Command\Ide\Wizard\IdeWizardCreateSshKeyCommand;
use Acquia\Cli\Exception\AcquiaCliException;
use stdClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GitLabWizardCreateSshKeyCommand extends IdeWizardCreateSshKeyCommand {

  protected static $defaultName = 'gitlab:wizard:ssh-key:create-upload';

  /**
   * @var array|false|string
   */
  private $appUuid;

  /**
   * {inheritdoc}.
   */
  protected function configure() {
    $this->setDescription('Wizard to perform first time setup tasks within an IDE')
      ->setAliases(['ide:wizard'])
      ->setHidden(!CommandBase::isAcquiaCloudIde());
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return void
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    $this->appUuid = getenv('ACQUIA_APPLICATION_UUID');
    $this->privateSshKeyFilename = $this->getSshKeyFilename($this->appUuid);
    $this->privateSshKeyFilepath = $this->sshDir . '/' . $this->privateSshKeyFilename;
    $this->publicSshKeyFilepath = $this->privateSshKeyFilepath . '.pub';

    parent::initialize($input, $output);
  }

  /**
   * @param string $app_uuid
   *
   * @return string
   */
  public function getSshKeyFilename(string $app_uuid): string {
    return 'id_rsa_acquia_gitlab_' . $app_uuid;
  }

  /**
   *
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function deleteThisSshKeyFromCloud(): void {
    if ($cloud_key = $this->findGitLabSshKeyOnCloud()) {
      $this->deleteSshKeyFromCloud($cloud_key);
    }
  }

  protected function validateEnvironment() {
    if (!getenv('GITLAB_CI')) {
      throw new AcquiaCliException('This command can only be run inside of a GitLab CI job');
    }
  }

  /**
   * @return \stdClass|null
   */
  protected function findGitLabSshKeyOnCloud(): ?stdClass {
    $acquia_cloud_client = $this->cloudApiClientService->getClient();
    $cloud_keys = $acquia_cloud_client->request('get', '/account/ssh-keys');
    $ssh_key_label = $this->getSshKeyLabel();
    foreach ($cloud_keys as $cloud_key) {
      if ($cloud_key->label === $ssh_key_label) {
        return $cloud_key;
      }
    }
    return NULL;
  }

  /**
   *
   * @param string $app_uuid
   *
   * @return string
   */
  public static function getGitLabSshKeyLabel(string $app_uuid): string {
    return self::normalizeSshKeyLabel('GITLAB_' . $app_uuid);
  }

  /**
   * @return string
   */
  protected function getSshKeyLabel(): string {
    return $this::getGitLabSshKeyLabel($this->appUuid);
  }

}