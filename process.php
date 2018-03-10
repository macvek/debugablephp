<?php


class DBAccess {
  static function InitializeDB() {
    return XCore::Atomic("InitializeDB", 0, function() {
      echo "initializing database\n";
      return "DB REF";
    });
  }

  static function StartTransaction() {
    return XCore::Atomic("StartTransaction",1, function($db) {
      echo "starting transaction on $db\n";
    });
  } 

  static function SelectFromDB() {
    return XCore::Atomic("SelectFromDB",2, function($db, $query) {
      echo "Triggering query $query on $db\n";
      return ["results" => [1,2,3]];
    });
  }

}

class XCoreAtomic {
  private $desc;
  private $arity;
  private $callback;
  
  public function __construct($desc, $arity, $callback) {
    $this->desc = $desc;
    $this->arity = $arity;
    $this->callback = $callback;
  }

  public function trigger($stack) {
    $args = [];
    $argsOrigin = $stack->pop($this->arity);
    
    foreach ($argsOrigin as $each) {
      $args[] = $each[1];
    }
    $ret = call_user_func_array($this->callback, $args);
    if (NULL !== $ret) {
      $stack->pushWithOrigin([$this->desc,$argsOrigin], $ret);
    }
  }
}

class XCoreTailRecursion extends XCoreAtomic {
  public function trigger($stack) {
    parent::trigger($stack);
    if (XCore::IsForward($stack->peek())) {
      $callDef = $stack->popValue();
      foreach ($callDef->args as $arg) {
        $stack->pushWithOrigin('tailRecursion', $arg);
      }
      return $this;
    }
  }
}

class XCoreStack {
  var $backingArray = [];
  private $limit = 0;

  public function isEmpty() {
    return count($this->backingArray) === 0;
  }

  public function pushStack($otherStack) {
    $this->backingArray = array_merge($this->backingArray, $otherStack->backingArray);
  }

  public function pushWithOrigin($origin, $content) {
    $this->backingArray[] = [$origin,$content];
  }

  public function pop($itemsToRemove) {
    if (count($this->backingArray) - $this->limit -$itemsToRemove < 0) {
      var_dump("STACK UNDERFLOW", $this);
      die(); 
    }
    
    return array_splice($this->backingArray,-$itemsToRemove);
  }

  public function popValue() {
    return $this->pop(1)[0][1];
  }

  public function pushRaw($content) {
    $this->backingArray[] = $content; 
  }

  public function peek() {
    return $this->backingArray[count($this->backingArray)-1];
  }

  public function limitTo($limit) {
    $this->limit = max(0,count($this->backingArray)-$limit);
  }

  public function popToLimit() {
    return $this->pop(max(0,count($this->backingArray)-$this->limit));
  }

  public function dropLimit() {
    $this->limit = 0;
  }


}

class XBuiltIn {
  private $desc;
  private $callback;

  public function __construct($desc, $callback) {
    $this->desc = $desc;
    $this->callback = $callback;
  }

  public function trigger($stack) {
    call_user_func($this->callback,$stack);
  }
};

class XCallForward {
  public function __construct($args) {
    $this->args = $args;
  }
}

class XCoreTrap {
  public function __construct($trapSource, $trapHandler, $finallyAction=NULL) {
    $this->trapSource = $trapSource;
    $this->trapHandler = $trapHandler;
    $this->finallyAction = $finallyAction;
  }

  public function trigger($stack) {
    $this->trapSource->trigger($stack);
    if (XCore::IsInterrupt($stack->peek())) {
      $stack->limitTo(2);
      $stack->popValue();

      $this->trapHandler->trigger($stack);
      /*
       * In case of interrupt not being handled, handler should put flag back on stack 
       */
      $finallyStack = XCore::Stack();
      if ($this->finallyAction) {
        $this->finallyAction->trigger($finallyStack);
        if (!$finallyStack->isEmpty()) {
          $stack->popToLimit();
          $stack->pushStack($finallyStack);
        }
      }
      $stack->dropLimit();
    }
    else {
      $this->finallyAction->trigger($stack);
    }
  }
}

class XRef {}
class XCore {
  static $INTERRUPTED;

  static function IsInterrupt($frame) {
    return $frame[1] === XCore::$INTERRUPTED;
  }

  static function IsForward($frame) {
    return $frame[1] instanceof XCallForward;
  }

  static function Identity() {
    return new XCoreAtomic("identity",1, function($it) {
      return $it;
    });
  }

  static function Stack() {
    return new XCoreStack();
  }

  static function Log() {
    return new XCoreAtomic("log", 1, function($msg) {
      echo "$msg\n";
    });
  }

  static function Again($args) {
    return new XCallForward($args);
  }

  static function Atomic($desc, $arity, $callback) {
    return new XCoreAtomic($desc, $arity, $callback);
  }

  static function TailRecursion($desc, $arity, $callback) {
    return new XCoreTailRecursion($desc, $arity, $callback);
  }

  static function Trap($trapSource, $trapHandler) {
    return new XCoreTrap($trapSource, $trapHandler);
  }

  static function TrapFinally($trapSource, $trapHandler, $finallyHandler) {
    return new XCoreTrap($trapSource, $trapHandler, $finallyHandler);
  }

  static function Inline($arity, $callback) {
    return new XCoreAtomic("inline", $arity, $callback);
  }

  static function InlineTailRecursion($arity, $callback) {
    return new XCoreTailRecursion("inline-tailrecursion", 
      $arity, $callback);
  }

  static function Interrupt() {
    return new XBuiltIn("interrupt", function($stack) {
      $stack->pushWithOrigin("interrupt",XCore::$INTERRUPTED);
    });
  }

  static function Branch($onTrue, $onFalse) {
    return new XBuiltIn("branch", 
      function($stack) use ($onTrue, $onFalse) {
        $result = $stack->popValue();
        if ($result === true) {
          $onTrue->trigger($stack);
        }
        else {
          $onFalse->trigger($stack);
        }
      }
    );
  }

  static function PushTrue() {
    return self::PushArgs([true]);
  }

  static function PushFalse() {
    return self::PushArgs([false]);
  }

  static function PushArgs($args) {
    return new XBuiltIn("PushArgs", function($stack) use ($args) {
      foreach ($args as $each) {
        $stack->pushWithOrigin("const", $each);
      }
    });
  }


  static function MakeDup() {
    return new XBuiltIn("MakeDup", function($stack) {
      $stack->pushRaw($stack->peek());
    });
  }

  static function Process() {
    return new XProcess();
  }
}
XCore::$INTERRUPTED = new XRef(); // just an empty reference


class View {
  static function Template() {
    return XCore::Atomic("Template",2,function($values, $viewName) {
      echo "Showing $viewName with values\n";
      print_r($values);
    });
  }
}

class XProcess {
  private $execs = [];
  private $stack;

  public function execute() {
    $this->stack = XCore::Stack();
    $this->trigger($this->stack);
    if (XCore::IsInterrupt($this->stack->peek())) {
      var_dump("UNHANDLED INTERRUPT", $this->stack);
    }

  }

  public function trigger($stack) {
    foreach($this->execs as $exec) {
      $op = $exec;

      if (XCore::IsInterrupt($stack->peek())) {
        return;
      }

      while($op) {
        $op = $op->trigger($stack);
      }
    }
  }

  public function put($exec, $argsToPush=[]) {
    if (!is_array($argsToPush)) {
      var_dump($exec,$argsToPush);
      die("argsToPush is not an array");
    }

    if (count($argsToPush) > 0) {
      $this->execs[] = XCore::PushArgs($argsToPush);
    }
    $this->execs[] = $exec;
  }

  public function branch($condition, $onTrue, $onFalse) {
    $this->put($condition);
    $this->put( XCore::Branch($onTrue, $onFalse));
  }
}
