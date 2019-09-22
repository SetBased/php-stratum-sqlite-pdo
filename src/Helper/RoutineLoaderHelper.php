<?php
declare(strict_types=1);

namespace SetBased\Stratum\SqlitePdo\Helper;

use SetBased\Stratum\Exception\RoutineLoaderException;
use SetBased\Stratum\Helper\RowSetHelper;
use SetBased\Stratum\SqlitePdo\Reflection\ParamTag;
use SetBased\Stratum\SqlitePdo\SqlitePdoDataLayer;
use SetBased\Stratum\StratumStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Zend\Code\Reflection\DocBlock\Tag\GenericTag;
use Zend\Code\Reflection\DocBlock\Tag\ReturnTag;
use Zend\Code\Reflection\DocBlock\TagManager as DocBlockTagManager;
use Zend\Code\Reflection\DocBlockReflection;

/**
 * Class for loading a single stored routine into a MySQL instance from pseudo SQL file.
 */
class RoutineLoaderHelper
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * An in memory SQLite database.
   *
   * @var SqlitePdoDataLayer
   */
  private $db;

  /**
   * The designation type of the stored routine.
   *
   * @var string
   */
  private $designationType;

  /**
   * All DocBlock parts as found in the source of the stored routine.
   *
   * @var array
   */
  private $docBlockPartsSource = [];

  /**
   * The DocBlock parts to be used by the wrapper generator.
   *
   * @var array
   */
  private $docBlockPartsWrapper;

  /**
   * The reflection of the DocBlock of the stored routine.
   *
   * @var DocBlockReflection
   */
  private $docBlockReflection;

  /**
   * The Output decorator
   *
   * @var StratumStyle
   */
  private $io;

  /**
   * The offset of the first line of the payload of the stored routine ins the source file.
   *
   * @var int
   */
  private $offset;

  /**
   * The information about the parameters of the stored routine.
   *
   * @var array[]
   */
  private $parameters = [];

  /**
   * The metadata of the stored routine. Note: this data is stored in the metadata file and is generated by PhpStratum.
   *
   * @var array
   */
  private $phpStratumMetadata;

  /**
   * The replace pairs (i.e. placeholders and their actual values, see strst).
   *
   * @var array
   */
  private $replace = [];

  /**
   * A map from placeholders to their actual values.
   *
   * @var array
   */
  private $replacePairs = [];

  /**
   * The return type of the stored routine (only if designation type singleton0, singleton1, or function).
   *
   * @var string|null
   */
  private $returnType;

  /**
   * The name of the stored routine.
   *
   * @var string
   */
  private $routineName;

  /**
   * The payload of the stored routine (i.e. the code without the DocBlock).
   *
   * @var string
   */
  private $routinePayLoad;

  /**
   * The source code as a single string of the stored routine.
   *
   * @var string
   */
  private $routineSourceCode;

  /**
   * The source code as an array of lines string of the stored routine.
   *
   * @var array
   */
  private $routineSourceCodeLines;

  /**
   * The source filename holding the stored routine.
   *
   * @var string
   */
  private $sourceFilename;

  //--------------------------------------------------------------------------------------------------------------------

  /**
   * Object constructor.
   *
   * @param StratumStyle $io              The output for log messages.
   * @param string       $routineFilename The filename of the source of the stored routine.
   */
  public function __construct(StratumStyle $io, string $routineFilename)
  {
    $this->db             = new SqlitePdoDataLayer();
    $this->io             = $io;
    $this->sourceFilename = $routineFilename;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads the stored routine into the instance of MySQL and returns the metadata of the stored routine.
   *
   * @return array
   */
  public function loadStoredRoutine(): array
  {
    $this->routineName = pathinfo($this->sourceFilename, PATHINFO_FILENAME);

    $this->io->text(sprintf('Loading routine <dbo>%s</dbo>', OutputFormatter::escape($this->routineName)));

    $this->readSourceCode();
    $this->createDocBlockReflection();
    $this->extractPlaceholders();
    $this->extractDesignationType();
    $this->extractReturnType();
    $this->validateReturnType();
    $this->loadRoutineFile();
    $this->extractDocBlockPartsSource();
    $this->extractRoutineParametersInfo();
    $this->extractDocBlockPartsWrapper();
    $this->validateParameterLists();
    $this->updateMetadata();

    return $this->phpStratumMetadata;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Creates the DocBlock reflection object.
   */
  private function createDocBlockReflection(): void
  {
    $tagManager = new DocBlockTagManager();
    $tagManager->addPrototype(new ParamTag());
    $tagManager->addPrototype(new ReturnTag());
    $tagManager->setGenericPrototype(new GenericTag());
    $this->docBlockReflection = new DocBlockReflection($this->routineSourceCode, $tagManager);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the designation type of the stored routine.
   */
  private function extractDesignationType(): void
  {
    $tags = $this->docBlockReflection->getTags('type');
    if (count($tags)==1)
    {
      $tag = $tags[0];
      if ($tag instanceof GenericTag)
      {
        $this->designationType = $tag->getContent();
      }
    }

    if ($this->designationType===null)
    {
      throw new RoutineLoaderException('Unable to find the designation type of the stored routine');
    }

    if (!in_array($this->designationType, ['none', 'row0', 'row1', 'rows', 'singleton0', 'singleton1']))
    {
      throw new RoutineLoaderException("'%s' is not a valid designation type", $this->designationType);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Extracts the DocBlock (in parts) from the source of the stored routine.
   */
  private function extractDocBlockPartsSource(): void
  {
    // Get the short description.
    $this->docBlockPartsSource['sort_description'] = $this->docBlockReflection->getShortDescription();

    // Get the long description.
    $this->docBlockPartsSource['long_description'] = $this->docBlockReflection->getLongDescription();

    // Get the description for each parameter of the stored routine.
    foreach ($this->docBlockReflection->getTags('param') as $key => $tag)
    {
      if ($tag instanceof ParamTag)
      {
        $this->docBlockPartsSource['parameters'][$key] = ['name'        => $tag->getVariableName(),
                                                          'type'        => implode('|', $tag->getTypes()),
                                                          'description' => $tag->getDescription()];
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   *  Extracts DocBlock parts to be used by the wrapper generator.
   */
  private function extractDocBlockPartsWrapper(): void
  {
    // Generate the parameters parts of the DocBlock to be used by the wrapper.
    $parameters = [];
    foreach ($this->docBlockPartsSource['parameters'] ?? [] as $parameter)
    {
      $type         = DataTypeHelper::columnTypeToPhpTypeHinting($this->parameterType($parameter['name'])).'|null';
      $parameters[] = ['name'        => $parameter['name'],
                       'type'        => $type,
                       'description' => $this->parameterDescription($parameter['name'])];
    }

    // Compose all the DocBlock parts to be used by the wrapper generator.
    $this->docBlockPartsWrapper = ['sort_description' => $this->docBlockPartsSource['sort_description'],
                                   'long_description' => $this->docBlockPartsSource['long_description'],
                                   'parameters'       => $parameters];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the placeholders from the stored routine source.
   */
  private function extractPlaceholders(): void
  {
    $unknown = [];

    preg_match_all('(@[A-Za-z0-9_.]+(%type)?@)', $this->routineSourceCode, $matches);
    if (!empty($matches[0]))
    {
      foreach ($matches[0] as $placeholder)
      {
        if (isset($this->replacePairs[strtoupper($placeholder)]))
        {
          $this->replace[$placeholder] = $this->replacePairs[strtoupper($placeholder)];
        }
        else
        {
          $unknown[] = $placeholder;
        }
      }
    }

    $this->logUnknownPlaceholders($unknown);
  }


  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the return type of the stored routine.
   */
  private function extractReturnType(): void
  {
    // Return immediately if designation type is not appropriate for this method.
    if (!in_array($this->designationType, ['function', 'singleton0', 'singleton1'])) return;

    $tags = $this->docBlockReflection->getTags('return');
    if (count($tags)==1)
    {
      $tag = $tags[0];
      if ($tag instanceof ReturnTag)
      {
        $this->returnType = implode('|', $tag->getTypes());
      }
    }

    if ($this->returnType===null)
    {
      $this->returnType = 'mixed';

      $this->io->logNote('Unable to find the return type of stored routine');
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts info about the parameters of the stored routine.
   */
  private function extractRoutineParametersInfo(): void
  {
    $first = $this->getFirstLineOfStoredRoutineBody();
    $body  = implode(PHP_EOL, array_slice($this->routineSourceCodeLines, $first - 1));

    preg_match_all('/(:[a-zA-Z_][a-zA-Z0-9_]*)/', $body, $matches);

    foreach ($matches[0] as $name)
    {
      $this->parameters[$name] = ['name' => $name,
                                  'type' => $this->parameterType($name)];
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the first line of the body of the stored routine.
   *
   * @return int
   */
  private function getFirstLineOfStoredRoutineBody(): int
  {
    $start = null;
    $last  = null;
    foreach ($this->routineSourceCodeLines as $i => $line)
    {
      if (trim($line)=='/**' && $start===null)
      {
        $start = $i + 1;
      }

      if (trim($line)=='*/' && $start!==null && $last===null)
      {
        $last = $i + 1;
        break;
      }
    }

    return ($last ?? 0) + 1;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads the stored routine into the database.
   */
  private function loadRoutineFile(): void
  {
    $this->setMagicConstants();

    //
    $this->offset = $this->getFirstLineOfStoredRoutineBody() - 1;
    $lines        = array_slice($this->routineSourceCodeLines, $this->offset);
    while (!empty($lines) && trim($lines[0])==='')
    {
      $this->offset++;
      array_shift($lines);
    }

    // Replace all place holders with their values.
    foreach ($lines as $i => &$line)
    {
      $this->replace['__LINE__'] = $i + $this->offset + 1;
      $line                      = strtr($line, $this->replace);
    }
    $this->routinePayLoad = implode(PHP_EOL, $lines);

    $this->unsetMagicConstants();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Logs the unknown placeholder (if any).
   *
   * @param array $unknown The unknown placeholders.
   */
  private function logUnknownPlaceholders(array $unknown): void
  {
    // Return immediately if there are no unknown placeholders.
    if (empty($unknown)) return;

    sort($unknown);
    $this->io->text('Unknown placeholder(s):');
    $this->io->listing($unknown);

    $replace = [];
    foreach ($unknown as $placeholder)
    {
      $replace[$placeholder] = '<error>'.$placeholder.'</error>';
    }
    $code = strtr(OutputFormatter::escape($this->routineSourceCode), $replace);

    $this->io->text(explode(PHP_EOL, $code));

    throw new RoutineLoaderException('Unknown placeholder(s) found');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the description of a parameter as found in the DocBlock of the stored routine.
   *
   * @param string $name The name of the parameter.
   *
   * @return string|null
   */
  private function parameterDescription(string $name): ?string
  {
    $key = RowSetHelper::searchInRowSet($this->docBlockPartsSource['parameters'] ?? [], 'name', $name);
    if ($key!==null) return $this->docBlockPartsSource['parameters'][$key]['description'];

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the type of a parameter as found in the DocBlock of the stored routine.
   *
   * @param string $name The name of the parameter.
   *
   * @return string|null
   */
  private function parameterType(string $name): ?string
  {
    $key = RowSetHelper::searchInRowSet($this->docBlockPartsSource['parameters'] ?? [], 'name', $name);
    if ($key!==null) return $this->docBlockPartsSource['parameters'][$key]['type'];

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads the source code of the stored routine.
   */
  private function readSourceCode(): void
  {
    $this->routineSourceCode      = file_get_contents($this->sourceFilename);
    $this->routineSourceCodeLines = explode(PHP_EOL, $this->routineSourceCode);

    if ($this->routineSourceCodeLines===false)
    {
      throw new RoutineLoaderException('Source file is empty');
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Adds magic constants to replace list.
   */
  private function setMagicConstants(): void
  {
    $real_path = realpath($this->sourceFilename);

    $this->replace['__FILE__']    = $this->db->quoteString($real_path);
    $this->replace['__ROUTINE__'] = $this->db->quoteString($this->routineName);
    $this->replace['__DIR__']     = $this->db->quoteString(dirname($real_path));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes magic constants from current replace list.
   */
  private function unsetMagicConstants(): void
  {
    unset($this->replace['__FILE__']);
    unset($this->replace['__ROUTINE__']);
    unset($this->replace['__DIR__']);
    unset($this->replace['__LINE__']);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Updates the metadata for the stored routine.
   */
  private function updateMetadata(): void
  {
    $this->phpStratumMetadata['routine_name'] = $this->routineName;
    $this->phpStratumMetadata['designation']  = $this->designationType;
    $this->phpStratumMetadata['return']       = $this->returnType;
    $this->phpStratumMetadata['parameters']   = $this->parameters;
    $this->phpStratumMetadata['phpdoc']       = $this->docBlockPartsWrapper;
    $this->phpStratumMetadata['offset']       = $this->offset;
    $this->phpStratumMetadata['source']       = $this->routinePayLoad;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Validates the parameters found the DocBlock in the source of the stored routine against the parameters from the
   * metadata of MySQL and reports missing and unknown parameters names.
   */
  private function validateParameterLists(): void
  {
    // Make list with names of parameters used in database.
    $database_parameters_names = [];
    foreach ($this->parameters as $parameter_info)
    {
      $database_parameters_names[] = $parameter_info['name'];
    }

    // Make list with names of parameters used in dock block of routine.
    $doc_block_parameters_names = [];
    if (isset($this->docBlockPartsSource['parameters']))
    {
      foreach ($this->docBlockPartsSource['parameters'] as $parameter)
      {
        $doc_block_parameters_names[] = $parameter['name'];
      }
    }

    // Check and show warning if any parameters is missing in DocBlock.
    $diff1 = array_diff($database_parameters_names, $doc_block_parameters_names);
    foreach ($diff1 as $name)
    {
      $this->io->logNote('Parameter <dbo>%s</dbo> is missing from DocBlock', $name);
    }

    // Check and show warning if find unknown parameters in DocBlock.
    $diff2 = array_diff($doc_block_parameters_names, $database_parameters_names);
    foreach ($diff2 as $name)
    {
      $this->io->logNote('Unknown parameter <dbo>%s</dbo> found in the DocBlock', $name);
    }

    if (!empty($diff1) || !empty($diff2))
    {
      throw new RoutineLoaderException('Invalid parameter docblock');
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Validates the specified return type of the stored routine.
   */
  private function validateReturnType(): void
  {
    // Return immediately if designation type is not appropriate for this method.
    if (!in_array($this->designationType, ['function', 'singleton0', 'singleton1'])) return;

    $types = explode('|', $this->returnType);
    $diff  = array_diff($types, ['string', 'int', 'float', 'double', 'bool', 'null']);

    if (!($this->returnType=='mixed' || $this->returnType=='bool' || empty($diff)))
    {
      throw new RoutineLoaderException("Return type must be 'mixed', 'bool', or a combination of 'int', 'float', 'string', and 'null'");
    }

    // The following tests are applicable for singleton0 routines only.
    if (!in_array($this->designationType, ['singleton0'])) return;

    // Return mixed is OK.
    if (in_array($this->returnType, ['bool', 'mixed'])) return;

    // In all other cases return type must contain null.
    $parts = explode('|', $this->returnType);
    $key   = array_search('null', $parts);
    if ($key===false)
    {
      throw new RoutineLoaderException("Return type must be 'mixed', 'bool', or contain 'null' (with a combination of 'int', 'float', and 'string')");
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
