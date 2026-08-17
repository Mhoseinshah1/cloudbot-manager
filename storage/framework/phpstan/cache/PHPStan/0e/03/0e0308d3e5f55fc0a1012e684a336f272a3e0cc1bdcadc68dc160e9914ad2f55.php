<?php declare(strict_types = 1);

// osfsl-/home/daytona/codebase/vendor/composer/../laravel/framework/src/Illuminate/Database/Eloquent/Relations/HasOneOrManyThrough.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-44c70f83ca7f3566bd0ec3de8133053e6ebd4d20d6096499edb30baf29b41f28-8.3.33-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'filename' => '/home/daytona/codebase/vendor/composer/../laravel/framework/src/Illuminate/Database/Eloquent/Relations/HasOneOrManyThrough.php',
      ),
    ),
    'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
    'name' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
    'shortName' => 'HasOneOrManyThrough',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 64,
    'docComment' => '/**
 * @template TRelatedModel of \\Illuminate\\Database\\Eloquent\\Model
 * @template TIntermediateModel of \\Illuminate\\Database\\Eloquent\\Model
 * @template TDeclaringModel of \\Illuminate\\Database\\Eloquent\\Model
 * @template TResult
 *
 * @extends \\Illuminate\\Database\\Eloquent\\Relations\\Relation<TRelatedModel, TIntermediateModel, TResult>
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 24,
    'endLine' => 870,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\Relation',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Illuminate\\Database\\Eloquent\\Relations\\Concerns\\InteractsWithDictionary',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'throughParent' => 
      array (
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'name' => 'throughParent',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The "through" parent model instance.
 *
 * @var TIntermediateModel
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 33,
        'endLine' => 33,
        'startColumn' => 5,
        'endColumn' => 29,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'farParent' => 
      array (
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'name' => 'farParent',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The far parent model instance.
 *
 * @var TDeclaringModel
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 40,
        'endLine' => 40,
        'startColumn' => 5,
        'endColumn' => 25,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'firstKey' => 
      array (
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'name' => 'firstKey',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The near key on the relationship.
 *
 * @var string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 47,
        'endLine' => 47,
        'startColumn' => 5,
        'endColumn' => 24,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'secondKey' => 
      array (
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'name' => 'secondKey',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The far key on the relationship.
 *
 * @var string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 54,
        'endLine' => 54,
        'startColumn' => 5,
        'endColumn' => 25,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'localKey' => 
      array (
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'name' => 'localKey',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The local key on the relationship.
 *
 * @var string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 61,
        'endLine' => 61,
        'startColumn' => 5,
        'endColumn' => 24,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'secondLocalKey' => 
      array (
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'name' => 'secondLocalKey',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The local key on the intermediary model.
 *
 * @var string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 68,
        'endLine' => 68,
        'startColumn' => 5,
        'endColumn' => 30,
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
      '__construct' => 
      array (
        'name' => '__construct',
        'parameters' => 
        array (
          'query' => 
          array (
            'name' => 'query',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Builder',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 33,
            'endColumn' => 46,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'farParent' => 
          array (
            'name' => 'farParent',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Model',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 49,
            'endColumn' => 64,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'throughParent' => 
          array (
            'name' => 'throughParent',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Model',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 67,
            'endColumn' => 86,
            'parameterIndex' => 2,
            'isOptional' => false,
          ),
          'firstKey' => 
          array (
            'name' => 'firstKey',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 89,
            'endColumn' => 97,
            'parameterIndex' => 3,
            'isOptional' => false,
          ),
          'secondKey' => 
          array (
            'name' => 'secondKey',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 100,
            'endColumn' => 109,
            'parameterIndex' => 4,
            'isOptional' => false,
          ),
          'localKey' => 
          array (
            'name' => 'localKey',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 112,
            'endColumn' => 120,
            'parameterIndex' => 5,
            'isOptional' => false,
          ),
          'secondLocalKey' => 
          array (
            'name' => 'secondLocalKey',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 123,
            'endColumn' => 137,
            'parameterIndex' => 6,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new has many through relationship instance.
 *
 * @param  \\Illuminate\\Database\\Eloquent\\Builder<TRelatedModel>  $query
 * @param  TDeclaringModel  $farParent
 * @param  TIntermediateModel  $throughParent
 * @param  string  $firstKey
 * @param  string  $secondKey
 * @param  string  $localKey
 * @param  string  $secondLocalKey
 */',
        'startLine' => 81,
        'endLine' => 91,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'addConstraints' => 
      array (
        'name' => 'addConstraints',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the base constraints on the relation query.
 *
 * @return void
 */',
        'startLine' => 98,
        'endLine' => 109,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'performJoin' => 
      array (
        'name' => 'performJoin',
        'parameters' => 
        array (
          'query' => 
          array (
            'name' => 'query',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 117,
                'endLine' => 117,
                'startTokenPos' => 318,
                'startFilePos' => 3146,
                'endTokenPos' => 318,
                'endFilePos' => 3149,
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
                      'name' => 'Illuminate\\Database\\Eloquent\\Builder',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 117,
            'endLine' => 117,
            'startColumn' => 36,
            'endColumn' => 57,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the join clause on the query.
 *
 * @param  \\Illuminate\\Database\\Eloquent\\Builder<TRelatedModel>|null  $query
 * @return void
 */',
        'startLine' => 117,
        'endLine' => 130,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getQualifiedParentKeyName' => 
      array (
        'name' => 'getQualifiedParentKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the fully-qualified parent key name.
 *
 * @return string
 */',
        'startLine' => 137,
        'endLine' => 140,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'throughParentSoftDeletes' => 
      array (
        'name' => 'throughParentSoftDeletes',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine whether "through" parent of the relation uses Soft Deletes.
 *
 * @return bool
 */',
        'startLine' => 147,
        'endLine' => 150,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'withTrashedParents' => 
      array (
        'name' => 'withTrashedParents',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Indicate that trashed "through" parents should be included in the query.
 *
 * @return $this
 */',
        'startLine' => 157,
        'endLine' => 162,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'addEagerConstraints' => 
      array (
        'name' => 'addEagerConstraints',
        'parameters' => 
        array (
          'models' => 
          array (
            'name' => 'models',
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
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 165,
            'endLine' => 165,
            'startColumn' => 41,
            'endColumn' => 53,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/** @inheritDoc */',
        'startLine' => 165,
        'endLine' => 175,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'buildDictionary' => 
      array (
        'name' => 'buildDictionary',
        'parameters' => 
        array (
          'results' => 
          array (
            'name' => 'results',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Collection',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 183,
            'endLine' => 183,
            'startColumn' => 40,
            'endColumn' => 66,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Build model dictionary keyed by the relation\'s foreign key.
 *
 * @param  \\Illuminate\\Database\\Eloquent\\Collection<int, TRelatedModel>  $results
 * @return array<array<array-key, TRelatedModel>>
 */',
        'startLine' => 183,
        'endLine' => 201,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'firstOrNew' => 
      array (
        'name' => 'firstOrNew',
        'parameters' => 
        array (
          'attributes' => 
          array (
            'name' => 'attributes',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 210,
                'endLine' => 210,
                'startTokenPos' => 704,
                'startFilePos' => 5909,
                'endTokenPos' => 705,
                'endFilePos' => 5910,
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
            'startLine' => 210,
            'endLine' => 210,
            'startColumn' => 32,
            'endColumn' => 53,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'values' => 
          array (
            'name' => 'values',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 210,
                'endLine' => 210,
                'startTokenPos' => 714,
                'startFilePos' => 5929,
                'endTokenPos' => 715,
                'endFilePos' => 5930,
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
            'startLine' => 210,
            'endLine' => 210,
            'startColumn' => 56,
            'endColumn' => 73,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the first related model record matching the attributes or instantiate it.
 *
 * @param  array  $attributes
 * @param  array  $values
 * @return TRelatedModel
 */',
        'startLine' => 210,
        'endLine' => 217,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'firstOrCreate' => 
      array (
        'name' => 'firstOrCreate',
        'parameters' => 
        array (
          'attributes' => 
          array (
            'name' => 'attributes',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 226,
                'endLine' => 226,
                'startTokenPos' => 787,
                'startFilePos' => 6421,
                'endTokenPos' => 788,
                'endFilePos' => 6422,
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
            'startLine' => 226,
            'endLine' => 226,
            'startColumn' => 35,
            'endColumn' => 56,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'values' => 
          array (
            'name' => 'values',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 226,
                'endLine' => 226,
                'startTokenPos' => 799,
                'startFilePos' => 6449,
                'endTokenPos' => 800,
                'endFilePos' => 6450,
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
                      'name' => 'Closure',
                      'isIdentifier' => false,
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
            'startLine' => 226,
            'endLine' => 226,
            'startColumn' => 59,
            'endColumn' => 84,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the first record matching the attributes. If the record is not found, create it.
 *
 * @param  array  $attributes
 * @param  (\\Closure(): array)|array  $values
 * @return TRelatedModel
 */',
        'startLine' => 226,
        'endLine' => 233,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'createOrFirst' => 
      array (
        'name' => 'createOrFirst',
        'parameters' => 
        array (
          'attributes' => 
          array (
            'name' => 'attributes',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 242,
                'endLine' => 242,
                'startTokenPos' => 877,
                'startFilePos' => 6972,
                'endTokenPos' => 878,
                'endFilePos' => 6973,
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
            'startLine' => 242,
            'endLine' => 242,
            'startColumn' => 35,
            'endColumn' => 56,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'values' => 
          array (
            'name' => 'values',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 242,
                'endLine' => 242,
                'startTokenPos' => 889,
                'startFilePos' => 7000,
                'endTokenPos' => 890,
                'endFilePos' => 7001,
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
                      'name' => 'Closure',
                      'isIdentifier' => false,
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
            'startLine' => 242,
            'endLine' => 242,
            'startColumn' => 59,
            'endColumn' => 84,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Attempt to create the record. If a unique constraint violation occurs, attempt to find the matching record.
 *
 * @param  array  $attributes
 * @param  (\\Closure(): array)|array  $values
 * @return TRelatedModel
 */',
        'startLine' => 242,
        'endLine' => 249,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'updateOrCreate' => 
      array (
        'name' => 'updateOrCreate',
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
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 258,
            'endLine' => 258,
            'startColumn' => 36,
            'endColumn' => 52,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'values' => 
          array (
            'name' => 'values',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 258,
                'endLine' => 258,
                'startTokenPos' => 989,
                'startFilePos' => 7586,
                'endTokenPos' => 990,
                'endFilePos' => 7587,
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
            'startLine' => 258,
            'endLine' => 258,
            'startColumn' => 55,
            'endColumn' => 72,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create or update a related record matching the attributes, and fill it with values.
 *
 * @param  array  $attributes
 * @param  array  $values
 * @return TRelatedModel
 */',
        'startLine' => 258,
        'endLine' => 265,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'firstWhere' => 
      array (
        'name' => 'firstWhere',
        'parameters' => 
        array (
          'column' => 
          array (
            'name' => 'column',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 276,
            'endLine' => 276,
            'startColumn' => 32,
            'endColumn' => 38,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'operator' => 
          array (
            'name' => 'operator',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 276,
                'endLine' => 276,
                'startTokenPos' => 1071,
                'startFilePos' => 8153,
                'endTokenPos' => 1071,
                'endFilePos' => 8156,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 276,
            'endLine' => 276,
            'startColumn' => 41,
            'endColumn' => 56,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 276,
                'endLine' => 276,
                'startTokenPos' => 1078,
                'startFilePos' => 8168,
                'endTokenPos' => 1078,
                'endFilePos' => 8171,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 276,
            'endLine' => 276,
            'startColumn' => 59,
            'endColumn' => 71,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'boolean' => 
          array (
            'name' => 'boolean',
            'default' => 
            array (
              'code' => '\'and\'',
              'attributes' => 
              array (
                'startLine' => 276,
                'endLine' => 276,
                'startTokenPos' => 1085,
                'startFilePos' => 8185,
                'endTokenPos' => 1085,
                'endFilePos' => 8189,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 276,
            'endLine' => 276,
            'startColumn' => 74,
            'endColumn' => 89,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Add a basic where clause to the query, and return the first result.
 *
 * @param  \\Closure|string|array  $column
 * @param  mixed  $operator
 * @param  mixed  $value
 * @param  string  $boolean
 * @return TRelatedModel|null
 */',
        'startLine' => 276,
        'endLine' => 279,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'first' => 
      array (
        'name' => 'first',
        'parameters' => 
        array (
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 287,
                'endLine' => 287,
                'startTokenPos' => 1127,
                'startFilePos' => 8464,
                'endTokenPos' => 1129,
                'endFilePos' => 8468,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 287,
            'endLine' => 287,
            'startColumn' => 27,
            'endColumn' => 42,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Execute the query and get the first related model.
 *
 * @param  array  $columns
 * @return TRelatedModel|null
 */',
        'startLine' => 287,
        'endLine' => 292,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'firstOrFail' => 
      array (
        'name' => 'firstOrFail',
        'parameters' => 
        array (
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 302,
                'endLine' => 302,
                'startTokenPos' => 1189,
                'startFilePos' => 8888,
                'endTokenPos' => 1191,
                'endFilePos' => 8892,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 302,
            'endLine' => 302,
            'startColumn' => 33,
            'endColumn' => 48,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Execute the query and get the first result or throw an exception.
 *
 * @param  array  $columns
 * @return TRelatedModel
 *
 * @throws \\Illuminate\\Database\\Eloquent\\ModelNotFoundException<TRelatedModel>
 */',
        'startLine' => 302,
        'endLine' => 309,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'firstOr' => 
      array (
        'name' => 'firstOr',
        'parameters' => 
        array (
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 320,
                'endLine' => 320,
                'startTokenPos' => 1258,
                'startFilePos' => 9395,
                'endTokenPos' => 1260,
                'endFilePos' => 9399,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 320,
            'endLine' => 320,
            'startColumn' => 29,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 320,
                'endLine' => 320,
                'startTokenPos' => 1270,
                'startFilePos' => 9423,
                'endTokenPos' => 1270,
                'endFilePos' => 9426,
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
                      'name' => 'Closure',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 320,
            'endLine' => 320,
            'startColumn' => 47,
            'endColumn' => 71,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Execute the query and get the first result or call a callback.
 *
 * @template TValue
 *
 * @param  (\\Closure(): TValue)|list<string>  $columns
 * @param  (\\Closure(): TValue)|null  $callback
 * @return TRelatedModel|TValue
 */',
        'startLine' => 320,
        'endLine' => 333,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'find' => 
      array (
        'name' => 'find',
        'parameters' => 
        array (
          'id' => 
          array (
            'name' => 'id',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 342,
            'endLine' => 342,
            'startColumn' => 26,
            'endColumn' => 28,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 342,
                'endLine' => 342,
                'startTokenPos' => 1358,
                'startFilePos' => 10035,
                'endTokenPos' => 1360,
                'endFilePos' => 10039,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 342,
            'endLine' => 342,
            'startColumn' => 31,
            'endColumn' => 46,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Find a related model by its primary key.
 *
 * @param  mixed  $id
 * @param  array  $columns
 * @return ($id is (\\Illuminate\\Contracts\\Support\\Arrayable<array-key, mixed>|array<mixed>) ? \\Illuminate\\Database\\Eloquent\\Collection<int, TRelatedModel> : TRelatedModel|null)
 */',
        'startLine' => 342,
        'endLine' => 351,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'findSole' => 
      array (
        'name' => 'findSole',
        'parameters' => 
        array (
          'id' => 
          array (
            'name' => 'id',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 363,
            'endLine' => 363,
            'startColumn' => 30,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 363,
                'endLine' => 363,
                'startTokenPos' => 1447,
                'startFilePos' => 10659,
                'endTokenPos' => 1449,
                'endFilePos' => 10663,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 363,
            'endLine' => 363,
            'startColumn' => 35,
            'endColumn' => 50,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Find a sole related model by its primary key.
 *
 * @param  mixed  $id
 * @param  array  $columns
 * @return TRelatedModel
 *
 * @throws \\Illuminate\\Database\\Eloquent\\ModelNotFoundException<TRelatedModel>
 * @throws \\Illuminate\\Database\\MultipleRecordsFoundException
 */',
        'startLine' => 363,
        'endLine' => 368,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'findMany' => 
      array (
        'name' => 'findMany',
        'parameters' => 
        array (
          'ids' => 
          array (
            'name' => 'ids',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 377,
            'endLine' => 377,
            'startColumn' => 30,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 377,
                'endLine' => 377,
                'startTokenPos' => 1502,
                'startFilePos' => 11102,
                'endTokenPos' => 1504,
                'endFilePos' => 11106,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 377,
            'endLine' => 377,
            'startColumn' => 36,
            'endColumn' => 51,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Find multiple related models by their primary keys.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable|array  $ids
 * @param  array  $columns
 * @return \\Illuminate\\Database\\Eloquent\\Collection<int, TRelatedModel>
 */',
        'startLine' => 377,
        'endLine' => 388,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'findOrFail' => 
      array (
        'name' => 'findOrFail',
        'parameters' => 
        array (
          'id' => 
          array (
            'name' => 'id',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 399,
            'endLine' => 399,
            'startColumn' => 32,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 399,
                'endLine' => 399,
                'startTokenPos' => 1603,
                'startFilePos' => 11866,
                'endTokenPos' => 1605,
                'endFilePos' => 11870,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 399,
            'endLine' => 399,
            'startColumn' => 37,
            'endColumn' => 52,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Find a related model by its primary key or throw an exception.
 *
 * @param  mixed  $id
 * @param  array  $columns
 * @return ($id is (\\Illuminate\\Contracts\\Support\\Arrayable<array-key, mixed>|array<mixed>) ? \\Illuminate\\Database\\Eloquent\\Collection<int, TRelatedModel> : TRelatedModel)
 *
 * @throws \\Illuminate\\Database\\Eloquent\\ModelNotFoundException<TRelatedModel>
 */',
        'startLine' => 399,
        'endLine' => 414,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'findOr' => 
      array (
        'name' => 'findOr',
        'parameters' => 
        array (
          'id' => 
          array (
            'name' => 'id',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 430,
            'endLine' => 430,
            'startColumn' => 28,
            'endColumn' => 30,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 430,
                'endLine' => 430,
                'startTokenPos' => 1748,
                'startFilePos' => 12840,
                'endTokenPos' => 1750,
                'endFilePos' => 12844,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 430,
            'endLine' => 430,
            'startColumn' => 33,
            'endColumn' => 48,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 430,
                'endLine' => 430,
                'startTokenPos' => 1760,
                'startFilePos' => 12868,
                'endTokenPos' => 1760,
                'endFilePos' => 12871,
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
                      'name' => 'Closure',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 430,
            'endLine' => 430,
            'startColumn' => 51,
            'endColumn' => 75,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Find a related model by its primary key or call a callback.
 *
 * @template TValue
 *
 * @param  mixed  $id
 * @param  (\\Closure(): TValue)|list<string>|string  $columns
 * @param  (\\Closure(): TValue)|null  $callback
 * @return (
 *     $id is (\\Illuminate\\Contracts\\Support\\Arrayable<array-key, mixed>|array<mixed>)
 *     ? \\Illuminate\\Database\\Eloquent\\Collection<int, TRelatedModel>|TValue
 *     : TRelatedModel|TValue
 * )
 */',
        'startLine' => 430,
        'endLine' => 451,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'get' => 
      array (
        'name' => 'get',
        'parameters' => 
        array (
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 454,
                'endLine' => 454,
                'startTokenPos' => 1915,
                'startFilePos' => 13420,
                'endTokenPos' => 1917,
                'endFilePos' => 13424,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 454,
            'endLine' => 454,
            'startColumn' => 25,
            'endColumn' => 40,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/** @inheritDoc */',
        'startLine' => 454,
        'endLine' => 470,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'paginate' => 
      array (
        'name' => 'paginate',
        'parameters' => 
        array (
          'perPage' => 
          array (
            'name' => 'perPage',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 481,
                'endLine' => 481,
                'startTokenPos' => 2015,
                'startFilePos' => 14313,
                'endTokenPos' => 2015,
                'endFilePos' => 14316,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 481,
            'endLine' => 481,
            'startColumn' => 30,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 481,
                'endLine' => 481,
                'startTokenPos' => 2022,
                'startFilePos' => 14330,
                'endTokenPos' => 2024,
                'endFilePos' => 14334,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 481,
            'endLine' => 481,
            'startColumn' => 47,
            'endColumn' => 62,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'pageName' => 
          array (
            'name' => 'pageName',
            'default' => 
            array (
              'code' => '\'page\'',
              'attributes' => 
              array (
                'startLine' => 481,
                'endLine' => 481,
                'startTokenPos' => 2031,
                'startFilePos' => 14349,
                'endTokenPos' => 2031,
                'endFilePos' => 14354,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 481,
            'endLine' => 481,
            'startColumn' => 65,
            'endColumn' => 82,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'page' => 
          array (
            'name' => 'page',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 481,
                'endLine' => 481,
                'startTokenPos' => 2038,
                'startFilePos' => 14365,
                'endTokenPos' => 2038,
                'endFilePos' => 14368,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 481,
            'endLine' => 481,
            'startColumn' => 85,
            'endColumn' => 96,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get a paginator for the "select" statement.
 *
 * @param  int|null  $perPage
 * @param  array  $columns
 * @param  string  $pageName
 * @param  int|null  $page
 * @return \\Illuminate\\Pagination\\LengthAwarePaginator
 */',
        'startLine' => 481,
        'endLine' => 486,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'simplePaginate' => 
      array (
        'name' => 'simplePaginate',
        'parameters' => 
        array (
          'perPage' => 
          array (
            'name' => 'perPage',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 497,
                'endLine' => 497,
                'startTokenPos' => 2093,
                'startFilePos' => 14839,
                'endTokenPos' => 2093,
                'endFilePos' => 14842,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 497,
            'endLine' => 497,
            'startColumn' => 36,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 497,
                'endLine' => 497,
                'startTokenPos' => 2100,
                'startFilePos' => 14856,
                'endTokenPos' => 2102,
                'endFilePos' => 14860,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 497,
            'endLine' => 497,
            'startColumn' => 53,
            'endColumn' => 68,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'pageName' => 
          array (
            'name' => 'pageName',
            'default' => 
            array (
              'code' => '\'page\'',
              'attributes' => 
              array (
                'startLine' => 497,
                'endLine' => 497,
                'startTokenPos' => 2109,
                'startFilePos' => 14875,
                'endTokenPos' => 2109,
                'endFilePos' => 14880,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 497,
            'endLine' => 497,
            'startColumn' => 71,
            'endColumn' => 88,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'page' => 
          array (
            'name' => 'page',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 497,
                'endLine' => 497,
                'startTokenPos' => 2116,
                'startFilePos' => 14891,
                'endTokenPos' => 2116,
                'endFilePos' => 14894,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 497,
            'endLine' => 497,
            'startColumn' => 91,
            'endColumn' => 102,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Paginate the given query into a simple paginator.
 *
 * @param  int|null  $perPage
 * @param  array  $columns
 * @param  string  $pageName
 * @param  int|null  $page
 * @return \\Illuminate\\Contracts\\Pagination\\Paginator
 */',
        'startLine' => 497,
        'endLine' => 502,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'cursorPaginate' => 
      array (
        'name' => 'cursorPaginate',
        'parameters' => 
        array (
          'perPage' => 
          array (
            'name' => 'perPage',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 513,
                'endLine' => 513,
                'startTokenPos' => 2171,
                'startFilePos' => 15384,
                'endTokenPos' => 2171,
                'endFilePos' => 15387,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 513,
            'endLine' => 513,
            'startColumn' => 36,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 513,
                'endLine' => 513,
                'startTokenPos' => 2178,
                'startFilePos' => 15401,
                'endTokenPos' => 2180,
                'endFilePos' => 15405,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 513,
            'endLine' => 513,
            'startColumn' => 53,
            'endColumn' => 68,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'cursorName' => 
          array (
            'name' => 'cursorName',
            'default' => 
            array (
              'code' => '\'cursor\'',
              'attributes' => 
              array (
                'startLine' => 513,
                'endLine' => 513,
                'startTokenPos' => 2187,
                'startFilePos' => 15422,
                'endTokenPos' => 2187,
                'endFilePos' => 15429,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 513,
            'endLine' => 513,
            'startColumn' => 71,
            'endColumn' => 92,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'cursor' => 
          array (
            'name' => 'cursor',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 513,
                'endLine' => 513,
                'startTokenPos' => 2194,
                'startFilePos' => 15442,
                'endTokenPos' => 2194,
                'endFilePos' => 15445,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 513,
            'endLine' => 513,
            'startColumn' => 95,
            'endColumn' => 108,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Paginate the given query into a cursor paginator.
 *
 * @param  int|null  $perPage
 * @param  array  $columns
 * @param  string  $cursorName
 * @param  string|null  $cursor
 * @return \\Illuminate\\Contracts\\Pagination\\CursorPaginator
 */',
        'startLine' => 513,
        'endLine' => 518,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'shouldSelect' => 
      array (
        'name' => 'shouldSelect',
        'parameters' => 
        array (
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 526,
                'endLine' => 526,
                'startTokenPos' => 2251,
                'startFilePos' => 15794,
                'endTokenPos' => 2253,
                'endFilePos' => 15798,
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
            'startLine' => 526,
            'endLine' => 526,
            'startColumn' => 37,
            'endColumn' => 58,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the select clause for the relation query.
 *
 * @param  array  $columns
 * @return array
 */',
        'startLine' => 526,
        'endLine' => 533,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'chunk' => 
      array (
        'name' => 'chunk',
        'parameters' => 
        array (
          'count' => 
          array (
            'name' => 'count',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 542,
            'endLine' => 542,
            'startColumn' => 27,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 542,
            'endLine' => 542,
            'startColumn' => 35,
            'endColumn' => 52,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Chunk the results of the query.
 *
 * @param  int  $count
 * @param  callable  $callback
 * @return bool
 */',
        'startLine' => 542,
        'endLine' => 545,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'chunkById' => 
      array (
        'name' => 'chunkById',
        'parameters' => 
        array (
          'count' => 
          array (
            'name' => 'count',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 556,
            'endLine' => 556,
            'startColumn' => 31,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 556,
            'endLine' => 556,
            'startColumn' => 39,
            'endColumn' => 56,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'column' => 
          array (
            'name' => 'column',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 556,
                'endLine' => 556,
                'startTokenPos' => 2368,
                'startFilePos' => 16608,
                'endTokenPos' => 2368,
                'endFilePos' => 16611,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 556,
            'endLine' => 556,
            'startColumn' => 59,
            'endColumn' => 72,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'alias' => 
          array (
            'name' => 'alias',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 556,
                'endLine' => 556,
                'startTokenPos' => 2375,
                'startFilePos' => 16623,
                'endTokenPos' => 2375,
                'endFilePos' => 16626,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 556,
            'endLine' => 556,
            'startColumn' => 75,
            'endColumn' => 87,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Chunk the results of a query by comparing numeric IDs.
 *
 * @param  int  $count
 * @param  callable  $callback
 * @param  string|null  $column
 * @param  string|null  $alias
 * @return bool
 */',
        'startLine' => 556,
        'endLine' => 563,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'chunkByIdDesc' => 
      array (
        'name' => 'chunkByIdDesc',
        'parameters' => 
        array (
          'count' => 
          array (
            'name' => 'count',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 574,
            'endLine' => 574,
            'startColumn' => 35,
            'endColumn' => 40,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 574,
            'endLine' => 574,
            'startColumn' => 43,
            'endColumn' => 60,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'column' => 
          array (
            'name' => 'column',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 574,
                'endLine' => 574,
                'startTokenPos' => 2455,
                'startFilePos' => 17176,
                'endTokenPos' => 2455,
                'endFilePos' => 17179,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 574,
            'endLine' => 574,
            'startColumn' => 63,
            'endColumn' => 76,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'alias' => 
          array (
            'name' => 'alias',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 574,
                'endLine' => 574,
                'startTokenPos' => 2462,
                'startFilePos' => 17191,
                'endTokenPos' => 2462,
                'endFilePos' => 17194,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 574,
            'endLine' => 574,
            'startColumn' => 79,
            'endColumn' => 91,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Chunk the results of a query by comparing IDs in descending order.
 *
 * @param  int  $count
 * @param  callable  $callback
 * @param  string|null  $column
 * @param  string|null  $alias
 * @return bool
 */',
        'startLine' => 574,
        'endLine' => 581,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'eachById' => 
      array (
        'name' => 'eachById',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 592,
            'endLine' => 592,
            'startColumn' => 30,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'count' => 
          array (
            'name' => 'count',
            'default' => 
            array (
              'code' => '1000',
              'attributes' => 
              array (
                'startLine' => 592,
                'endLine' => 592,
                'startTokenPos' => 2539,
                'startFilePos' => 17723,
                'endTokenPos' => 2539,
                'endFilePos' => 17726,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 592,
            'endLine' => 592,
            'startColumn' => 50,
            'endColumn' => 62,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'column' => 
          array (
            'name' => 'column',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 592,
                'endLine' => 592,
                'startTokenPos' => 2546,
                'startFilePos' => 17739,
                'endTokenPos' => 2546,
                'endFilePos' => 17742,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 592,
            'endLine' => 592,
            'startColumn' => 65,
            'endColumn' => 78,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'alias' => 
          array (
            'name' => 'alias',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 592,
                'endLine' => 592,
                'startTokenPos' => 2553,
                'startFilePos' => 17754,
                'endTokenPos' => 2553,
                'endFilePos' => 17757,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 592,
            'endLine' => 592,
            'startColumn' => 81,
            'endColumn' => 93,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Execute a callback over each item while chunking by ID.
 *
 * @param  callable  $callback
 * @param  int  $count
 * @param  string|null  $column
 * @param  string|null  $alias
 * @return bool
 */',
        'startLine' => 592,
        'endLine' => 599,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'cursor' => 
      array (
        'name' => 'cursor',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get a generator for the given query.
 *
 * @return \\Illuminate\\Support\\LazyCollection<int, TRelatedModel>
 */',
        'startLine' => 606,
        'endLine' => 609,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'each' => 
      array (
        'name' => 'each',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 618,
            'endLine' => 618,
            'startColumn' => 26,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'count' => 
          array (
            'name' => 'count',
            'default' => 
            array (
              'code' => '1000',
              'attributes' => 
              array (
                'startLine' => 618,
                'endLine' => 618,
                'startTokenPos' => 2665,
                'startFilePos' => 18451,
                'endTokenPos' => 2665,
                'endFilePos' => 18454,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 618,
            'endLine' => 618,
            'startColumn' => 46,
            'endColumn' => 58,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Execute a callback over each item while chunking.
 *
 * @param  callable  $callback
 * @param  int  $count
 * @return bool
 */',
        'startLine' => 618,
        'endLine' => 627,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'lazy' => 
      array (
        'name' => 'lazy',
        'parameters' => 
        array (
          'chunkSize' => 
          array (
            'name' => 'chunkSize',
            'default' => 
            array (
              'code' => '1000',
              'attributes' => 
              array (
                'startLine' => 635,
                'endLine' => 635,
                'startTokenPos' => 2754,
                'startFilePos' => 18942,
                'endTokenPos' => 2754,
                'endFilePos' => 18945,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 635,
            'endLine' => 635,
            'startColumn' => 26,
            'endColumn' => 42,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Query lazily, by chunks of the given size.
 *
 * @param  int  $chunkSize
 * @return \\Illuminate\\Support\\LazyCollection<int, TRelatedModel>
 */',
        'startLine' => 635,
        'endLine' => 638,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'lazyById' => 
      array (
        'name' => 'lazyById',
        'parameters' => 
        array (
          'chunkSize' => 
          array (
            'name' => 'chunkSize',
            'default' => 
            array (
              'code' => '1000',
              'attributes' => 
              array (
                'startLine' => 648,
                'endLine' => 648,
                'startTokenPos' => 2787,
                'startFilePos' => 19335,
                'endTokenPos' => 2787,
                'endFilePos' => 19338,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 648,
            'endLine' => 648,
            'startColumn' => 30,
            'endColumn' => 46,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'column' => 
          array (
            'name' => 'column',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 648,
                'endLine' => 648,
                'startTokenPos' => 2794,
                'startFilePos' => 19351,
                'endTokenPos' => 2794,
                'endFilePos' => 19354,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 648,
            'endLine' => 648,
            'startColumn' => 49,
            'endColumn' => 62,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'alias' => 
          array (
            'name' => 'alias',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 648,
                'endLine' => 648,
                'startTokenPos' => 2801,
                'startFilePos' => 19366,
                'endTokenPos' => 2801,
                'endFilePos' => 19369,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 648,
            'endLine' => 648,
            'startColumn' => 65,
            'endColumn' => 77,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Query lazily, by chunking the results of a query by comparing IDs.
 *
 * @param  int  $chunkSize
 * @param  string|null  $column
 * @param  string|null  $alias
 * @return \\Illuminate\\Support\\LazyCollection<int, TRelatedModel>
 */',
        'startLine' => 648,
        'endLine' => 655,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'lazyByIdDesc' => 
      array (
        'name' => 'lazyByIdDesc',
        'parameters' => 
        array (
          'chunkSize' => 
          array (
            'name' => 'chunkSize',
            'default' => 
            array (
              'code' => '1000',
              'attributes' => 
              array (
                'startLine' => 665,
                'endLine' => 665,
                'startTokenPos' => 2870,
                'startFilePos' => 19924,
                'endTokenPos' => 2870,
                'endFilePos' => 19927,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 665,
            'endLine' => 665,
            'startColumn' => 34,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'column' => 
          array (
            'name' => 'column',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 665,
                'endLine' => 665,
                'startTokenPos' => 2877,
                'startFilePos' => 19940,
                'endTokenPos' => 2877,
                'endFilePos' => 19943,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 665,
            'endLine' => 665,
            'startColumn' => 53,
            'endColumn' => 66,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'alias' => 
          array (
            'name' => 'alias',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 665,
                'endLine' => 665,
                'startTokenPos' => 2884,
                'startFilePos' => 19955,
                'endTokenPos' => 2884,
                'endFilePos' => 19958,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 665,
            'endLine' => 665,
            'startColumn' => 69,
            'endColumn' => 81,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Query lazily, by chunking the results of a query by comparing IDs in descending order.
 *
 * @param  int  $chunkSize
 * @param  string|null  $column
 * @param  string|null  $alias
 * @return \\Illuminate\\Support\\LazyCollection<int, TRelatedModel>
 */',
        'startLine' => 665,
        'endLine' => 672,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'prepareQueryBuilder' => 
      array (
        'name' => 'prepareQueryBuilder',
        'parameters' => 
        array (
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 680,
                'endLine' => 680,
                'startTokenPos' => 2953,
                'startFilePos' => 20412,
                'endTokenPos' => 2955,
                'endFilePos' => 20416,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 680,
            'endLine' => 680,
            'startColumn' => 44,
            'endColumn' => 59,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Prepare the query builder for query execution.
 *
 * @param  array  $columns
 * @return \\Illuminate\\Database\\Eloquent\\Builder<TRelatedModel>
 */',
        'startLine' => 680,
        'endLine' => 687,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getRelationExistenceQuery' => 
      array (
        'name' => 'getRelationExistenceQuery',
        'parameters' => 
        array (
          'query' => 
          array (
            'name' => 'query',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Builder',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 690,
            'endLine' => 690,
            'startColumn' => 47,
            'endColumn' => 60,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'parentQuery' => 
          array (
            'name' => 'parentQuery',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Builder',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 690,
            'endLine' => 690,
            'startColumn' => 63,
            'endColumn' => 82,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 690,
                'endLine' => 690,
                'startTokenPos' => 3029,
                'startFilePos' => 20725,
                'endTokenPos' => 3031,
                'endFilePos' => 20729,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 690,
            'endLine' => 690,
            'startColumn' => 85,
            'endColumn' => 100,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/** @inheritDoc */',
        'startLine' => 690,
        'endLine' => 705,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getRelationExistenceQueryForSelfRelation' => 
      array (
        'name' => 'getRelationExistenceQueryForSelfRelation',
        'parameters' => 
        array (
          'query' => 
          array (
            'name' => 'query',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Builder',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 715,
            'endLine' => 715,
            'startColumn' => 62,
            'endColumn' => 75,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'parentQuery' => 
          array (
            'name' => 'parentQuery',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Builder',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 715,
            'endLine' => 715,
            'startColumn' => 78,
            'endColumn' => 97,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 715,
                'endLine' => 715,
                'startTokenPos' => 3183,
                'startFilePos' => 21783,
                'endTokenPos' => 3185,
                'endFilePos' => 21787,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 715,
            'endLine' => 715,
            'startColumn' => 100,
            'endColumn' => 115,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Add the constraints for a relationship query on the same table.
 *
 * @param  \\Illuminate\\Database\\Eloquent\\Builder<TRelatedModel>  $query
 * @param  \\Illuminate\\Database\\Eloquent\\Builder<TDeclaringModel>  $parentQuery
 * @param  mixed  $columns
 * @return \\Illuminate\\Database\\Eloquent\\Builder<TRelatedModel>
 */',
        'startLine' => 715,
        'endLine' => 730,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getRelationExistenceQueryForThroughSelfRelation' => 
      array (
        'name' => 'getRelationExistenceQueryForThroughSelfRelation',
        'parameters' => 
        array (
          'query' => 
          array (
            'name' => 'query',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Builder',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 740,
            'endLine' => 740,
            'startColumn' => 69,
            'endColumn' => 82,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'parentQuery' => 
          array (
            'name' => 'parentQuery',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Database\\Eloquent\\Builder',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 740,
            'endLine' => 740,
            'startColumn' => 85,
            'endColumn' => 104,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'columns' => 
          array (
            'name' => 'columns',
            'default' => 
            array (
              'code' => '[\'*\']',
              'attributes' => 
              array (
                'startLine' => 740,
                'endLine' => 740,
                'startTokenPos' => 3354,
                'startFilePos' => 22879,
                'endTokenPos' => 3356,
                'endFilePos' => 22883,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 740,
            'endLine' => 740,
            'startColumn' => 107,
            'endColumn' => 122,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Add the constraints for a relationship query on the same table as the through parent.
 *
 * @param  \\Illuminate\\Database\\Eloquent\\Builder<TRelatedModel>  $query
 * @param  \\Illuminate\\Database\\Eloquent\\Builder<TDeclaringModel>  $parentQuery
 * @param  mixed  $columns
 * @return \\Illuminate\\Database\\Eloquent\\Builder<TRelatedModel>
 */',
        'startLine' => 740,
        'endLine' => 753,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'take' => 
      array (
        'name' => 'take',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 761,
            'endLine' => 761,
            'startColumn' => 26,
            'endColumn' => 31,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Alias to set the "limit" value of the query.
 *
 * @param  int  $value
 * @return $this
 */',
        'startLine' => 761,
        'endLine' => 764,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'limit' => 
      array (
        'name' => 'limit',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 772,
            'endLine' => 772,
            'startColumn' => 27,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the "limit" value of the query.
 *
 * @param  int  $value
 * @return $this
 */',
        'startLine' => 772,
        'endLine' => 789,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getQualifiedFarKeyName' => 
      array (
        'name' => 'getQualifiedFarKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the qualified foreign key on the related model.
 *
 * @return string
 */',
        'startLine' => 796,
        'endLine' => 799,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getFirstKeyName' => 
      array (
        'name' => 'getFirstKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the foreign key on the "through" model.
 *
 * @return string
 */',
        'startLine' => 806,
        'endLine' => 809,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getQualifiedFirstKeyName' => 
      array (
        'name' => 'getQualifiedFirstKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the qualified foreign key on the "through" model.
 *
 * @return string
 */',
        'startLine' => 816,
        'endLine' => 819,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getForeignKeyName' => 
      array (
        'name' => 'getForeignKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the foreign key on the related model.
 *
 * @return string
 */',
        'startLine' => 826,
        'endLine' => 829,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getQualifiedForeignKeyName' => 
      array (
        'name' => 'getQualifiedForeignKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the qualified foreign key on the related model.
 *
 * @return string
 */',
        'startLine' => 836,
        'endLine' => 839,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getLocalKeyName' => 
      array (
        'name' => 'getLocalKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the local key on the far parent model.
 *
 * @return string
 */',
        'startLine' => 846,
        'endLine' => 849,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getQualifiedLocalKeyName' => 
      array (
        'name' => 'getQualifiedLocalKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the qualified local key on the far parent model.
 *
 * @return string
 */',
        'startLine' => 856,
        'endLine' => 859,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'aliasName' => NULL,
      ),
      'getSecondLocalKeyName' => 
      array (
        'name' => 'getSecondLocalKeyName',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the local key on the intermediary model.
 *
 * @return string
 */',
        'startLine' => 866,
        'endLine' => 869,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Database\\Eloquent\\Relations',
        'declaringClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'implementingClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
        'currentClassName' => 'Illuminate\\Database\\Eloquent\\Relations\\HasOneOrManyThrough',
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