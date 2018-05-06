<?php

require_once "phing/Task.php";

use Symfony\Component\Yaml\Yaml;
use Tlr\Tables\Elements\Table;

/**
 * Class SecurityHtmlSnippetsTask
 *
 * Check the security status for all the valid drupal projects.
 */
class SecurityHtmlSnippetsTask extends Task {

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
   * Glob pattern where all the property yml files can be found.
   *
   * @var string
   *   Glob pattern where all the property yml files can be found.
   */
  protected $sourceFile;

  /**
   * Dir where the json reports will be outputted.
   *
   * @var string
   *   Dir where the json reports will be outputted.
   */
  protected $outputDir;


  /**
   * Dir where the template data is.
   *
   * @var string
   *   Dir where the json reports will be outputted.
   */
  protected $templateDir;

  /**
   * Generate all the snippets.
   *
   * @var array
   *   All the snippet data.
   */
  protected $tables = [];

  /**
   * The main entry point method.
   */
  public function main() {
    $data = Yaml::parseFile($this->getSourceFile());

    foreach ($data as $projectStatus) {
      if (!isset($this->tables[$projectStatus['team']])) {
        $headerTable = new Table();
        $headerRow = $headerTable->header()->row();
        $headerRow->cell('Project');
        $headerRow->cell('Insecure');
        $headerRow->cell('Update available');
        // $headerRow->cell('Unpublished');
        $headerRow->cell('Unsupported');
        $headerRow->cell('Detail');
        // $headerRow->cell('Up to date');
        // $headerRow->cell('Unchecked');
        // $headerRow->cell('Unknown');
        // $headerRow->cell('Custom?');
        $this->tables[$projectStatus['team']]['header'] = $headerTable;
        $this->tables[$projectStatus['team']]['details'] = [];
      }

      /** @var Table $headerTable */
      $headerTable = $this->tables[$projectStatus['team']]['header'];

      // If the item could not be validated, add a message row.
      if (!$projectStatus['checked']) {
        $row = $headerTable->body()->row();
        $row->cell($projectStatus['group'] . ' ' . $projectStatus['name']);
        // Keeps the output clean.
        for ($i = 1; $i <= 3; $i++) {
          $row->cell('');
        }
        $row->cell($projectStatus['message']);
      }
      else {
        // If more than one server was polled for this project. for example in
        // multi site setups etc. We'll add one row for each.
        foreach ($projectStatus['report'] as $serverId => $report) {
          $row = $headerTable->body()->row();
          $row->cell($projectStatus['group'] . ' ' . $projectStatus['name']);
          if ($report['checked']) {

            $row->cell((string) $report['modules']['counts'][static::UPDATE_NOT_SECURE]);
            $row->cell((string) $report['modules']['counts'][static::UPDATE_NOT_CURRENT]);

            // Add all the other numbers together.
            $unsupported = (int) $report['modules']['counts'][static::UPDATE_REVOKED] + (int) $report['modules']['counts'][static::UPDATE_NOT_SUPPORTED];
            $row->cell((string) $unsupported);

            $row->cell($report['host']);

            // Create a detail table.
            // Create html table
            $detailTable = new Table;
            $row = $detailTable->header()->row();
            $row->cell('Module');
            $row->cell('Status');
            $row->cell('Current version');
            $row->cell('Required version');

            foreach ($report['modules']['needUpdateModules'] as $machine_name => $module) {
              $row = $detailTable->body()->row();
              $row->cell($module['label']);
              $row->cell($module['message']);
              $row->cell($module['currentVersion']);
              $row->cell($module['newVersion']);
            }

            $this->tables[$projectStatus['team']]['details'][] = [
              'header' => '<h2>' . $projectStatus['name'] . ' (' . $projectStatus['group'] .')</h2>',
              'subheader' => '<p>' . $serverId . '</p>',
              'table' => $detailTable,
            ];
          }
          else {
            // Keeps the output clean.
            for ($i = 1; $i <= 3; $i++) {
              $row->cell('');
            }
            $message = !empty($report['message']) ? $report['message'] : 'Unknown Error';
            $row->cell($message);
          }
        }
      }
    }

    // Write to html.
    $loader = new \Twig_Loader_Filesystem($this->getTemplateDir());
    $twigEnv = new \Twig_Environment($loader);

    foreach ($this->tables as $team => $tableInfo) {
      $outputFile = $this->getOutputDir() . '/' . $team . '-snippet.html';
      $outputFullFile = $this->getOutputDir() . '/' . $team . '.html';
      $output = $tableInfo['header']->render();

      foreach ($tableInfo['details'] as $detailTable) {
        $output .= $detailTable['header'];
        $output .= $detailTable['subheader'];
        $output .= $detailTable['table']->render();
      }

      file_put_contents($outputFile, $output);

      $html = $twigEnv->render('page.html.twig', ['content' => $output]);
      file_put_contents($outputFullFile, $html);
    }
  }

  /**
   * Set the source dir for the files.
   *
   * @param string $sourceFile
   *   Glob pattern where all the property yml files can be found.
   */
  public function setSourceFile(string $sourceFile) {
    $this->sourceFile = $sourceFile;
  }

  /**
   * Get the source pattern.
   *
   * @return string
   *    Glob pattern where all the property yml files can be found.
   */
  public function getSourceFile() {
    return $this->sourceFile;
  }

  /**
   * Get the template dir.
   *
   * @return string
   *   Directory with all the twig templates etc.
   */
  public function getTemplateDir() {
    return $this->templateDir;
  }

  /**
   * Set the template dir.
   *
   * @param string $templateDir
   *   Directory with all the template information.
   */
  public function setTemplateDir(string $templateDir) {
    $this->templateDir = $templateDir;
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