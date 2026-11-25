<?php

namespace BracketSmith;

use Exception;
use RuntimeException;
use InvalidArgumentException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class BracketSmith
{
    /**
     * Directories/files to process
     *
     * @var array
     */
    private array $include = [
        "app/",
        "config/",
        "database/",
        "lang/",
        "routes/",
    ];

    private bool $custom_include = false;

    /**
     * Patterns of directories/files to skip
     *
     * @var array
     */
    private array $exclude = [
        "vendor/",
        "storage/",
        "bootstrap/cache/",
        "node_modules/",
        ".git/",
    ];

    /**
     * Counter of processed files
     *
     * @var int
     */
    private int $processed_count = 0;

    /**
     * Counter of changed files
     *
     * @var int
     */
    private int $changed_count = 0;

    /**
     * Constructor
     *
     * @param bool $dry_run
     * @param bool $verbose
     */
    public function __construct(
        /**
         * Dry-run mode (only checks, does not modify)
         *
         * @var bool
         */
        private readonly bool $dry_run = false,

        /**
         * Verbose mode (detailed output)
         *
         * @var bool
         */
        private readonly bool $verbose = false
    ) {
        $this->loadConfig();
    }

    /**
     * Load configuration from bracketsmith.json file in project root
     *
     * @return void
     */
    private function loadConfig() : void
    {
        $config_file = getcwd() . DIRECTORY_SEPARATOR . 'bracketsmith.json';

        if ( ! file_exists( $config_file ) )
            return; // Use defaults

        $json = @file_get_contents( $config_file );

        if ( $json === false )
            throw new InvalidArgumentException( "Cannot read bracketsmith.json." );

        $config = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );

        if ( ! $config instanceof \stdClass )
            throw new InvalidArgumentException( "bracketsmith.json must contain an object." );

        $config = (array) $config;

        $unknown = array_diff( array_keys( $config ), [ 'include', 'exclude' ] );
        if ( $unknown !== [] )
            throw new InvalidArgumentException( "Unknown configuration keys: " . implode( ', ', $unknown ) );

        foreach ( [ 'include', 'exclude' ] as $key )
        {
            if ( ! array_key_exists( $key, $config ) )
                continue;

            if ( ! is_array( $config[ $key ] ) || ! array_is_list( $config[ $key ] ) )
                throw new InvalidArgumentException( "$key must be a list of paths." );

            foreach ( $config[ $key ] as $path )
                if ( ! is_string( $path ) || trim( $path ) === '' )
                    throw new InvalidArgumentException( "$key paths must be non-empty strings." );

            $this->{$key} = $config[ $key ];
            if ( $key === 'include' )
                $this->custom_include = true;
        }
    }

    /**
     * Executes processing on all configured directories
     *
     * @param array $files_from_cli
     *
     * @return bool Success or general failure
     */
    public function run( array $files_from_cli = [] ) : bool
    {
        $this->log( "🔧 Processing array spacing..." );

        $success = true;

        // If files were passed via CLI, only process them
        if ( ! empty( $files_from_cli ) )
        {
            foreach ( $files_from_cli as $file )
                if ( ! $this->processFile( $file ) )
                    $success = false;
        }
        else
        {
            foreach ( $this->include as $directory )
            {
                if ( ! $this->custom_include && ! file_exists( $directory ) )
                {
                    $this->log( "⚠️ Directory not found: " . $directory );
                    continue;
                }

                if ( ! $this->processFile( $directory ) )
                    $success = false;
            }
        }

        $this->log( $this->getSummary() );

        return $success;
    }

    /**
     * Process a specific directory
     *
     * @param string $dir_path
     *
     * @return bool
     */
    public function processDirectory( string $dir_path ) : bool
    {
        if ( ! is_dir( $dir_path ) )
        {
            $this->log( "⚠️ Directory not found: " . $dir_path );

            return false;
        }

        try
        {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir_path,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            $success = true;

            foreach ( $iterator as $file )
            {
                if ( $file->isFile() && $file->getExtension() === "php" )
                {
                    $file_path = $file->getPathname();

                    // Skips files in specific directories
                    if ( $this->shouldSkipFile( $file_path ) )
                        continue;

                    if ( ! $this->processFile( $file_path ) )
                        $success = false;
                }
            }

            return $success;
        }
        catch ( Exception $e )
        {
            $this->log( sprintf( "❌ Error processing directory %s: ", $dir_path ) . $e->getMessage() );

            return false;
        }
    }

    /**
     * Process a specific file or directory
     *
     * @param string $path
     *
     * @return bool
     */
    public function processFile( string $path ) : bool
    {
        if ( ! file_exists( $path ) )
        {
            $this->log( "❌ Path not found: " . $path );

            return false;
        }

        // If it's a directory, process it recursively
        if ( is_dir( $path ) )
            return $this->processDirectory( $path );

        // Process single file
        $this->processed_count++;

        try
        {
            $target = realpath( $path );
            if ( $target === false || ! is_file( $target ) )
                throw new RuntimeException( "Not a local file: " . $path );

            $content = @file_get_contents( $target );

            if ( $content === false )
                throw new RuntimeException( "Cannot read file: " . $path );

            $new_content = $this->addSpacesToArrays( $content );

            if ( $content !== $new_content )
            {
                token_get_all( $new_content, TOKEN_PARSE );
                if ( ! $this->dry_run )
                {
                    clearstatcache( true, $target );
                    $metadata = @stat( $target );
                    if ( $metadata === false || ! is_writable( $target ) )
                        throw new RuntimeException( "Cannot replace file: " . $path );
                    if ( $metadata[ 'nlink' ] > 1 )
                        throw new RuntimeException( "Refusing to replace a file with multiple hard links: " . $path );

                    $temporary = null;
                    try
                    {
                        $temporary = @tempnam( dirname( $target ), 'bsm' );
                        if ( $temporary === false || realpath( dirname( $temporary ) ) !== dirname( $target ) )
                            throw new RuntimeException( "Cannot create a temporary file beside: " . $path );
                        if ( @file_put_contents( $temporary, $new_content ) !== strlen( $new_content ) )
                            throw new RuntimeException( "Cannot write complete temporary file for: " . $path );
                        if ( fileowner( $temporary ) !== $metadata[ 'uid' ] && ! @chown( $temporary, $metadata[ 'uid' ] ) )
                            throw new RuntimeException( "Cannot preserve file owner: " . $path );
                        if ( filegroup( $temporary ) !== $metadata[ 'gid' ] && ! @chgrp( $temporary, $metadata[ 'gid' ] ) )
                            throw new RuntimeException( "Cannot preserve file group: " . $path );
                        if ( ! @chmod( $temporary, $metadata[ 'mode' ] & 07777 ) )
                            throw new RuntimeException( "Cannot preserve file permissions: " . $path );

                        // ponytail: this detects prior edits, not simultaneous writers; coordinate writers with a shared lock if needed.
                        if ( @file_get_contents( $target ) !== $content )
                            throw new RuntimeException( "File changed during processing: " . $path );
                        if ( ! @rename( $temporary, $target ) )
                            throw new RuntimeException( "Cannot replace original file: " . $path );
                    }
                    finally
                    {
                        if ( is_string( $temporary ) && file_exists( $temporary ) )
                            @unlink( $temporary );
                    }

                    $this->verboseLog( "✅ Processed: " . $path );
                }
                else
                {
                    $this->verboseLog( "🔍 Would be processed: " . $path );
                }

                $this->changed_count++;

                return true;
            }

            $this->verboseLog( "⏭️ No changes needed: " . $path );

            return true;
        }
        catch ( Exception | \ParseError $e )
        {
            $this->log( sprintf( "❌ Error processing file %s: ", $path ) . $e->getMessage() );

            return false;
        }
    }

    /**
     * Configure custom include paths
     *
     * @param array $include
     *
     * @return self
     */
    public function setInclude( array $include ) : self
    {
        if ( ! array_is_list( $include ) )
            throw new InvalidArgumentException( "include must be a list of paths." );
        foreach ( $include as $path )
            if ( ! is_string( $path ) || trim( $path ) === '' )
                throw new InvalidArgumentException( "include paths must be non-empty strings." );

        $this->include = $include;
        $this->custom_include = true;

        return $this;
    }

    /**
     * Get execution statistics
     *
     * @return array
     */
    public function getStats() : array
    {
        return [
            "processed" => $this->processed_count,
            "changed"   => $this->changed_count,
        ];
    }

    private function addSpacesToArrays( string $content ) : string
    {
        $tokens = token_get_all( $content );
        $result = "";
        $contexts = [];
        $attribute_depth = 0;

        foreach ( $tokens as $index => $token )
        {
            if ( $contexts === [] && is_array( $token ) && $token[ 0 ] === T_ATTRIBUTE )
            {
                $attribute_depth++;
                $result .= $token[ 1 ];
                continue;
            }

            if ( $attribute_depth > 0 )
            {
                $result .= is_array( $token ) ? $token[ 1 ] : $token;
                if ( $token === '[' )
                    $attribute_depth++;
                elseif ( $token === ']' )
                    $attribute_depth--;
                continue;
            }

            if ( is_array( $token ) )
            {
                if ( $contexts === [] && $token[ 0 ] === T_WHITESPACE
                    && ( $tokens[ $index - 1 ] ?? null ) === '['
                    && strpbrk( $token[ 1 ], "\r\n" ) === false )
                    continue;

                $result .= $token[ 1 ];

                if ( $token[ 0 ] === T_START_HEREDOC )
                    $contexts[] = T_END_HEREDOC;
                elseif ( $token[ 0 ] === T_END_HEREDOC )
                    array_pop( $contexts );
                elseif ( $token[ 0 ] === T_CURLY_OPEN || $token[ 0 ] === T_DOLLAR_OPEN_CURLY_BRACES )
                    $contexts[] = '}';

                continue;
            }

            if ( $token === '"' || $token === '`' )
            {
                if ( end( $contexts ) === $token )
                    array_pop( $contexts );
                else
                    $contexts[] = $token;

                $result .= $token;
                continue;
            }

            if ( $contexts !== [] )
            {
                if ( $token === '{' )
                    $contexts[] = '}';
                elseif ( $token === '}' && end( $contexts ) === '}' )
                    array_pop( $contexts );

                $result .= $token;
                continue;
            }

            if ( $token === "[" )
            {
                $result .= "[";
                $next_token = $tokens[ $index + 1 ] ?? null;
                $next_text = is_array( $next_token ) ? $next_token[ 1 ] : $next_token;

                if ( is_array( $next_token ) && $next_token[ 0 ] === T_WHITESPACE
                    && strpbrk( $next_text, "\r\n" ) === false )
                {
                    $next_token = $tokens[ $index + 2 ] ?? null;
                    $next_text = is_array( $next_token ) ? $next_token[ 1 ] : $next_token;
                }

                if ( $next_text !== "]" && $next_text !== null && ! preg_match( '/^[ \r\n\t\f\v]/', $next_text ) )
                    $result .= " ";

                continue;
            }

            if ( $token === "]" )
            {
                $previous = $tokens[ $index - 1 ] ?? null;
                if ( ! is_array( $previous ) || $previous[ 0 ] !== T_WHITESPACE
                    || strpbrk( $previous[ 1 ], "\r\n" ) === false )
                    $result = rtrim( $result, " \t\f\v" );

                if ( $result !== "" && ! str_ends_with( $result, "[" ) && ! preg_match( '/[ \r\n\t\f\v]\z/', $result ) )
                    $result .= " ";

                $result .= "]";
                continue;
            }

            $result .= $token;
        }

        return $result;
    }

    /**
     * Checks if a file should be skipped
     *
     * @param string $file_path
     *
     * @return bool
     */
    private function shouldSkipFile( string $file_path ) : bool
    {
        $file_path = str_replace( '\\', '/', $file_path );

        foreach ( $this->exclude as $exclude_pattern )
            if ( str_contains( $file_path, str_replace( '\\', '/', $exclude_pattern ) ) )
                return true;

        return false;
    }

    /**
     * Get execution summary
     *
     * @return string
     */
    private function getSummary() : string
    {
        if ( $this->dry_run )
            return sprintf( "🔍 Verification completed: %d of %d files would require changes.", $this->changed_count, $this->processed_count );

        return sprintf( "✅ Processing completed: %d of %d files were changed.", $this->changed_count, $this->processed_count );
    }

    /**
     * Normal log
     *
     * @param string $message
     *
     * @return void
     */
    private function log( string $message ) : void
    {
        echo $message . "\n";
    }

    /**
     * Verbose log (only if verbose is active)
     *
     * @param string $message
     *
     * @return void
     */
    private function verboseLog( string $message ) : void
    {
        if ( $this->verbose )
            echo $message . "\n";
    }
}
