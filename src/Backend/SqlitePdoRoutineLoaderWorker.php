<?php
declare(strict_types=1);

namespace SetBased\Stratum\SqlitePdo\Backend;

use SetBased\Exception\RuntimeException;
use SetBased\Stratum\Backend\RoutineLoaderWorker;
use SetBased\Stratum\Common\Exception\RoutineLoaderException;
use SetBased\Stratum\Common\Helper\SourceFinderHelper;
use SetBased\Stratum\Middle\NameMangler\NameMangler;
use SetBased\Stratum\SqlitePdo\Helper\RoutineLoaderHelper;

/**
 * Command for mimicking loading stored routines into a SQLite instance from pseudo SQL files.
 */
class SqlitePdoRoutineLoaderWorker extends SqlitePdoWorker implements RoutineLoaderWorker
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * An array with source filenames that are not loaded into SQLite.
   *
   * @var array
   */
  private array $errorFilenames = [];

  /**
   * Class name for mangling routine and parameter names.
   *
   * @var string|null
   */
  private ?string $nameMangler;

  /**
   * The metadata of all stored routines. Note: this data is stored in the metadata file and is generated by PhpStratum.
   *
   * @var array
   */
  private array $phpStratumMetadata = [];

  /**
   * The filename of the file with the metadata of all stored routines.
   *
   * @var string
   */
  private string $phpStratumMetadataFilename;

  /**
   * Pattern where of the sources files.
   *
   * @var string
   */
  private string $sourcePattern;

  /**
   * All sources with stored routines. Each element is an array with the following keys:
   * <ul>
   * <li> path_name    The path the source file.
   * <li> routine_name The name of the routine (equals the basename of the path).
   * <li> method_name  The name of the method in the data layer for the wrapper method of the stored routine.
   * </ul>
   *
   * @var array[]
   */
  private array $sources = [];

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * @inheritdoc
   */
  public function execute(?array $sources = null): int
  {
    $this->io->title('PhpStratum: SQLite PDO Loader');

    $this->phpStratumMetadataFilename = $this->settings->manString('loader.metadata');
    $this->sourcePattern              = $this->settings->manString('loader.sources');
    $this->nameMangler                = $this->settings->optString('wrapper.mangler_class');

    if (empty($sources))
    {
      $this->loadAll();
    }
    else
    {
      $this->loadList($sources);
    }

    $this->logOverviewErrors();

    return (empty($this->errorFilenames)) ? 0 : 1;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Detects stored routines that would result in duplicate wrapper method name.
   */
  private function detectNameConflicts(): void
  {
    // Get same method names from array
    list($sources_by_path, $sources_by_method) = $this->getDuplicates();

    // Add every not unique method name to myErrorFileNames
    foreach ($sources_by_path as $source)
    {
      $this->errorFilenames[] = $source['path_name'];
    }

    // Log the sources files with duplicate method names.
    foreach ($sources_by_method as $method => $sources)
    {
      $tmp = [];
      foreach ($sources as $source)
      {
        $tmp[] = $source['path_name'];
      }

      $this->io->error(sprintf("The following source files would result wrapper methods with equal name '%s'",
                               $method));
      $this->io->listing($tmp);
    }

    // Remove duplicates from mySources.
    foreach ($this->sources as $i => $source)
    {
      if (isset($sources_by_path[$source['path_name']]))
      {
        unset($this->sources[$i]);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Searches recursively for all source files.
   */
  private function findSourceFiles(): void
  {
    $helper    = new SourceFinderHelper(dirname($this->settings->manString('stratum.config_path')));
    $filenames = $helper->findSources($this->sourcePattern);

    foreach ($filenames as $filename)
    {
      $routineName     = pathinfo($filename, PATHINFO_FILENAME);
      $this->sources[] = ['path_name'    => $filename,
                          'routine_name' => $routineName,
                          'method_name'  => $this->methodName($routineName)];
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Finds all source files that actually exists from a list of file names.
   *
   * @param string[] $sources The list of file names.
   */
  private function findSourceFilesFromList(array $sources): void
  {
    foreach ($sources as $path)
    {
      if (!file_exists($path))
      {
        $this->io->error(sprintf("File not exists: '%s'", $path));
        $this->errorFilenames[] = $path;
      }
      else
      {
        $routineName     = pathinfo($path, PATHINFO_FILENAME);
        $this->sources[] = ['path_name'    => $path,
                            'routine_name' => $routineName,
                            'method_name'  => $this->methodName($routineName)];
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns all elements in {@link $sources} with duplicate method names.
   *
   * @return array[]
   */
  private function getDuplicates(): array
  {
    // First pass make lookup table by method_name.
    $lookup = [];
    foreach ($this->sources as $source)
    {
      if (isset($source['method_name']))
      {
        if (!isset($lookup[$source['method_name']]))
        {
          $lookup[$source['method_name']] = [];
        }

        $lookup[$source['method_name']][] = $source;
      }
    }

    // Second pass find duplicate sources.
    $duplicates_sources = [];
    $duplicates_methods = [];
    foreach ($this->sources as $source)
    {
      if (count($lookup[$source['method_name']])>1)
      {
        $duplicates_sources[$source['path_name']]   = $source;
        $duplicates_methods[$source['method_name']] = $lookup[$source['method_name']];
      }
    }

    return [$duplicates_sources, $duplicates_methods];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines into SQLite.
   */
  private function loadAll(): void
  {
    $this->findSourceFiles();
    $this->detectNameConflicts();
    $this->loadStoredRoutines();
    $this->writeStoredRoutineMetadata();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines in a list into SQLite.
   *
   * @param string[] $sources The list of files to be loaded.
   */
  private function loadList(array $sources): void
  {
    $this->findSourceFilesFromList($sources);
    $this->detectNameConflicts();
    $this->loadStoredRoutines();
    $this->writeStoredRoutineMetadata();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines.
   */
  private function loadStoredRoutines(): void
  {
    // Sort the sources by routine name.
    usort($this->sources, function ($a, $b) {
      return strcmp($a['routine_name'], $b['routine_name']);
    });

    // Process all sources.
    foreach ($this->sources as $filename)
    {
      $routineName = $filename['routine_name'];

      $helper = new RoutineLoaderHelper($this->io, $filename['path_name']);

      try
      {
        $this->phpStratumMetadata[$routineName] = $helper->loadStoredRoutine();
      }
      catch (RoutineLoaderException $e)
      {
        $messages = [$e->getMessage(), sprintf("Failed to load file '%s'", $filename['path_name'])];
        $this->io->error($messages);

        $this->errorFilenames[] = $filename['path_name'];
        unset($this->phpStratumMetadata[$routineName]);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Logs the source files that were not successfully loaded into SQLite.
   */
  private function logOverviewErrors(): void
  {
    if (!empty($this->errorFilenames))
    {
      $this->io->warning('Routines in the files below are not loaded:');
      $this->io->listing($this->errorFilenames);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the method name in the wrapper for a stored routine. Returns null when name mangler is not set.
   *
   * @param string $routineName The name of the routine.
   *
   * @return null|string
   */
  private function methodName(string $routineName): ?string
  {
    if ($this->nameMangler!==null)
    {
      /** @var NameMangler $mangler */
      $mangler = $this->nameMangler;

      return $mangler::getMethodName($routineName);
    }

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Writes the metadata of all stored routines to the metadata file.
   */
  private function writeStoredRoutineMetadata(): void
  {
    $this->io->writeln('');

    $json_data = json_encode($this->phpStratumMetadata, JSON_PRETTY_PRINT);
    if (json_last_error()!=JSON_ERROR_NONE)
    {
      throw new RuntimeException("Error of encoding to JSON: '%s'.", json_last_error_msg());
    }
    $this->writeTwoPhases($this->phpStratumMetadataFilename, $json_data);
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
