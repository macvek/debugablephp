<?php

require 'process.php';

$stack = XCore::Stack();
$atomic = XCore::Atomic("sample",0,function() {
  echo "I'm sample\n";
});

$atomic->trigger($stack);

$withOneArg = XCore::Atomic("withOneArg",1,function($one) {
  echo "My First Arg $one\n";
});

$stack->pushWithOrigin("origin", "Is OK");
$withOneArg->trigger($stack);

$stack->pushWithOrigin("builtin", "BABY");
$pushOntoStack = XCore::Atomic("push onto stack",1, function($arg) {
  return "YEAH RIGHT $arg";
});

$pushOntoStack->trigger($stack);
print_r($stack);
$withOneArg->trigger($stack);


var_dump( function($a,$b) { echo "$a $b\n";});











