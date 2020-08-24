<?php
/**
 * Opcodes, a custom sniff helping improve performance for PHP_CodeSniffer by testing against direct opcode calls.
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
 * Detect slow functions.
 *
 * The sniff analyses the following:
 * - Slow internal PHP functions.
 * - Slow WordPress functions.
 *
 * PHP version ^7.0.0
 *
 * @since 1.1.0
 */
class FunctionsSniff extends Sniff {

	/**
	 * @var string[] $slowFuncs
	 */
	protected $slowInternalFuncs = [
		'file_exists',
		'is_readable',
		'file_get_contents',
		'strnatcasecmp',
		'class_exists', // Only when performing an autoload lookup!
		'mysqli_query',
		'mysqli_real_connect',
		'is_dir',
		'scandir',
		'glob',
	];

	/**
	 * @var string[] $slowWpEscapeFuncs
	 */
	protected $slowWpEscapeFuncs = [
		'esc_url',
	];

	/**
	 * @var string[] $slowWpTranslateFuncs
	 */
	protected $slowWpTranslateFuncs = [
		'__',
		'esc_attr__',
		'esc_html__',
		'_e',
		'esc_attr_e',
		'esc_html_e',
		'_x',
		'_ex',
		'esc_attr_x',
		'esc_html_x',
		'_n',
		'_nx',
	];

	/**
	 * @var string[] $slowWpFuncs
	 */
	protected $slowWpFuncs = [
		// 'add_query_arg',    // only slow when you push huge objects, which WP does for script loading....
		// 'remove_query_arg', // only slow when you push huge objects, which WP does for script loading....
	];

	/**
	 * @var string[] $slowFuncs List of slow functions.
	 * @since 1.2.0
	 */
	public $extraSlowFuncs = [];

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function register() {
		// Handle case-insensitivity of function names.
		$this->slowInternalFuncs    = array_map( 'strtolower', $this->slowInternalFuncs );
		$this->slowWpEscapeFuncs    = array_map( 'strtolower', $this->slowWpEscapeFuncs );
		$this->slowWpTranslateFuncs = array_map( 'strtolower', $this->slowWpTranslateFuncs );
		$this->slowWpFuncs          = array_map( 'strtolower', $this->slowWpFuncs );
		$this->extraSlowFuncs       = array_map( 'strtolower', $this->extraSlowFuncs );

		// Combine slowFuncs.
		// $this->slowFuncs = array_unique( array_merge( $this->slowFuncs, $this->extraSlowFuncs ) );

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

		$this->process_slow_funcs( $stackPtr );
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
	protected function process_slow_funcs( $stackPtr ) {

		$function   = $this->tokens[ $stackPtr ]['content'];
		$functionLc = strtolower( $function );

		if ( in_array( $functionLc, $this->slowInternalFuncs, true ) ) {
			$this->phpcsFile->addWarning(
				'Function %s might be slow. Consider an alternative, memoize, or cache the call.',
				$stackPtr,
				'PHP',
				[ "{$function}()" ]
			);
		}
		if ( in_array( $functionLc, $this->slowWpEscapeFuncs, true ) ) {
			$this->phpcsFile->addWarning(
				'Function %s might be slow. Consider an alternative, memoize, or cache the call.',
				$stackPtr,
				'WordPressEscape',
				[ "{$function}()" ]
			);
		}
		if ( in_array( $functionLc, $this->slowWpTranslateFuncs, true ) ) {
			$this->phpcsFile->addWarning(
				'Function %s might be slow. Consider an alternative, memoize, or cache the call.',
				$stackPtr,
				'WordPressi18n',
				[ "{$function}()" ]
			);
		}
		if ( in_array( $functionLc, $this->slowWpFuncs, true ) ) {
			$this->phpcsFile->addWarning(
				'Function %s might be slow. Consider an alternative, memoize, or cache the call.',
				$stackPtr,
				'WordPress',
				[ "{$function}()" ]
			);
		}
		if ( in_array( $functionLc, $this->extraSlowFuncs, true ) ) {
			$this->phpcsFile->addWarning(
				'Function %s might be slow. Consider an alternative, memoize, or cache the call.',
				$stackPtr,
				'Extra',
				[ "{$function}()" ]
			);
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
	 * @since 1.1.0
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
}
