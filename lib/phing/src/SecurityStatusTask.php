<?php

require_once "phing/Task.php";

use Symfony\Component\Yaml\Yaml;
use surangapg\HeavydComponents\Project\Project;
use surangapg\HeavydComponents\Project\ProjectInterface;
use surangapg\HeavydComponents\Server\ServerInterface;

/**
 * Class SecurityStatusTask
 *
 * Check the security status for all the valid drupal projects.
 */
class SecurityStatusTask extends Task {

  /**
   * These were copied to be able to decode the data from ups
   * @TODO Simplify this.
   */
  /** Project is missing security update(s). */
  const UPDATE_NOT_SECURE = 1;

  /** Current release has been unpublished and is no longer available. */
  const UPDATE_REVOKED = 2;

  /** Current release is no longer supported by the project maintainer. */
  const UPDATE_NOT_SUPPORTED = 3;

  /** Project has a new release available, but it is not a security release. */
  const UPDATE_NOT_CURRENT = 4;

  /** Project is up to date. */
  const UPDATE_CURRENT = 5;

  /** Project's status cannot be checked. */
  const UPDATE_NOT_CHECKED = -1;

  /** No available update data was found for project. */
  const UPDATE_UNKNOWN = -2;

  /**
   * @param integer $status
   * @return string
   */
  public function decodeStatus($status) {
    $statuses = $this->getStatuses();
    return isset($statuses[$status]) ? $statuses[$status] : 'Update code unknown';
  }

  /**
   * Get a full list of all the statuses.
   *
   * @TODO Simplify this.
   *
   * @return array
   */
  public function getStatuses() {
    return [
      self::UPDATE_NOT_SECURE => 'Insecure',
      self::UPDATE_NOT_CURRENT => 'Update available',
      self::UPDATE_REVOKED => 'Unpublished',
      self::UPDATE_NOT_SUPPORTED => 'Unsupported',
      self::UPDATE_CURRENT => 'Up to date',
      self::UPDATE_NOT_CHECKED => 'Unchecked',
      self::UPDATE_UNKNOWN => 'Unknown'
    ];
  }

  /**
   * Glob pattern where all the property yml files can be found.
   *
   * @var string
   *   Glob pattern where all the property yml files can be found.
   */
  protected $sourceDir;

  /**
   * Dir where the json reports will be outputted.
   *
   * @var string
   *   Dir where the json reports will be outputted.
   */
  protected $outputDir;

  /**
   * All the data in the report.
   *
   * @var array
   *   Report to write out to the file system.
   */
  protected $report = [];

  /**
   * The main entry point method.
   */
  public function main() {

    $projects = Project::parseFolder($this->getSourceDir());

    foreach ($projects as $dataFile => $project) {

      if (!in_array($project->getProjectType(), ['d7', 'd8'])) {
        continue;
      }

      $this->log('Handling ' . $dataFile);
      $this->pollProjectServers($project);
    }
    $this->outputReport();
  }

  /**
   * Poll all the servers for a project.
   *
   * @param \surangapg\HeavydComponents\Project\ProjectInterface $project
   *   Project to handle.
   */
  protected function pollProjectServers(ProjectInterface $project) {

    $this->report[$project->getFullIdentifier()]['checked'] = FALSE;
    $this->report[$project->getFullIdentifier()]['id'] = $project->getFullIdentifier();
    $this->report[$project->getFullIdentifier()]['type'] = $project->getProjectType();
    $this->report[$project->getFullIdentifier()]['group'] = $project->getProjectGroup();
    $this->report[$project->getFullIdentifier()]['name'] = $project->getProjectName();
    $this->report[$project->getFullIdentifier()]['team'] = $project->getProjectTeam();
    $this->report[$project->getFullIdentifier()]['message'] = '';
    $this->report[$project->getFullIdentifier()]['report'] = [];

    // Check or the server should be auto polled.
    if (!$project->pollSecurityAutomatically()) {
      $this->report[$project->getFullIdentifier()]['message']= 'Auto check has been disabled for this project.';
      return;
    }

    // Otherwise generate the reports for all the polled servers.
    $servers = $project->getSecurityPollableServers();

    if (empty($servers)) {
      $this->report[$project->getFullIdentifier()]['message']= 'No server configured for auto polling.';
      return;
    }

    foreach ($servers as $server) {
      $this->report[$project->getFullIdentifier()]['checked'] = TRUE;
      $this->generateReport($project, $server);
    }
  }

  /**
   * Get the actual report for a server.
   *
   * @param \surangapg\HeavydComponents\Project\ProjectInterface $project
   *   Project to check.
   * @param \surangapg\HeavydComponents\Server\ServerInterface $server
   *   Server to check.
   */
  protected function generateReport(ProjectInterface $project, ServerInterface $server) {
    $this->log('Visiting ' . $server->getLabel());
    $commandReturn = $server->runRemote('drush ups --fields="label,name,existing_version,candidate_version,status" --format=csv');

    $this->report[$project->getFullIdentifier()]['report'][$server->getLabel()]['host'] = $server->getHost();

    if ($commandReturn['return'] != 0) {
      $this->report[$project->getFullIdentifier()]['report'][$server->getLabel()]['checked'] = FALSE;
      $this->report[$project->getFullIdentifier()]['report'][$server->getLabel()]['message'] = $server->getUser() . '@' . $server->getHost() . ' -- error code: ' . $commandReturn['return'] . implode("<br>", $commandReturn['output']);

      return;
    }

    $this->report[$project->getFullIdentifier()]['report'][$server->getLabel()]['checked'] = TRUE;
    // Parse the feedback.
    $upsData = [
      'counts' => array_fill_keys(array_keys($this->getStatuses()), 0),
      'modules' => [],
      'needUpdateModules' => [],
    ];

    foreach ($commandReturn['output'] as $data) {
      if (!empty($data)) {
        $data = explode(',', $data);
        $assoc = array_combine(
          [
            'label',
            'machineName',
            'currentVersion',
            'newVersion',
            'statusCode',
          ],
          $data
        );
        $assoc['message'] = $this->decodeStatus($assoc['statusCode']);

        // Increase the total tally
        $upsData['counts'][$assoc['statusCode']] += 1;

        // Add the detailed data.
        $upsData['modules'][$assoc['machineName']] = $assoc;

        if ($assoc['statusCode'] != self::UPDATE_CURRENT) {
          $upsData['needUpdateModules'][$assoc['statusCode'] . $assoc['machineName']] = $assoc;
        }

        ksort($upsData['needUpdateModules']);

        $this->report[$project->getFullIdentifier()]['report'][$server->getLabel()]['modules'] = $upsData;
      }
    }
  }

  /**
   * Output the data currently in the report.
   */
  protected function outputReport() {
    $outputFile = $this->getOutputDir() . '/security-report.yml';
    file_put_contents($outputFile, Yaml::dump($this->report, 6, 2));
    $this->report = [];
  }

  /**
   * Set the source dir for the files.
   *
   * @param string $sourceDir
   *   Glob pattern where all the property yml files can be found.
   */
  public function setSourceDir(string $sourceDir) {
    $this->sourceDir = $sourceDir;
  }

  /**
   * Get the source pattern.
   *
   * @return string
   *    Glob pattern where all the property yml files can be found.
   */
  public function getSourceDir() {
    return $this->sourceDir;
  }

  /**
   * Dir where the json reports will be outputted.
   *
   * @param string $outputDir
   *   Dir where the json reports will be outputted.
   */
  public function setOutputDir(string $outputDir) {
    $this->outputDir = $outputDir;
  }

  /**
   * Dir where the json reports will be outputted.
   *
   * @return string
   *   Dir where the json reports will be outputted.
   */
  public function getOutputDir() {
    return $this->outputDir;
  }
}

?>