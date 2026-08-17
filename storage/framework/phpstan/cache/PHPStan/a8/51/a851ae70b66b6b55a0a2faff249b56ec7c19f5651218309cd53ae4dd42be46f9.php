<?php declare(strict_types = 1);

// osfsl-/home/daytona/codebase/vendor/composer/../filament/tables/src/Table.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Filament\Tables\Table
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-336c8582ffd0e50187a10bc2b108e3f359ed6b7341cd5c6c1f3522b7163af888-8.3.33-6.70.0.3',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Filament\\Tables\\Table',
        'filename' => '/home/daytona/codebase/vendor/composer/../filament/tables/src/Table.php',
      ),
    ),
    'namespace' => 'Filament\\Tables',
    'name' => 'Filament\\Tables\\Table',
    'shortName' => 'Table',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 8,
    'endLine' => 109,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Filament\\Support\\Components\\ViewComponent',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Filament\\Tables\\Table\\Concerns\\BelongsToLivewire',
      1 => 'Filament\\Tables\\Table\\Concerns\\CanBeStriped',
      2 => 'Filament\\Tables\\Table\\Concerns\\CanDeferLoading',
      3 => 'Filament\\Tables\\Table\\Concerns\\CanGroupRecords',
      4 => 'Filament\\Tables\\Table\\Concerns\\CanPaginateRecords',
      5 => 'Filament\\Tables\\Table\\Concerns\\CanPollRecords',
      6 => 'Filament\\Tables\\Table\\Concerns\\CanReorderRecords',
      7 => 'Filament\\Tables\\Table\\Concerns\\CanSearchRecords',
      8 => 'Filament\\Tables\\Table\\Concerns\\CanSortRecords',
      9 => 'Filament\\Tables\\Table\\Concerns\\CanSummarizeRecords',
      10 => 'Filament\\Tables\\Table\\Concerns\\CanToggleColumns',
      11 => 'Filament\\Tables\\Table\\Concerns\\HasActions',
      12 => 'Filament\\Tables\\Table\\Concerns\\HasBulkActions',
      13 => 'Filament\\Tables\\Table\\Concerns\\HasColumns',
      14 => 'Filament\\Tables\\Table\\Concerns\\HasContent',
      15 => 'Filament\\Tables\\Table\\Concerns\\HasEmptyState',
      16 => 'Filament\\Tables\\Table\\Concerns\\HasFilterIndicators',
      17 => 'Filament\\Tables\\Table\\Concerns\\HasFilters',
      18 => 'Filament\\Tables\\Table\\Concerns\\HasHeader',
      19 => 'Filament\\Tables\\Table\\Concerns\\HasHeaderActions',
      20 => 'Filament\\Tables\\Table\\Concerns\\HasQuery',
      21 => 'Filament\\Tables\\Table\\Concerns\\HasQueryStringIdentifier',
      22 => 'Filament\\Tables\\Table\\Concerns\\HasRecordAction',
      23 => 'Filament\\Tables\\Table\\Concerns\\HasRecordClasses',
      24 => 'Filament\\Tables\\Table\\Concerns\\HasRecords',
      25 => 'Filament\\Tables\\Table\\Concerns\\HasRecordUrl',
    ),
    'immediateConstants' => 
    array (
      'LOADING_TARGETS' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'LOADING_TARGETS',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '[\'gotoPage\', \'nextPage\', \'previousPage\', \'removeTableFilter\', \'removeTableFilters\', \'reorderTable\', \'resetTableFiltersForm\', \'sortTable\', \'tableColumnSearches\', \'tableFilters\', \'tableRecordsPerPage\', \'tableSearch\']',
          'attributes' => 
          array (
            'startLine' => 46,
            'endLine' => 59,
            'startTokenPos' => 200,
            'startFilePos' => 1414,
            'endTokenPos' => 238,
            'endFilePos' => 1730,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 46,
        'endLine' => 59,
        'startColumn' => 5,
        'endColumn' => 6,
      ),
    ),
    'immediateProperties' => 
    array (
      'view' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'view',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'filament-tables::index\'',
          'attributes' => 
          array (
            'startLine' => 40,
            'endLine' => 40,
            'startTokenPos' => 167,
            'startFilePos' => 1248,
            'endTokenPos' => 167,
            'endFilePos' => 1271,
          ),
        ),
        'docComment' => '/**
 * @var view-string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 40,
        'endLine' => 40,
        'startColumn' => 5,
        'endColumn' => 54,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'viewIdentifier' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'viewIdentifier',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'table\'',
          'attributes' => 
          array (
            'startLine' => 42,
            'endLine' => 42,
            'startTokenPos' => 178,
            'startFilePos' => 1314,
            'endTokenPos' => 178,
            'endFilePos' => 1320,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 42,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 47,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'evaluationIdentifier' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'evaluationIdentifier',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'table\'',
          'attributes' => 
          array (
            'startLine' => 44,
            'endLine' => 44,
            'startTokenPos' => 189,
            'startFilePos' => 1369,
            'endTokenPos' => 189,
            'endFilePos' => 1375,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 44,
        'endLine' => 44,
        'startColumn' => 5,
        'endColumn' => 53,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'defaultCurrency' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'defaultCurrency',
        'modifiers' => 17,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'usd\'',
          'attributes' => 
          array (
            'startLine' => 61,
            'endLine' => 61,
            'startTokenPos' => 251,
            'startFilePos' => 1778,
            'endTokenPos' => 251,
            'endFilePos' => 1782,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 61,
        'endLine' => 61,
        'startColumn' => 5,
        'endColumn' => 50,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'defaultDateDisplayFormat' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'defaultDateDisplayFormat',
        'modifiers' => 17,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'M j, Y\'',
          'attributes' => 
          array (
            'startLine' => 63,
            'endLine' => 63,
            'startTokenPos' => 264,
            'startFilePos' => 1839,
            'endTokenPos' => 264,
            'endFilePos' => 1846,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 63,
        'endLine' => 63,
        'startColumn' => 5,
        'endColumn' => 62,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'defaultDateTimeDisplayFormat' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'defaultDateTimeDisplayFormat',
        'modifiers' => 17,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'M j, Y H:i:s\'',
          'attributes' => 
          array (
            'startLine' => 65,
            'endLine' => 65,
            'startTokenPos' => 277,
            'startFilePos' => 1907,
            'endTokenPos' => 277,
            'endFilePos' => 1920,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 65,
        'endLine' => 65,
        'startColumn' => 5,
        'endColumn' => 72,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'defaultNumberLocale' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'defaultNumberLocale',
        'modifiers' => 17,
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
            'startLine' => 67,
            'endLine' => 67,
            'startTokenPos' => 291,
            'startFilePos' => 1973,
            'endTokenPos' => 291,
            'endFilePos' => 1976,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 67,
        'endLine' => 67,
        'startColumn' => 5,
        'endColumn' => 54,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'defaultTimeDisplayFormat' => 
      array (
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'name' => 'defaultTimeDisplayFormat',
        'modifiers' => 17,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'H:i:s\'',
          'attributes' => 
          array (
            'startLine' => 69,
            'endLine' => 69,
            'startTokenPos' => 304,
            'startFilePos' => 2033,
            'endTokenPos' => 304,
            'endFilePos' => 2039,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 69,
        'endLine' => 69,
        'startColumn' => 5,
        'endColumn' => 61,
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
          'livewire' => 
          array (
            'name' => 'livewire',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Filament\\Tables\\Contracts\\HasTable',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 71,
            'endLine' => 71,
            'startColumn' => 39,
            'endColumn' => 56,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
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
        'modifiers' => 33,
        'namespace' => 'Filament\\Tables',
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'currentClassName' => 'Filament\\Tables\\Table',
        'aliasName' => NULL,
      ),
      'make' => 
      array (
        'name' => 'make',
        'parameters' => 
        array (
          'livewire' => 
          array (
            'name' => 'livewire',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Filament\\Tables\\Contracts\\HasTable',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 76,
            'endLine' => 76,
            'startColumn' => 33,
            'endColumn' => 50,
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
            'name' => 'static',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 76,
        'endLine' => 82,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'Filament\\Tables',
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'currentClassName' => 'Filament\\Tables\\Table',
        'aliasName' => NULL,
      ),
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
        'startLine' => 84,
        'endLine' => 97,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Filament\\Tables',
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'currentClassName' => 'Filament\\Tables\\Table',
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
            'startLine' => 102,
            'endLine' => 102,
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
        'startLine' => 102,
        'endLine' => 108,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Filament\\Tables',
        'declaringClassName' => 'Filament\\Tables\\Table',
        'implementingClassName' => 'Filament\\Tables\\Table',
        'currentClassName' => 'Filament\\Tables\\Table',
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