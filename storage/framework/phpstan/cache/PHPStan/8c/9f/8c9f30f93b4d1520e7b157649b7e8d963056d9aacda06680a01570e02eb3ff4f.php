<?php declare(strict_types = 1);

// odsl-/home/daytona/codebase/app/Models/WalletTransaction.php-PHPStan\BetterReflection\Reflection\ReflectionClass-App\Models\WalletTransaction
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.70.0.3-8.3.33-95e0b4be4d36b5de428dc835b5ef1f4c435dacd35df2e2f2f022dc16ca793d55',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'App\\Models\\WalletTransaction',
        'filename' => '/home/daytona/codebase/app/Models/WalletTransaction.php',
      ),
    ),
    'namespace' => 'App\\Models',
    'name' => 'App\\Models\\WalletTransaction',
    'shortName' => 'WalletTransaction',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 9,
    'endLine' => 43,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Database\\Eloquent\\Model',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory',
    ),
    'immediateConstants' => 
    array (
      'TYPE_CREDIT' => 
      array (
        'declaringClassName' => 'App\\Models\\WalletTransaction',
        'implementingClassName' => 'App\\Models\\WalletTransaction',
        'name' => 'TYPE_CREDIT',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'credit\'',
          'attributes' => 
          array (
            'startLine' => 13,
            'endLine' => 13,
            'startTokenPos' => 45,
            'startFilePos' => 272,
            'endTokenPos' => 45,
            'endFilePos' => 279,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 13,
        'endLine' => 13,
        'startColumn' => 5,
        'endColumn' => 40,
      ),
      'TYPE_DEBIT' => 
      array (
        'declaringClassName' => 'App\\Models\\WalletTransaction',
        'implementingClassName' => 'App\\Models\\WalletTransaction',
        'name' => 'TYPE_DEBIT',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'debit\'',
          'attributes' => 
          array (
            'startLine' => 15,
            'endLine' => 15,
            'startTokenPos' => 56,
            'startFilePos' => 313,
            'endTokenPos' => 56,
            'endFilePos' => 319,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 15,
        'endLine' => 15,
        'startColumn' => 5,
        'endColumn' => 38,
      ),
      'TYPE_REFUND' => 
      array (
        'declaringClassName' => 'App\\Models\\WalletTransaction',
        'implementingClassName' => 'App\\Models\\WalletTransaction',
        'name' => 'TYPE_REFUND',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'refund\'',
          'attributes' => 
          array (
            'startLine' => 17,
            'endLine' => 17,
            'startTokenPos' => 67,
            'startFilePos' => 354,
            'endTokenPos' => 67,
            'endFilePos' => 361,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 17,
        'endLine' => 17,
        'startColumn' => 5,
        'endColumn' => 40,
      ),
      'TYPE_ADJUSTMENT' => 
      array (
        'declaringClassName' => 'App\\Models\\WalletTransaction',
        'implementingClassName' => 'App\\Models\\WalletTransaction',
        'name' => 'TYPE_ADJUSTMENT',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'adjustment\'',
          'attributes' => 
          array (
            'startLine' => 19,
            'endLine' => 19,
            'startTokenPos' => 78,
            'startFilePos' => 400,
            'endTokenPos' => 78,
            'endFilePos' => 411,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 48,
      ),
    ),
    'immediateProperties' => 
    array (
      'fillable' => 
      array (
        'declaringClassName' => 'App\\Models\\WalletTransaction',
        'implementingClassName' => 'App\\Models\\WalletTransaction',
        'name' => 'fillable',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'wallet_id\', \'type\', \'amount_toman\', \'balance_after\', \'reference_type\', \'reference_id\', \'description\']',
          'attributes' => 
          array (
            'startLine' => 21,
            'endLine' => 29,
            'startTokenPos' => 87,
            'startFilePos' => 441,
            'endTokenPos' => 110,
            'endFilePos' => 606,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 21,
        'endLine' => 29,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
    ),
    'immediateMethods' => 
    array (
      'casts' => 
      array (
        'name' => 'casts',
        'parameters' => 
        array (
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
        'docComment' => NULL,
        'startLine' => 31,
        'endLine' => 37,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'App\\Models',
        'declaringClassName' => 'App\\Models\\WalletTransaction',
        'implementingClassName' => 'App\\Models\\WalletTransaction',
        'currentClassName' => 'App\\Models\\WalletTransaction',
        'aliasName' => NULL,
      ),
      'wallet' => 
      array (
        'name' => 'wallet',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 39,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'App\\Models',
        'declaringClassName' => 'App\\Models\\WalletTransaction',
        'implementingClassName' => 'App\\Models\\WalletTransaction',
        'currentClassName' => 'App\\Models\\WalletTransaction',
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