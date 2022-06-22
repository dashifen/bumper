<?php

namespace Dashifen\Composer;

use Dashifen\Exception\Exception;

class BumperException extends Exception
{
  public const INVALID_SEMVER = 1;
  public const INVALID_BRANCH = 2;
  public const UNKNOWN_FILES  = 3;
  public const UNKNOWN_FILE   = 4;
  
}
