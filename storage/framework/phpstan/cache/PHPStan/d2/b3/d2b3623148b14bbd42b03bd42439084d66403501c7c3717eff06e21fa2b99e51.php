<?php declare(strict_types = 1);

// osfsl-/home/daytona/codebase/vendor/composer/../filament/actions/src/MountableAction.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Filament\Actions\MountableAction
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-549be5113926c2d7f98252b1b127d2b808545c315d070b48bbfe75665ee7a8a8-8.3.33-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Filament\\Actions\\MountableAction',
        'filename' => '/home/daytona/codebase/vendor/composer/../filament/actions/src/MountableAction.php',
      ),
    ),
    'namespace' => 'Filament\\Actions',
    'name' => 'Filament\\Actions\\MountableAction',
    'shortName' => 'MountableAction',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 64,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 10,
    'endLine' => 86,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Filament\\Actions\\StaticAction',
    'implementsClassNames' => 
    array (
      0 => 'Filament\\Actions\\Contracts\\HasLivewire',
    ),
    'traitClassNames' => 
    array (
      0 => 'Filament\\Actions\\Concerns\\BelongsToLivewire',
      1 => 'Filament\\Actions\\Concerns\\CanBeMounted',
      2 => 'Filament\\Actions\\Concerns\\CanNotify',
      3 => 'Filament\\Actions\\Concerns\\CanOpenModal',
      4 => 'Filament\\Actions\\Concerns\\CanRedirect',
      5 => 'Filament\\Actions\\Concerns\\CanRequireConfirmation',
      6 => 'Filament\\Actions\\Concerns\\CanUseDatabaseTransactions',
      7 => 'Filament\\Actions\\Concerns\\HasExtraModalWindowAttributes',
      8 => 'Filament\\Actions\\Concerns\\HasForm',
      9 => 'Filament\\Actions\\Concerns\\HasInfolist',
      10 => 'Filament\\Actions\\Concerns\\HasLifecycleHooks',
      11 => 'Filament\\Actions\\Concerns\\HasParentActions',
      12 => 'Filament\\Actions\\Concerns\\HasWizard',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
    ),
    'immediateMethods' => 
    array (
      'setUp' => 
      array (
        'name' => 'setUp',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 26,
        'endLine' => 34,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Filament\\Actions',
        'declaringClassName' => 'Filament\\Actions\\MountableAction',
        'implementingClassName' => 'Filament\\Actions\\MountableAction',
        'currentClassName' => 'Filament\\Actions\\MountableAction',
        'aliasName' => NULL,
      ),
      'call' => 
      array (
        'name' => 'call',
        'parameters' => 
        array (
          'parameters' => 
          array (
            'name' => 'parameters',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 39,
                'endLine' => 39,
                'startTokenPos' => 196,
                'startFilePos' => 1159,
                'endTokenPos' => 197,
                'endFilePos' => 1160,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 39,
            'endLine' => 39,
            'startColumn' => 26,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'mixed',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param  array<string, mixed>  $parameters
 */',
        'startLine' => 39,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Actions',
        'declaringClassName' => 'Filament\\Actions\\MountableAction',
        'implementingClassName' => 'Filament\\Actions\\MountableAction',
        'currentClassName' => 'Filament\\Actions\\MountableAction',
        'aliasName' => NULL,
      ),
      'cancel' => 
      array (
        'name' => 'cancel',
        'parameters' => 
        array (
          'shouldRollBackDatabaseTransaction' => 
          array (
            'name' => 'shouldRollBackDatabaseTransaction',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 44,
                'endLine' => 44,
                'startTokenPos' => 236,
                'startFilePos' => 1325,
                'endTokenPos' => 236,
                'endFilePos' => 1329,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'bool',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 44,
            'endLine' => 44,
            'startColumn' => 28,
            'endColumn' => 74,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 44,
        'endLine' => 47,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Actions',
        'declaringClassName' => 'Filament\\Actions\\MountableAction',
        'implementingClassName' => 'Filament\\Actions\\MountableAction',
        'currentClassName' => 'Filament\\Actions\\MountableAction',
        'aliasName' => NULL,
      ),
      'halt' => 
      array (
        'name' => 'halt',
        'parameters' => 
        array (
          'shouldRollBackDatabaseTransaction' => 
          array (
            'name' => 'shouldRollBackDatabaseTransaction',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 49,
                'endLine' => 49,
                'startTokenPos' => 272,
                'startFilePos' => 1511,
                'endTokenPos' => 272,
                'endFilePos' => 1515,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'bool',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 49,
            'endLine' => 49,
            'startColumn' => 26,
            'endColumn' => 72,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 49,
        'endLine' => 52,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Actions',
        'declaringClassName' => 'Filament\\Actions\\MountableAction',
        'implementingClassName' => 'Filament\\Actions\\MountableAction',
        'currentClassName' => 'Filament\\Actions\\MountableAction',
        'aliasName' => NULL,
      ),
      'hold' => 
      array (
        'name' => 'hold',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @deprecated Use `halt()` instead.
 */',
        'startLine' => 57,
        'endLine' => 60,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Actions',
        'declaringClassName' => 'Filament\\Actions\\MountableAction',
        'implementingClassName' => 'Filament\\Actions\\MountableAction',
        'currentClassName' => 'Filament\\Actions\\MountableAction',
        'aliasName' => NULL,
      ),
      'success' => 
      array (
        'name' => 'success',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 62,
        'endLine' => 66,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Actions',
        'declaringClassName' => 'Filament\\Actions\\MountableAction',
        'implementingClassName' => 'Filament\\Actions\\MountableAction',
        'currentClassName' => 'Filament\\Actions\\MountableAction',
        'aliasName' => NULL,
      ),
      'failure' => 
      array (
        'name' => 'failure',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 68,
        'endLine' => 72,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Actions',
        'declaringClassName' => 'Filament\\Actions\\MountableAction',
        'implementingClassName' => 'Filament\\Actions\\MountableAction',
        'currentClassName' => 'Filament\\Actions\\MountableAction',
        'aliasName' => NULL,
      ),
      'resolveDefaultClosureDependencyForEvaluationByName' => 
      array (
        'name' => 'resolveDefaultClosureDependencyForEvaluationByName',
        'parameters' => 
        array (
          'parameterName' => 
          array (
            'name' => 'parameterName',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 77,
            'endLine' => 77,
            'startColumn' => 75,
            'endColumn' => 95,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @return array<mixed>
 */',
        'startLine' => 77,
        'endLine' => 85,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Filament\\Actions',
        'declaringClassName' => 'Filament\\Actions\\MountableAction',
        'implementingClassName' => 'Filament\\Actions\\MountableAction',
        'currentClassName' => 'Filament\\Actions\\MountableAction',
        'aliasName' => NULL,
      ),
    ),
    'traitsData' => 
    array (
      'aliases' => 
      array (
      ),
      'modifiers' => 
      array (
      ),
      'precedences' => 
      array (
      ),
      'hashes' => 
      array (
      ),
    ),
  ),
));