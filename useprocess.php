<?php

require 'process.php';

function useprocess() {
  $process = new XProcess();
  $process->put ( DBAccess::InitializeDB() );
  $process->put ( XCore::MakeDup() );
  $process->put ( DBAccess::StartTransaction() );
  $process->put ( DBAccess::SelectFromDB(), ['select * from user']);
  $process->put ( View::Template(), ['users.t']);

  $branchA = new XProcess();
  $branchA->put( XCore::Log(), ['Message A']);

  $branchB = new XProcess();
  $branchB->put( XCore::Log(), ['Message B']);

  $process->branch( 
    XCore::Inline(0, function() { return rand(0,1) == 1; }),
    $branchA,
    $branchB
  );

  $subProcess = XCore::Process();
  $subProcess->put(XCore::Identity(), ["Message from subprocess"]);

  $process->put($subProcess);
  $process->put( XCore::Log() );
  $process->put( XCore::InlineTailRecursion(2, 
    function($acc, $count) {
      if ($count > 1) {
        return XCore::Again([$acc * $count,$count-1]);
      }
      else {
        return $acc;
      }
    }), [1,5]);
  $process->put( XCore::Log() );

  $exceptionProcess = XCore::Process();
  $exceptionProcess->put( XCore::Log(), ["About to throw an error"]);
  $exceptionProcess->put( XCore::Identity(), ["Message to throw"]);
  $exceptionProcess->put( XCore::Interrupt());
  $exceptionProcess->put( XCore::Identity(), ["Won't be returned"]);
  
  $handler = XCore::Process();
  $handler->put(XCore::Log(),["Log from handler"]);
  $handler->put(XCore::Log());

  $process->put(XCore::Trap($exceptionProcess, $handler));
  
  $process->put(XCore::Log(),["DoneWithSingleException"]);

  $wontHandle = XCore::Process();
  $wontHandle->put(XCore::Log(),["About to NOT handle exception"]);

  $trueCase = XCore::Process();
  $trueCase->put( XCore::Log(), ["Handled"]);

  $falseCase = XCore::Process();
  $falseCase->put( XCore::Log(), ["Not handling.."]);
  $falseCase->put( XCore::Interrupt() );
  
  $wontHandle->branch(XCore::PushFalse(),$trueCase, $falseCase);

  $finallyAction = XCore::Process();
  $finallyAction->put( XCore::Log(), ["Finally action"] );

  $process->put(XCore::TrapFinally(
    XCore::Trap($exceptionProcess, $wontHandle),
    $handler,
    $finallyAction
  ));

  $throwInFinally = XCore::Process();
  $throwInFinally->put( XCore::Log(), ["Finally and throw"] );
  $throwInFinally->put( XCore::Interrupt(), ["Thrown from finally"]);

  $process->put(XCore::Trap(
    XCore::TrapFinally($exceptionProcess, $wontHandle, $throwInFinally),
    $handler
  ));

  $process->put(XCore::Log(), ["DONE"]);
  $process->execute();
}

