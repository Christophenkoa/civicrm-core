<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Query;

/**
 * Base class for all Sql functions.
 *
 * @package Civi\Api4\Query
 */
abstract class SqlFunction extends SqlExpression {

  /**
   * @var array[]
   */
  protected $args = [];

  /**
   * Used for categorizing functions in the UI
   *
   * @var string
   */
  protected static $category;

  /**
   * Data type output by this function
   *
   * @var string
   */
  protected static $dataType;

  const CATEGORY_AGGREGATE = 'aggregate',
    CATEGORY_COMPARISON = 'comparison',
    CATEGORY_DATE = 'date',
    CATEGORY_MATH = 'math',
    CATEGORY_STRING = 'string';

  /**
   * Parse the argument string into an array of function arguments
   */
  protected function initialize() {
    $arg = trim(substr($this->expr, strpos($this->expr, '(') + 1, -1));
    foreach ($this->getParams() as $idx => $param) {
      $prefix = NULL;
      if ($param['prefix']) {
        $prefix = $this->captureKeyword([$param['prefix']], $arg);
        // Supply api_default
        if (!$prefix && isset($param['api_default'])) {
          $this->args[$idx] = [
            'prefix' => $param['api_default']['prefix'] ?? [$param['prefix']],
            'expr' => array_map([parent::class, 'convert'], $param['api_default']['expr']),
            'suffix' => $param['api_default']['suffix'] ?? [],
          ];
          continue;
        }
        if (!$prefix && !$param['optional']) {
          throw new \API_Exception("Missing {$param['prefix']} for SQL function " . static::getName());
        }
      }
      elseif ($param['flag_before']) {
        $prefix = $this->captureKeyword(array_keys($param['flag_before']), $arg);
      }
      $this->args[$idx] = [
        'prefix' => (array) $prefix,
        'expr' => [],
        'suffix' => [],
      ];
      if ($param['max_expr'] && (!$param['prefix'] || $param['prefix'] === $prefix)) {
        $exprs = $this->captureExpressions($arg, $param['must_be'], $param['cant_be']);
        if (count($exprs) < $param['min_expr'] || count($exprs) > $param['max_expr']) {
          throw new \API_Exception('Incorrect number of arguments for SQL function ' . static::getName());
        }
        $this->args[$idx]['expr'] = $exprs;

        $this->args[$idx]['suffix'] = (array) $this->captureKeyword(array_keys($param['flag_after']), $arg);
      }
    }
  }

  /**
   * Change $dataType according to output of function
   *
   * @see \Civi\Api4\Utils\FormattingUtil::formatOutputValues
   * @param string $value
   * @param string $dataType
   * @return string
   */
  public function formatOutputValue($value, &$dataType) {
    if (static::$dataType) {
      $dataType = static::$dataType;
    }
    return $value;
  }

  /**
   * Shift a keyword off the beginning of the argument string and return it.
   *
   * @param array $keywords
   *   Whitelist of keywords
   * @param string $arg
   * @return mixed|null
   */
  private function captureKeyword($keywords, &$arg) {
    foreach ($keywords as $key) {
      if (strpos($arg, $key . ' ') === 0) {
        $arg = ltrim(substr($arg, strlen($key)));
        return $key;
      }
    }
    return NULL;
  }

  /**
   * Shifts 0 or more expressions off the argument string and returns them
   *
   * @param string $arg
   * @param array $mustBe
   * @param array $cantBe
   * @return array
   * @throws \API_Exception
   */
  private function captureExpressions(&$arg, $mustBe, $cantBe) {
    $captured = [];
    $arg = ltrim($arg);
    while ($arg) {
      $item = $this->captureExpression($arg);
      $arg = ltrim(substr($arg, strlen($item)));
      $expr = SqlExpression::convert($item, FALSE, $mustBe, $cantBe);
      $this->fields = array_merge($this->fields, $expr->getFields());
      $captured[] = $expr;
      // Keep going if we have a comma indicating another expression follows
      if (substr($arg, 0, 1) === ',') {
        $arg = ltrim(substr($arg, 1));
      }
      else {
        break;
      }
    }
    return $captured;
  }

  /**
   * Scans the beginning of a string for an expression; stops when it hits delimiter
   *
   * @param $arg
   * @return string
   */
  private function captureExpression($arg) {
    $chars = str_split($arg);
    $isEscaped = $quote = NULL;
    $item = '';
    $quotes = ['"', "'"];
    $brackets = [
      ')' => '(',
    ];
    $enclosures = array_fill_keys($brackets, 0);
    foreach ($chars as $index => $char) {
      if (!$isEscaped && in_array($char, $quotes, TRUE)) {
        // Open quotes - we'll ignore everything inside
        if (!$quote) {
          $quote = $char;
        }
        // Close quotes
        elseif ($char === $quote) {
          $quote = NULL;
        }
      }
      if (!$quote) {
        // Delineates end of expression
        if (($char == ',' || $char == ' ') && !array_filter($enclosures)) {
          return $item;
        }
        // Open brackets - we'll ignore delineators inside
        if (isset($enclosures[$char])) {
          $enclosures[$char]++;
        }
        // Close brackets
        if (isset($brackets[$char]) && $enclosures[$brackets[$char]]) {
          $enclosures[$brackets[$char]]--;
        }
      }
      $item .= $char;
      // We are escaping the next char if this is a backslash not preceded by an odd number of backslashes
      $isEscaped = $char === '\\' && ((strlen($item) - strlen(rtrim($item, '\\'))) % 2);
    }
    return $item;
  }

  /**
   * Render the expression for insertion into the sql query
   *
   * @param array $fieldList
   * @return string
   */
  public function render(array $fieldList): string {
    $output = '';
    $params = $this->getParams();
    foreach ($this->args as $index => $arg) {
      $rendered = $this->renderArg($arg, $params[$index], $fieldList);
      if (strlen($rendered)) {
        $output .= (strlen($output) ? ' ' : '') . $rendered;
      }
    }
    return $this->getName() . '(' . $output . ')';
  }

  /**
   * @param array $arg
   * @param array $param
   * @param array $fieldList
   * @return string
   */
  private function renderArg($arg, $param, $fieldList): string {
    $rendered = implode(' ', $arg['prefix']);
    foreach ($arg['expr'] ?? [] as $idx => $expr) {
      if (strlen($rendered) || $idx) {
        $rendered .= $idx ? ', ' : ' ';
      }
      $rendered .= $expr->render($fieldList);
    }
    if ($arg['suffix']) {
      $rendered .= (strlen($rendered) ? ' ' : '') . implode(' ', $arg['suffix']);
    }
    return $rendered;
  }

  /**
   * @inheritDoc
   */
  public function getAlias(): string {
    return $this->alias ?? $this->getName() . ':' . implode('_', $this->fields);
  }

  /**
   * Get the name of this sql function.
   * @return string
   */
  public static function getName(): string {
    $className = static::class;
    return substr($className, strrpos($className, 'SqlFunction') + 11);
  }

  /**
   * Get the param metadata for this sql function.
   * @return array
   */
  final public static function getParams(): array {
    $params = [];
    foreach (static::params() as $param) {
      // Merge in defaults to ensure each param has these properties
      $params[] = $param + [
        'prefix' => NULL,
        'min_expr' => 1,
        'max_expr' => 1,
        'flag_before' => [],
        'flag_after' => [],
        'optional' => FALSE,
        'must_be' => [],
        'cant_be' => ['SqlWild'],
        'api_default' => NULL,
      ];
    }
    return $params;
  }

  abstract protected static function params(): array;

  /**
   * Get the arguments passed to this sql function instance.
   * @return array[]
   */
  public function getArgs(): array {
    return $this->args;
  }

  /**
   * @return string
   */
  public static function getCategory(): string {
    return static::$category;
  }

  /**
   * @return string|NULL
   */
  public static function getDataType():? string {
    return static::$dataType;
  }

  /**
   * @return string
   */
  abstract public static function getTitle(): string;

}
