<?php

namespace Dashifen\Composer;

use Closure;
use Dashifen\Version\Version;
use Dashifen\Git\Traits\GitAwareTrait;
use PHLAK\SemVer\Exceptions\InvalidVersionException;

class Bumper implements BumperInterface
{
  use GitAwareTrait;
  
  protected Closure $fileGetter;
  protected Version $current;
  protected Version $next;
  protected array $files;
  
  public function __construct()
  {
    // by default, our file getter checks for a composer.json file and then
    // decodes it to look for the bump property of the extra object.  if it can
    // decode that, or if it can't find the JSON file at all, it returns an
    // empty array.
    
    $this->fileGetter = fn() => is_file('composer.json')
      ? json_decode(file_get_contents('composer.json'))->extra->bump ?? []
      : [];
  }
  
  /**
   * flagExists
   *
   * Returns true if the specified flag exists as a command line argument sent
   * to the bump script.
   *
   * @param string $flag
   *
   * @return bool
   */
  public static function flagExists(string $flag): bool
  {
    return in_array($flag, $_SERVER['argv'] ?? []);
  }
  
  /**
   * setFileGetter
   *
   * In situations where folks need to do something other than the default
   * file getter that's defined in the constructor, this method lets them send
   * a different Closure into this object for use in the bump method below.
   *
   * @param Closure $fileGetter
   *
   * @return void
   */
  public function setFileGetter(Closure $fileGetter): void
  {
    // we could do more work here to confirm that the parameter is appropriate,
    // but much of that work is done in the bump method, so we'll just let it
    // handle things.
    
    $this->fileGetter = $fileGetter;
  }
  
  /**
   * bump
   *
   * This method calculates the next version and updates version numbers found
   * in the files specified by our file getter as specified.  If files are
   * changed, those changes are also committed by default.
   *
   * @param bool $commit
   * @param bool $simulate
   *
   * @return void
   * @throws BumperException
   */
  public function bump(bool $simulate = false, bool $commit = true): void
  {
    $this->setFiles();
    $this->setVersions();
    $this->calculateNextVersion();
    
    $this->echo('The next version is ' . $this->next . '.');
    $this->echo(sprintf('Files(s) to bump: %s', join(', ', array_keys($this->files))));
    
    if (!$this->canBump()) {
      $this->echo('Cannot bump.', 'error');
      exit;
    }
    
    if ($this->bumpFiles($simulate)) {
      $this->echo('Bump simulation complete.', 'success');
      exit;
    }
    
    $reminder = !$commit
      ? "Don't forget to update your local git repo!"
      : $this->updateLocalRepo();
    
    $this->echo($reminder, 'reminder');
    $this->echo('Bump complete.', 'success');
  }
  
  /**
   * setFiles
   *
   * Uses the fileGetter property to identify the files that we need to bump.
   *
   * @return void
   * @throws BumperException
   */
  protected function setFiles(): void
  {
    $filenames = $this->fileGetter->call($this);
    if (!is_array($filenames) || sizeof($filenames) === 0) {
      throw new BumperException('There are no files to bump.',
        BumperException::UNKNOWN_FILES);
    }
    
    // now that we have a list of files, we need to identify the version
    // specified in them.  we can use array_map to do so and then array_combine
    // to set our $files property to an array of filenames mapped to their
    // versions.
    
    $versions = array_map([$this, 'findVersionNumber'], $filenames);
    $this->files = array_combine($filenames, $versions);
  }
  
  /**
   * findVersionNumber
   *
   * Searches through the file to find the version number and returns it or
   * null if no version can be found.
   *
   * @param string $file
   *
   * @return Version|null
   */
  protected function findVersionNumber(string $file): ?Version
  {
    if (($content = file_get_contents($file)) !== false) {
      foreach (explode("\n", $content) as $line) {
        
        // here we loop over each line in the file looking for one that might
        // be the one that has a version number on it.  if we find such a line,
        // we actually try to extract that number and if we can do so, we use
        // it.
        
        if ($this->isVersionLine($line)) {
          $oldVersion = $this->maybeFindVersion($line);
          if ($oldVersion !== null) {
            return $oldVersion;
          }
        }
      }
    }
    
    return null;
  }
  
  /**
   * isVersionLine
   *
   * Returns true if the specified line appears to be the one on which a
   * version can be found.
   *
   * @param string $line
   *
   * @return bool
   */
  protected function isVersionLine(string $line): bool
  {
    // there are two cases that we want to check for by default:  a match in a
    // WordPress plugin or theme header and a match in JSON files.  so, we need
    // to match both version: and "version": here.
    
    return (bool) preg_match('/(?:version|"version"):/i', $line);
  }
  
  /**
   * maybeFindVersion
   *
   * Given a line that might contain a version number, confirm whether or not
   * it does and return a Version object if so or null otherwise.
   *
   * @param string $line
   *
   * @return Version|null
   */
  protected function maybeFindVersion(string $line): ?Version
  {
    // the pattern matches everything except for dots, plus signs, and digits.
    // these are the characters that can make up a semantic version.  anything
    // else is replaced with an empty string.  that should leave us with just
    // something that might be a version number.
    
    try {
      return new Version(preg_replace('/[^.+\d]/', '', $line));
    } catch (InvalidVersionException) {
      return null;
    }
  }
  
  /**
   * setVersions
   *
   * Getting an ordered list of this repo's tags, constructs and sets both the
   * current and next properties.
   *
   * @return void
   * @throws BumperException
   */
  protected function setVersions(): void
  {
    // the getGitTags method of our GitAwareTrait explicitly returns only tags
    // that match semantic versioning patterns.  even better:  it returns them
    // in reverse order, so the most recent version is first.  therefore, to
    // find the current tag, all we need to do is execute that function grab
    // the first one.  if we can't find any such tags, we assume we're at the
    // first version.
    
    $currentTag = $this->getGitTags()[0] ?? '1.0.0';
    
    try {
      
      // we set both of these Version properties here, but we want explicitly
      // different objects.  that's because the calculateNextVersion method
      // will change the next property, so we can't have them be references for
      // each other.
      
      $this->current = new Version($currentTag);
      $this->next = new Version($currentTag);
    } catch (InvalidVersionException $e) {
      
      // here, we just want to convert the InvalidVersionException to one of
      // our own to reduce the number of exceptions the scope using this object
      // needs to care about.
      
      throw new BumperException(
        $currentTag . ' is an invalid semantic version.',
        BumperException::INVALID_SEMVER,
        $e
      );
    }
  }
  
  /**
   * calculateNextVersion
   *
   * Uses the name of the current branch to determine the next version number
   * after the current one.
   *
   * @return void
   * @throws BumperException
   */
  protected function calculateNextVersion(): void
  {
    if (($branch = $this->getGitBranch())->isTypeUnknown()) {
      throw new BumperException($branch . ' is an invalid branch name.',
        BumperException::INVALID_BRANCH);
    }
    
    match (true) {
      $branch->isRelease() => $this->doReleaseCalculation(),
      $branch->isFeature() => $this->doFeatureCalculation(),
      $branch->isBugFix()  => $this->doBugFixCalculation(),
      default              => $this->doBuildCalculation(),
    };
  }
  
  /**
   * doReleaseCalculation
   *
   * Calculates the next version for a release, i.e. 1.2.3 becoming 2.0.0.
   *
   * @return void
   */
  protected function doReleaseCalculation(): void
  {
    $this->next
      ->setMajor($this->next->getMajor() + 1)
      ->setMinor(0)
      ->setPatch(0)
      ->setBuild(null);
  }
  
  /**
   * doFeatureCalculation
   *
   * Calculates the next version for a new feature, i.e. 1.2.3 becoming 1.3.0.
   *
   * @return void
   */
  protected function doFeatureCalculation(): void
  {
    $this->next->setMinor($this->next->getMinor() + 1)
      ->setPatch(0)
      ->setBuild(null);
  }
  
  /**
   * doBugFixCalculation
   *
   * Calculates the next version for a release, i.e. 1.2.3 becoming 1.2.4.
   *
   * @return void
   */
  protected function doBugFixCalculation(): void
  {
    $this->next->setPatch($this->next->getPatch() + 1)
      ->setBuild(null);
  }
  
  /**
   * doBuildCalculation
   *
   * Calculates the next version for a build of an unknown type, i.e. 1.2.3
   * becoming 1.2.3+1.
   *
   * @return void
   */
  protected function doBuildCalculation(): void
  {
    // it's unlikely that we need this calculation regularly, but it's the
    // default case for the match statement in the above calculateNextVersion
    // method.
    
    $this->next->setBuild($this->next->getBuild() + 1);
  }
  
  /**
   * echo
   *
   * Prints $info to STDOUT and adds a new line character after it.  The type
   * parameter is also emitted prior to $info if it's anything other than the
   * default producing lines like "WARNING:  Goose approaching!"
   *
   * @param string $info
   * @param string $type
   *
   * @return void
   */
  protected function echo(string $info, string $type = 'info'): void
  {
    echo $type !== 'info'
      ? strtoupper($type) . ': ' . $info . PHP_EOL
      : $info . PHP_EOL;
  }
  
  /**
   * canBump
   *
   * Analyzes the list of files that we want to bump and returns true if all of
   * them can be bumped.
   *
   * @return bool
   */
  protected function canBump(): bool
  {
    $messages = [
      'missing' => 'Unable to identify version in %s.',
      'newer'   => 'Found %s in %s greater than or equal to %s.',
    ];
    
    $canBump = true;
    foreach ($this->files as $filename => $version) {
      if ($version === null) {
        
        // if we don't have a version at all, then this probably isn't the
        // correct file.  we'll emit a warning and then indicate that we can't
        // bump.  the continue statement is so that we don't emit the next
        // warning for this file, too.
        
        $this->echo(sprintf($messages['missing'], $filename), 'warning');
        $canBump = false;
        continue;
      }
      
      if (version_compare($version, $this->next, '>=')) {
        
        // the PHP version_compare function will return true if $version is
        // greater than or equal to $this->next due to our use of that operator
        // as the third parameter.  clearly, something's up if the current
        // version is greater than what we want to bump to, so we'll have to
        // let a dev figure out what's going on in this case.
        
        $message = sprintf($messages['newer'], $version, $filename, $this->next);
        $this->echo($message, 'warning');
        $canBump = false;
      }
    }
    
    return $canBump;
  }
  
  protected function bumpFiles(bool $simulate): bool
  {
    $message = $simulate
      ? 'Simulating bump to %s from %s to %s.'
      : 'Bumping %s from %s to %s.';
    
    foreach ($this->files as $filename => $version) {
      $this->echo(sprintf($message, $filename, $version, $this->next));
      if (!$simulate) {
        
        // if we're not simulating, we get the content of our file and replace
        // its version with our next one.  we have already scanned these files
        // before, but since this operation is only done locally and not very
        // often, we're not too worried about the performance hit.  plus, the
        // files that contain version numbers are frequently not that long.
        
        $content = file_get_contents($filename);
        $altered = str_replace($version, $this->next, $content);
        file_put_contents($filename, $altered);
      }
    }
    
    return $simulate;
  }
  
  /**
   * updateLocalRepo
   *
   * If we're making changes to the local repo, then this is the method that
   * does so.
   *
   * @return string
   */
  protected function updateLocalRepo(): string
  {
    exec('git add .');
    exec('git commit -m "Versions bumped to ' . $this->next . '."');
    $this->echo('Changed files committed to the repo.');
    return "Don't forget to push these changes!";
  }
}
