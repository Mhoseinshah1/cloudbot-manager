<?php declare(strict_types = 1);

// phpinternal-PHPStan\BetterReflection\Reflection\ReflectionFunction-random_int
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.70.0.3-dev-master@709e512-8.3.33',
   'data' => 
  array (
    'name' => 'random_int',
    'parameters' => 
    array (
      'min' => 
      array (
        'name' => 'min',
        'default' => NULL,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'isVariadic' => false,
        'byRef' => false,
        'isPromoted' => false,
        'attributes' => 
        array (
        ),
        'startLine' => 17,
        'endLine' => 17,
        'startColumn' => 25,
        'endColumn' => 32,
        'parameterIndex' => 0,
        'isOptional' => false,
      ),
      'max' => 
      array (
        'name' => 'max',
        'default' => NULL,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'isVariadic' => false,
        'byRef' => false,
        'isPromoted' => false,
        'attributes' => 
        array (
        ),
        'startLine' => 17,
        'endLine' => 17,
        'startColumn' => 35,
        'endColumn' => 42,
        'parameterIndex' => 1,
        'isOptional' => false,
      ),
    ),
    'returnsReference' => false,
    'returnType' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
      'data' => 
      array (
        'name' => 'int',
        'isIdentifier' => true,
      ),
    ),
    'attributes' => 
    array (
    ),
    'docComment' => '/**
 * Generates cryptographically secure pseudo-random integers
 * @link https://php.net/manual/en/function.random-int.php
 * @param int $min The lowest value to be returned, which must be PHP_INT_MIN or higher.
 * @param int $max The highest value to be returned, which must be less than or equal to PHP_INT_MAX.
 * @return int Returns a cryptographically secure random integer in the range min to max, inclusive.
 * @since 7.0
 * @throws Random\\RandomException if an appropriate source of randomness cannot be found.
 */',
    'startLine' => 17,
    'endLine' => 19,
    'startColumn' => 5,
    'endColumn' => 5,
    'couldThrow' => false,
    'isClosure' => false,
    'isGenerator' => false,
    'isVariadic' => false,
    'isStatic' => false,
    'namespace' => NULL,
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\InternalLocatedSource',
      'data' => 
      array (
        'name' => 'random_int',
        'filename' => 'phpstorm-stubs:random/random.stub',
        'extensionName' => 'random',
        'aliasName' => NULL,
      ),
    ),
  ),
));