<?php

namespace Dashifen\Composer;

use Closure;

interface BumperInterface
{
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
  public static function flagExists(string $flag): bool;
  
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
  public function setFileGetter(Closure $fileGetter): void;
  
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
  public function bump(bool $simulate = false, bool $commit = true): void;
}
