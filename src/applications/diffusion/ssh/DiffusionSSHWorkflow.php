<?php

abstract class DiffusionSSHWorkflow extends PhabricatorSSHWorkflow {

  private $args;
  private $repository;
  private $hasWriteAccess;
  private $proxyURI;

  public function getRepository() {
    if (!$this->repository) {
      throw new Exception(pht('Repository is not available yet!'));
    }
    return $this->repository;
  }

  private function setRepository(PhabricatorRepository $repository) {
    $this->repository = $repository;
    return $this;
  }

  public function getArgs() {
    return $this->args;
  }

  public function getEnvironment() {
    $env = array(
      DiffusionCommitHookEngine::ENV_USER => $this->getUser()->getUsername(),
      DiffusionCommitHookEngine::ENV_REMOTE_PROTOCOL => 'ssh',
    );

    $ssh_client = getenv('SSH_CLIENT');
    if ($ssh_client) {
      // This has the format "<ip> <remote-port> <local-port>". Grab the IP.
      $remote_address = head(explode(' ', $ssh_client));
      $env[DiffusionCommitHookEngine::ENV_REMOTE_ADDRESS] = $remote_address;
    }

    return $env;
  }

  /**
   * Identify and load the affected repository.
   */
  abstract protected function identifyRepository();
  abstract protected function executeRepositoryOperations();

  protected function writeError($message) {
    $this->getErrorChannel()->write($message);
    return $this;
  }

  protected function shouldProxy() {
    return (bool)$this->proxyURI;
  }

  protected function getProxyCommand() {
    $uri = new PhutilURI($this->proxyURI);

    $username = PhabricatorEnv::getEnvConfig('cluster.instance');
    if (!strlen($username)) {
      $username = PhabricatorEnv::getEnvConfig('diffusion.ssh-user');
      if (!strlen($username)) {
        throw new Exception(
          pht(
            'Unable to determine the username to connect with when trying '.
            'to proxy an SSH request within the Phabricator cluster.'));
      }
    }

    $port = $uri->getPort();
    $host = $uri->getDomain();
    $key_path = AlmanacKeys::getKeyPath('device.key');
    if (!Filesystem::pathExists($key_path)) {
      throw new Exception(
        pht(
          'Unable to proxy this SSH request within the cluster: this device '.
          'is not registered and has a missing device key (expected to '.
          'find key at "%s").',
          $key_path));
    }

    $options = array();
    $options[] = '-o';
    $options[] = 'StrictHostKeyChecking=no';
    $options[] = '-o';
    $options[] = 'UserKnownHostsFile=/dev/null';

    // This is suppressing "added <address> to the list of known hosts"
    // messages, which are confusing and irrelevant when they arise from
    // proxied requests. It might also be suppressing lots of useful errors,
    // of course. Ideally, we would enforce host keys eventually.
    $options[] = '-o';
    $options[] = 'LogLevel=quiet';

    // NOTE: We prefix the command with "@username", which the far end of the
    // connection will parse in order to act as the specified user. This
    // behavior is only available to cluster requests signed by a trusted
    // device key.

    return csprintf(
      'ssh %Ls -l %s -i %s -p %s %s -- %s %Ls',
      $options,
      $username,
      $key_path,
      $port,
      $host,
      '@'.$this->getUser()->getUsername(),
      $this->getOriginalArguments());
  }

  final public function execute(PhutilArgumentParser $args) {
    $this->args = $args;

    $repository = $this->identifyRepository();
    $this->setRepository($repository);

    $is_cluster_request = $this->getIsClusterRequest();
    $uri = $repository->getAlmanacServiceURI(
      $this->getUser(),
      $is_cluster_request,
      array(
        'ssh',
      ));

    if ($uri) {
      $this->proxyURI = $uri;
    }

    try {
      return $this->executeRepositoryOperations();
    } catch (Exception $ex) {
      $this->writeError(get_class($ex).': '.$ex->getMessage());
      return 1;
    }
  }

  protected function loadRepositoryWithPath($path) {
    $viewer = $this->getUser();

    $regex = '@^/?diffusion/(?P<callsign>[A-Z]+)(?:/|\z)@';
    $matches = null;
    if (!preg_match($regex, $path, $matches)) {
      throw new Exception(
        pht(
          'Unrecognized repository path "%s". Expected a path like '.
          '"%s".',
          $path,
          '/diffusion/X/'));
    }

    $callsign = $matches[1];
    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer($viewer)
      ->withCallsigns(array($callsign))
      ->executeOne();

    if (!$repository) {
      throw new Exception(
        pht('No repository "%s" exists!', $callsign));
    }

    switch ($repository->getServeOverSSH()) {
      case PhabricatorRepository::SERVE_READONLY:
      case PhabricatorRepository::SERVE_READWRITE:
        // If we have read or read/write access, proceed for now. We will
        // check write access when the user actually issues a write command.
        break;
      case PhabricatorRepository::SERVE_OFF:
      default:
        throw new Exception(
          pht('This repository is not available over SSH.'));
    }

    return $repository;
  }

  protected function requireWriteAccess($protocol_command = null) {
    if ($this->hasWriteAccess === true) {
      return;
    }

    $repository = $this->getRepository();
    $viewer = $this->getUser();

    switch ($repository->getServeOverSSH()) {
      case PhabricatorRepository::SERVE_READONLY:
        if ($protocol_command !== null) {
          throw new Exception(
            pht(
              'This repository is read-only over SSH (tried to execute '.
              'protocol command "%s").',
              $protocol_command));
        } else {
          throw new Exception(
            pht('This repository is read-only over SSH.'));
        }
        break;
      case PhabricatorRepository::SERVE_READWRITE:
        $can_push = PhabricatorPolicyFilter::hasCapability(
          $viewer,
          $repository,
          DiffusionPushCapability::CAPABILITY);
        if (!$can_push) {
          throw new Exception(
            pht('You do not have permission to push to this repository.'));
        }
        break;
      case PhabricatorRepository::SERVE_OFF:
      default:
        // This shouldn't be reachable because we don't get this far if the
        // repository isn't enabled, but kick them out anyway.
        throw new Exception(
          pht('This repository is not available over SSH.'));
    }

    $this->hasWriteAccess = true;
    return $this->hasWriteAccess;
  }

}
