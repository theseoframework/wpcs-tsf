<?php
/**
 * Opcodes, a custom sniff helping improve performance for PHP_CodeSniffer
 * by testing against direct opcode calls.
 *
 * @package   TSF
 * @copyright 2020 Sybre Waaijer
 * @license   GPLv3
 * @link      https://github.com/sybrew/the-seo-framework
 */

namespace TSFCS\TSF\Sniffs\Performance;

use PHPCompatibility\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Detect points that can help performance.
 *
 * The sniff analyses the following:
 * - Namespaced opcode calls for pre-compiled functions.
 * - Namespaced opcode calls for pre-evaluated constant functions.
 *
 * TODO:
 * - Check for map/walk/function_exists
 *
 * PHP version ^7.0.0
 *
 * @since 1.0.0
 */
class OpcodesSniff extends Sniff {

	/**
	 * @link <https://github.com/php/php-src/blob/php-8.4.0RC2/Zend/zend_compile.c#L4954-L5025>
	 * @var string[] $opFuncs
	 */
	protected $opFuncs = [
		'strlen',
		'is_null',
		'is_bool',
		'is_long',
		'is_int',
		'is_integer',
		'is_float',
		'is_double',
		'is_string',
		'is_array',
		'is_object',
		'is_resource',
		'is_scalar',
		'boolval',
		'intval',
		'floatval',
		'doubleval',
		'strval',
		'defined',
		'chr',
		'ord',
		'call_user_func_array',
		'call_user_func',
		'in_array',
		'count',
		'sizeof',
		'get_class',
		'get_called_class',
		'gettype',
		'func_num_args',
		'func_get_args',
		'array_slice',
		'array_key_exists',
		'sprintf',
	];

	/**
	 * @link <https://github.com/php/php-src/blob/php-8.4.0RC2/Zend/Optimizer/block_pass.c#L341-L346>
	 * @var string[] $opConstFuncs
	 */
	protected $opConstFuncs = [
		'constant',
		'function_exists',
		'is_callable',
		'extension_loaded',
		'dirname',
		'define',
	];

	/**
	 * @var string[] $internalFuncs
	 * @see register(), there it's populated.
	 */
	protected $internalFuncs = [];

	/**
	 * @var string[] $noopInternal
	 * @see register(), there it's populated.
	 */
	protected $noopInternal = [];

	/**
	 * @var string[] $allNoopChecks
	 * @see register(), there it's populated.
	 */
	protected $allNoopChecks = [];

	/**
	 * @var string[] $opChecks Internal functions that should be checked for opcode improvements (e.g., 'array_keys').
	 *                         This should not yield a benefit, though.
	 * @since 1.1.0
	 */
	public $opChecks = [];

	/**
	 * @var string[] $noopChecks User functions that should not be checked for opcode improvements (i.e., your namespaced functions).
	 * @since 1.1.0
	 */
	public $userNoopChecks = [];

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function register() {
		// Handle case-insensitivity of function names.
		$this->opFuncs      = array_map( 'strtolower', $this->opFuncs );
		$this->opConstFuncs = array_map( 'strtolower', $this->opConstFuncs );
		$this->opChecks     = array_map( 'strtolower', $this->opChecks );

		// Combine opChecks.
		$this->opChecks = array_unique( array_merge( $this->opChecks, $this->opFuncs, $this->opConstFuncs ) );

		$this->internalFuncs = array_map( 'strtolower', get_defined_functions()['internal'] );

		$this->noopInternal  = array_diff(
			$this->internalFuncs,
			$this->opFuncs,
			$this->opConstFuncs
		);

		$this->userNoopChecks = array_map( 'strtolower', $this->userNoopChecks );

		// Combine noopChecks.
		$this->allNoopChecks = array_unique( array_merge( $this->noopInternal, $this->userNoopChecks ) );

		$targets = [
			\T_STRING,
		];

		return $targets;
	}

	/**
	 * Processes all tests.
	 *
	 * @since 1.0.0
	 *
	 * @param \PHP_CodeSniffer_File $phpcsFile The file being scanned.
	 * @param int                   $stackPtr  The position of the current token in
	 *                                         the stack passed in $tokens.
	 *
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {

		$this->phpcsFile = $phpcsFile;
		$this->tokens    = $phpcsFile->getTokens();

		if ( ! $this->is_targetted_token( $stackPtr ) ) return;

		$next_non_empty = $this->phpcsFile->findNext( Tokens::$emptyTokens, ( $stackPtr + 1 ), null, true, null, true );
		if ( false === $next_non_empty || \T_OPEN_PARENTHESIS !== $this->tokens[ $next_non_empty ]['code'] ) return;

		$this->process_namespaces( $stackPtr );
	}

	/**
	 * Process the namespace tests.
	 *
	 * @since 1.0.0
	 * @source wordpress-coding-standards\wpcs
	 *
	 * @param int $stackPtr The position of the current token in
	 *                      the stack passed in $tokens.
	 */
	protected function process_namespaces( $stackPtr ) {

		$function   = $this->tokens[ $stackPtr ]['content'];
		$functionLc = strtolower( $function );

		if ( '' !== $this->determineNamespace( $this->phpcsFile, $stackPtr ) ) {
			if ( in_array( $functionLc, $this->opChecks, true ) ) {
				if ( false === $this->is_token_namespaced( $stackPtr ) ) {

					$warning = $this->is_object_creation( $stackPtr )
						? 'Class %s should have a leading namespace separator `\`.'
						: 'Function %s should have a leading namespace separator `\`.';

					$this->phpcsFile->addWarning(
						$warning,
						$stackPtr,
						'ShouldHaveNamespaceEscape',
						[ "{$function}()" ]
					);
				}
			} elseif ( ! in_array( $functionLc, $this->internalFuncs, true ) && ! in_array( $functionLc, $this->allNoopChecks, true )  ) {
				if ( false === $this->is_token_namespaced( $stackPtr ) ) {

					$warning = $this->is_object_creation( $stackPtr )
						? 'Class %s should have a leading namespace separator `\`.'
						: 'Function %s should have a leading namespace separator `\`.';

					$this->phpcsFile->addWarning(
						$warning,
						$stackPtr,
						'ShouldHaveNamespaceEscape',
						[ "{$function}()" ]
					);
				}
			}
		} else {
			// When there's no namespace, we're already in the correct scope for the opcode.
			// Warn dev that there's a useless NS escape.
			if ( ! in_array( $functionLc, $this->userNoopChecks, true ) ) {
				if ( true === $this->is_token_globally_namespaced( $stackPtr ) ) {

					$warning = $this->is_object_creation( $stackPtr )
						? 'Class %s should not have a leading namespace separator `\`.'
						: 'Function %s should not have a leading namespace separator `\`.';

					$this->phpcsFile->addWarning(
						$warning,
						$stackPtr,
						'UselessLeadingNamespaceEscape',
						[ "{$function}()" ]
					);
				}
			}
		}
	}

	/**
	 * Verify is the current token is a function call.
	 *
	 * @since 1.0.0
	 * @source wordpress-coding-standards\wpcs (partial)
	 *
	 * @param int $stackPtr The position of the current token in
	 *                      the stack passed in $tokens.
	 *
	 * @return bool
	 */
	protected function is_targetted_token( $stackPtr ) {

		if ( \T_STRING !== $this->tokens[ $stackPtr ]['code'] ) {
			return false;
		}

		// Exclude function definitions, class methods, and namespaced calls.
		if ( $this->is_class_object_call( $stackPtr ) === true ) {
			return false;
		}

		$prev = $this->phpcsFile->findPrevious( Tokens::$emptyTokens, ( $stackPtr - 1 ), null, true );
		if ( false !== $prev ) {
			// Skip sniffing on function, class definitions or for function aliases in use statements.
			$skipped = array(
				\T_FUNCTION => \T_FUNCTION,
				\T_CLASS    => \T_CLASS,
				\T_AS       => \T_AS, // Use declaration alias.
			);

			if ( isset( $skipped[ $this->tokens[ $prev ]['code'] ] ) ) {
				return false;
			}
		}

		// Check if this could even be a function call.
		$next = $this->phpcsFile->findNext( Tokens::$emptyTokens, ( $stackPtr + 1 ), null, true );
		if ( false === $next ) {
			return false;
		}

		// Check for `use function ... (as|;)`.
		if ( ( \T_STRING === $this->tokens[ $prev ]['code'] && 'function' === $this->tokens[ $prev ]['content'] )
			&& ( \T_AS === $this->tokens[ $next ]['code'] || \T_SEMICOLON === $this->tokens[ $next ]['code'] )
		) {
			return true;
		}

		// Check for reference function.
		if ( \T_BITWISE_AND === $this->tokens[ $prev ]['code'] ) {
			return false;
		}

		// If it's not a `use` statement, there should be parenthesis.
		if ( \T_OPEN_PARENTHESIS !== $this->tokens[ $next ]['code'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a particular token is a (static or non-static) call to a class method or property.
	 *
	 * @internal Note: this may still mistake a namespaced function imported via a `use` statement for
	 * a global function!
	 *
	 * @since 1.0.0
	 * @source wordpress-coding-standards\wpcs
	 *
	 * @param int $stackPtr The position of the current token in
	 *                      the stack passed in $tokens.
	 *
	 * @return bool
	 */
	protected function is_class_object_call( $stackPtr ) {

		$before = $this->phpcsFile->findPrevious( Tokens::$emptyTokens, ( $stackPtr - 1 ), null, true, null, true );

		if ( false === $before ) {
			return false;
		}

		if ( \T_OBJECT_OPERATOR !== $this->tokens[ $before ]['code']
			&& \T_DOUBLE_COLON !== $this->tokens[ $before ]['code']
		) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a particular token is a (static or non-static) call to an object.
	 *
	 * @since 1.1.0
	 *
	 * @param int $stackPtr The position of the current token in
	 *                      the stack passed in $tokens.
	 *
	 * @return bool
	 */
	protected function is_object_creation( $stackPtr ) {

		$before = $this->phpcsFile->findPrevious( Tokens::$emptyTokens, ( $stackPtr - 2 ), null, true, null, true );

		if ( false === $before ) {
			return false;
		}

		if ( \T_NEW !== $this->tokens[ $before ]['code'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a particular token is prefixed with a namespace.
	 *
	 * @internal This will give a false positive if the file is not namespaced and the token is prefixed
	 * with `namespace\`.
	 *
	 * @since 1.1.1
	 * @source wordpress-coding-standards\wpcs (partially)
	 *
	 * @param int $stackPtr The position of the current token in
	 *                      the stack passed in $tokens.
	 *
	 * @return bool
	 */
	protected function is_token_namespaced( $stackPtr ) {

		$prev = $this->phpcsFile->findPrevious( Tokens::$emptyTokens, ( $stackPtr - 1 ), null, true, null, true );
		if ( false === $prev ) {
			return false;
		}

		if ( \T_NS_SEPARATOR !== $this->tokens[ $prev ]['code'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a particular token is prefixed with a namespace.
	 *
	 * @internal This will give a false positive if the file is not namespaced and the token is prefixed
	 * with `namespace\`.
	 *
	 * @since 1.0.0
	 * @source wordpress-coding-standards\wpcs (mostly)
	 *
	 * @param int $stackPtr The position of the current token in
	 *                      the stack passed in $tokens.
	 *
	 * @return bool
	 */
	protected function is_token_globally_namespaced( $stackPtr ) {

		$prev = $this->phpcsFile->findPrevious( Tokens::$emptyTokens, ( $stackPtr - 1 ), null, true, null, true );
		if ( false === $prev ) {
			return false;
		}

		if ( \T_NS_SEPARATOR !== $this->tokens[ $prev ]['code'] ) {
			return false;
		}

		$before_prev = $this->phpcsFile->findPrevious( Tokens::$emptyTokens, ( $prev - 1 ), null, true, null, true );
		if ( false === $before_prev ) {
			return false;
		}

		$function   = $this->tokens[ $stackPtr ]['content'];
		$functionLc = strtolower( $function );

		// This is an actual non-global namespace lookup.
		if ( \T_STRING === $this->tokens[ $before_prev ]['code']
		|| \T_NAMESPACE === $this->tokens[ $before_prev ]['code'] ) {
			return false;
		}

		return true;
	}
}
