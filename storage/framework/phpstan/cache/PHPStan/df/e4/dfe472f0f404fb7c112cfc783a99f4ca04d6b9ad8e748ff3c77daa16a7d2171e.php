<?php declare(strict_types = 1);

// osfsl-/home/daytona/codebase/vendor/composer/../filament/filament/src/Resources/Pages/Concerns/HasRelationManagers.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Filament\Resources\Pages\Concerns\HasRelationManagers
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-1f7d0d401b29d6053782aa6aa02f7e84d268305e318cc2234c919790ed7abaf5-8.3.33-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'filename' => '/home/daytona/codebase/vendor/composer/../filament/filament/src/Resources/Pages/Concerns/HasRelationManagers.php',
      ),
    ),
    'namespace' => 'Filament\\Resources\\Pages\\Concerns',
    'name' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
    'shortName' => 'HasRelationManagers',
    'isInterface' => false,
    'isTrait' => true,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 11,
    'endLine' => 90,
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
      'activeRelationManager' => 
      array (
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'name' => 'activeRelationManager',
        'modifiers' => 1,
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
                  'name' => 'null',
                  'isIdentifier' => true,
                ),
              ),
            ),
          ),
        ),
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 14,
            'endLine' => 14,
            'startTokenPos' => 51,
            'startFilePos' => 397,
            'endTokenPos' => 51,
            'endFilePos' => 400,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
          0 => 
          array (
            'name' => 'Livewire\\Attributes\\Url',
            'isRepeated' => false,
            'arguments' => 
            array (
            ),
          ),
        ),
        'startLine' => 13,
        'endLine' => 14,
        'startColumn' => 5,
        'endColumn' => 49,
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
      'getAllRelationManagers' => 
      array (
        'name' => 'getAllRelationManagers',
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
 * @return array<class-string<RelationManager> | RelationGroup | RelationManagerConfiguration>
 */',
        'startLine' => 19,
        'endLine' => 22,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Filament\\Resources\\Pages\\Concerns',
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'currentClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'aliasName' => NULL,
      ),
      'getRelationManagers' => 
      array (
        'name' => 'getRelationManagers',
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
 * @return array<class-string<RelationManager> | RelationGroup | RelationManagerConfiguration>
 */',
        'startLine' => 27,
        'endLine' => 41,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Resources\\Pages\\Concerns',
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'currentClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'aliasName' => NULL,
      ),
      'normalizeRelationManagerClass' => 
      array (
        'name' => 'normalizeRelationManagerClass',
        'parameters' => 
        array (
          'manager' => 
          array (
            'name' => 'manager',
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
                      'name' => 'Filament\\Resources\\RelationManagers\\RelationManagerConfiguration',
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
            'startLine' => 47,
            'endLine' => 47,
            'startColumn' => 54,
            'endColumn' => 99,
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
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param  class-string<RelationManager> | RelationManagerConfiguration  $manager
 * @return class-string<RelationManager>
 */',
        'startLine' => 47,
        'endLine' => 54,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Filament\\Resources\\Pages\\Concerns',
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'currentClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'aliasName' => NULL,
      ),
      'renderingHasRelationManagers' => 
      array (
        'name' => 'renderingHasRelationManagers',
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
        'startLine' => 56,
        'endLine' => 69,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Resources\\Pages\\Concerns',
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'currentClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'aliasName' => NULL,
      ),
      'hasCombinedRelationManagerTabsWithContent' => 
      array (
        'name' => 'hasCombinedRelationManagerTabsWithContent',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 71,
        'endLine' => 74,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Resources\\Pages\\Concerns',
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'currentClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'aliasName' => NULL,
      ),
      'getContentTabLabel' => 
      array (
        'name' => 'getContentTabLabel',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
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
                  'name' => 'null',
                  'isIdentifier' => true,
                ),
              ),
            ),
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 76,
        'endLine' => 79,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Resources\\Pages\\Concerns',
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'currentClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'aliasName' => NULL,
      ),
      'getContentTabIcon' => 
      array (
        'name' => 'getContentTabIcon',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
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
                  'name' => 'null',
                  'isIdentifier' => true,
                ),
              ),
            ),
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 81,
        'endLine' => 84,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Resources\\Pages\\Concerns',
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'currentClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'aliasName' => NULL,
      ),
      'getContentTabPosition' => 
      array (
        'name' => 'getContentTabPosition',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
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
                  'name' => 'Filament\\Resources\\Pages\\ContentTabPosition',
                  'isIdentifier' => false,
                ),
              ),
              1 => 
              array (
                'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                'data' => 
                array (
                  'name' => 'null',
                  'isIdentifier' => true,
                ),
              ),
            ),
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 86,
        'endLine' => 89,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Filament\\Resources\\Pages\\Concerns',
        'declaringClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'implementingClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
        'currentClassName' => 'Filament\\Resources\\Pages\\Concerns\\HasRelationManagers',
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