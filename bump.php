<?php

namespace Dashifen\Composer;

// by default, we use the bumper object included within this package.  however,
// sometimes we might want to use an object other than that one.  therefore, we
// allow the "--object" flag on the command line and assume the value in the
// next argument is the object we want to use.  PHP will throw an error if it
// does not exist when we try to instantiate it in the try-block below to help
// protect us from mistakes.

$object = 'Dashifen\Composer\Bumper';
foreach ($_SERVER['argv'] as $i => $arg) {
  if ($arg === '--object') {
    $object = $_SERVER['argv'][$i + 1];
    break;
  }
}

if (!class_exists($object)) {
  require 'vendor/autoload.php';
}

try {
  $bumper = new $object;
  $runSimulation = Bumper::flagExists('simulate');
  
  // if we are running a simulation, then we don't want to commit.  but, if
  // the no-commit flag exists, we also don't want to commit.  the following
  // assignment checks those conditions and we can pass it's results over to
  // the bump method of our Bumper object.  notice that this means the default
  // behavior is to update the local repo after changing the files.
  
  $doCommit = !$runSimulation && !Bumper::flagExists('no-commit');
  $bumper->bump($runSimulation, $doCommit);
} catch (BumperException $e) {
  echo 'Cannot bump because ' . lcfirst($e->getMessage());
}
