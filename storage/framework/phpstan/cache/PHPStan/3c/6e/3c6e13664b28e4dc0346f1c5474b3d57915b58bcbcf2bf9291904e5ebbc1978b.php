<?php declare(strict_types = 1);

// osfsl-/home/daytona/codebase/vendor/composer/../filament/forms/src/Components/Concerns/HasNestedRecursiveValidationRules.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Filament\Forms\Components\Concerns\HasNestedRecursiveValidationRules
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-e4f0146724c32a20c72136f9cd94e1ce05e2b5fff1e1ee2b075a77713ec279c1-8.3.33-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'filename' => '/home/daytona/codebase/vendor/composer/../filament/forms/src/Components/Concerns/HasNestedRecursiveValidationRules.php',
      ),
    ),
    'namespace' => 'Filament\\Forms\\Components\\Concerns',
    'name' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
    'shortName' => 'HasNestedRecursiveValidationRules',
    'isInterface' => false,
    'isTrait' => true,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 7,
    'endLine' => 55,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'nestedRecursiveValidationRules' => 
      array (
        'declaringClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'implementingClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'name' => 'nestedRecursiveValidationRules',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '[]',
          'attributes' => 
          array (
            'startLine' => 12,
            'endLine' => 12,
            'startTokenPos' => 28,
            'startFilePos' => 205,
            'endTokenPos' => 29,
            'endFilePos' => 206,
          ),
        ),
        'docComment' => '/**
 * @var array<mixed>
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 12,
        'endLine' => 12,
        'startColumn' => 5,
        'endColumn' => 57,
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
      'nestedRecursiveRules' => 
      array (
        'name' => 'nestedRecursiveRules',
        'parameters' => 
        array (
          'rules' => 
          array (
            'name' => 'rules',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionUnionType',
              'data' => 
              array (
                'types' => 
                array (
                  0 => 
                  array (
                    'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                    'data' => 
                    array (
                      'name' => 'string',
                      'isIdentifier' => true,
                    ),
                  ),
                  1 => 
                  array (
                    'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                    'data' => 
                    array (
                      'name' => 'array',
                      'isIdentifier' => true,
                    ),
                  ),
                ),
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
            'startColumn' => 42,
            'endColumn' => 62,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'condition' => 
          array (
            'name' => 'condition',
            'default' => 
            array (
              'code' => 'true',
              'attributes' => 
              array (
                'startLine' => 17,
                'endLine' => 17,
                'startTokenPos' => 59,
                'startFilePos' => 363,
                'endTokenPos' => 59,
                'endFilePos' => 366,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionUnionType',
              'data' => 
              array (
                'types' => 
                array (
                  0 => 
                  array (
                    'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                    'data' => 
                    array (
                      'name' => 'bool',
                      'isIdentifier' => true,
                    ),
                  ),
                  1 => 
                  array (
                    'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                    'data' => 
                    array (
                      'name' => 'Closure',
                      'isIdentifier' => false,
                    ),
                  ),
                ),
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
            'startColumn' => 65,
            'endColumn' => 96,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'static',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param  string | array<mixed>  $rules
 */',
        'startLine' => 17,
        'endLine' => 29,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Forms\\Components\\Concerns',
        'declaringClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'implementingClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'currentClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'aliasName' => NULL,
      ),
      'getNestedRecursiveValidationRules' => 
      array (
        'name' => 'getNestedRecursiveValidationRules',
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
        'docComment' => '/**
 * @return array<mixed>
 */',
        'startLine' => 34,
        'endLine' => 47,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Forms\\Components\\Concerns',
        'declaringClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'implementingClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'currentClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'aliasName' => NULL,
      ),
      'dehydrateValidationAttributes' => 
      array (
        'name' => 'dehydrateValidationAttributes',
        'parameters' => 
        array (
          'attributes' => 
          array (
            'name' => 'attributes',
            'default' => NULL,
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
            'byRef' => true,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 49,
            'endLine' => 49,
            'startColumn' => 51,
            'endColumn' => 68,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 49,
        'endLine' => 54,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Forms\\Components\\Concerns',
        'declaringClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'implementingClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
        'currentClassName' => 'Filament\\Forms\\Components\\Concerns\\HasNestedRecursiveValidationRules',
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