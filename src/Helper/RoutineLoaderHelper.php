<?php
declare(strict_types=1);

namespace SetBased\Stratum\SqlitePdo\Helper;

use SetBased\Stratum\Backend\StratumStyle;
use SetBased\Stratum\Common\DocBlock\DocBlockReflection;
use SetBased\Stratum\Common\Exception\RoutineLoaderException;
use SetBased\Stratum\SqlitePdo\SqlitePdoDataLayer;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Class for mimicking loading a single stored routine into a SQLite instance from pseudo SQL file.
 */
class RoutineLoaderHelper
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The designation type of the stored routine.
   *
   * @var string
   */
  private string $designationType;

  /**
   * An in memory SQLite database.
   *
   * @var SqlitePdoDataLayer
   */
  private SqlitePdoDataLayer $dl;

  /**
   * The reflection of the DocBlock of the stored routine.
   *
   * @var DocBlockReflection
   */
  private DocBlockReflection $docBlockReflection;

  /**
   * The Output decorator
   *
   * @var StratumStyle
   */
  private StratumStyle $io;

  /**
   * The offset of the first line of the payload of the stored routine ins the source file.
   *
   * @var int
   */
  private int $offset;

  /**
   * The metadata of the stored routine. Note: this data is stored in the metadata file and is generated by PhpStratum.
   *
   * @var array
   */
  private array $phpStratumMetadata;

  /**
   * The replace pairs (i.e. placeholders and their actual values, see strtr).
   *
   * @var array
   */
  private array $replace = [];

  /**
   * A map from placeholders to their actual values.
   *
   * @var array
   */
  private array $replacePairs = [];

  /**
   * The return type of the stored routine (only if designation type singleton0, singleton1, or function).
   *
   * @var string|null
   */
  private ?string $returnType = null;

  /**
   * The name of the stored routine.
   *
   * @var string
   */
  private string $routineName;

  /**
   * The routine parameters.
   *
   * @var RoutineParametersHelper|null
   */
  private ?RoutineParametersHelper $routineParameters = null;

  /**
   * The payload of the stored routine (i.e. the code without the DocBlock).
   *
   * @var string
   */
  private string $routinePayLoad;

  /**
   * The source code as a single string of the stored routine.
   *
   * @var string
   */
  private string $routineSourceCode;

  /**
   * The source code as an array of lines string of the stored routine.
   *
   * @var array
   */
  private array $routineSourceCodeLines;

  /**
   * The source filename holding the stored routine.
   *
   * @var string
   */
  private string $sourceFilename;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object constructor.
   *
   * @param StratumStyle $io              The output for log messages.
   * @param string       $routineFilename The filename of the source of the stored routine.
   */
  public function __construct(StratumStyle $io, string $routineFilename)
  {
    $this->dl             = new SqlitePdoDataLayer();
    $this->io             = $io;
    $this->sourceFilename = $routineFilename;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads the stored routine into the instance of SQLite and returns the metadata of the stored routine.
   *
   * @return array
   */
  public function loadStoredRoutine(): array
  {
    $this->routineName = pathinfo($this->sourceFilename, PATHINFO_FILENAME);

    $this->io->text(sprintf('Loading routine <dbo>%s</dbo>', OutputFormatter::escape($this->routineName)));

    $this->readSourceCode();
    $this->createDocBlockReflection();
    $this->extractParameters();
    $this->extractPlaceholders();
    $this->extractDesignationType();
    $this->extractReturnType();
    $this->validateReturnType();

    $this->loadRoutineFile();

    $this->updateMetadata();

    return $this->phpStratumMetadata;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Creates the DocBlock reflection object.
   */
  private function createDocBlockReflection(): void
  {
    $start = $this->findFirstMatchingLine('/^\s*\/\*\*\s*$/');
    $end   = $this->findFirstMatchingLine('/^\s*\*\/\s*$/');
    if ($start!==null && $end!==null && $start<$end)
    {
      $lines    = array_slice($this->routineSourceCodeLines, $start, $end - $start + 1);
      $docBlock = implode(PHP_EOL, (array)$lines);
    }
    else
    {
      $docBlock = '';
    }

    DocBlockReflection::setTagParameters('param', ['type', 'name']);
    DocBlockReflection::setTagParameters('type', ['type']);
    DocBlockReflection::setTagParameters('return', ['type']);

    $this->docBlockReflection = new DocBlockReflection($docBlock);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts the designation type of the stored routine.
   */
  private function extractDesignationType(): void
  {
    $tags = $this->docBlockReflection->getTags('type');
    if (count($tags)===0)
    {
      throw new RoutineLoaderException('Tag @type not found in DocBlock.');
    }
    elseif (count($tags)>1)
    {
      throw new RoutineLoaderException('Multiple @type tags found in DocBlock.');
    }

    $tag                   = $tags[0];
    $this->designationType = $tag['arguments']['type'];
    if ($this->designationType===null)
    {
      throw new RoutineLoaderException('Unable to find the designation type of the stored routine');
    }

    if (!in_array($this->designationType, ['lastInsertId', 'none', 'row0', 'row1', 'rows', 'singleton0', 'singleton1']))
    {
      throw new RoutineLoaderException("'%s' is not a valid designation type", $this->designationType);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts DocBlock parts to be used by the wrapper generator.
   */
  private function extractDocBlockPartsWrapper(): array
  {
    return ['short_description' => $this->docBlockReflection->getShortDescription(),
            'long_description'  => $this->docBlockReflection->getLongDescription(),
            'parameters'        => $this->routineParameters->extractDocBlockPartsWrapper()];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Extracts routine parameters.
   */
  private function extractParameters()
  {
    $this->routineParameters = new RoutineParametersHelper($this->io,
                                                           $this->docBlockReflection,
                                                           $this->offset,
                                                           $this->routineSourceCodeLines);

    $this->routineParameters->extractRoutineParameters();
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
    $tags = $this->docBlockReflection->getTags('return');

    switch ($this->designationType)
    {
      case 'function':
      case 'singleton0':
      case 'singleton1':
        if (count($tags)===0)
        {
          throw new RoutineLoaderException('Tag @return not found in DocBlock.');
        }
        $tag = $tags[0];
        if ($tag['arguments']['type']==='')
        {
          throw new RoutineLoaderException('Invalid return tag. Expected: @return <type>.');
        }
        $this->returnType = $tag['arguments']['type'];
        break;

      default:
        if (count($tags)!==0)
        {
          throw new RoutineLoaderException('Redundant @type tag found in DocBlock.');
        }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the key of the source line that match a regex pattern.
   *
   * @param string $pattern The regex pattern.
   *
   * @return int|null
   */
  private function findFirstMatchingLine(string $pattern): ?int
  {
    foreach ($this->routineSourceCodeLines as $key => $line)
    {
      if (preg_match($pattern, $line)===1)
      {
        return $key;
      }
    }

    return null;
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

    // Replace all place holders with their values.
    $lines = array_slice($this->routineSourceCodeLines, $this->offset);
    foreach ($lines as $i => &$line)
    {
      $this->replace['__LINE__'] = $this->dl->quoteInt($i + $this->offset + 1);
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

    $this->offset = $this->getFirstLineOfStoredRoutineBody() - 1;
    $lines        = array_slice($this->routineSourceCodeLines, $this->offset);
    while (!empty($lines) && trim($lines[0])==='')
    {
      $this->offset++;
      array_shift($lines);
    }


  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Adds magic constants to replace list.
   */
  private function setMagicConstants(): void
  {
    $real_path = realpath($this->sourceFilename);

    $this->replace['__FILE__']    = $this->dl->quoteVarchar($real_path);
    $this->replace['__ROUTINE__'] = $this->dl->quoteVarchar($this->routineName);
    $this->replace['__DIR__']     = $this->dl->quoteVarchar(dirname($real_path));
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
    $this->phpStratumMetadata['parameters']   = $this->routineParameters->getParameters();
    $this->phpStratumMetadata['phpdoc']       = $this->extractDocBlockPartsWrapper();
    $this->phpStratumMetadata['offset']       = $this->offset;
    $this->phpStratumMetadata['source']       = $this->routinePayLoad;
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
