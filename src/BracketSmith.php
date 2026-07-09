<?php

namespace BracketSmith;

use Exception;
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

        $config = json_decode( file_get_contents( $config_file ), true );

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            $this->log( "⚠️ Invalid JSON in bracketsmith.json: " . json_last_error_msg() );
            return;
        }

        if ( isset( $config['include'] ) && is_array( $config['include'] ) )
            $this->include = $config['include'];

        if ( isset( $config['exclude'] ) && is_array( $config['exclude'] ) )
            $this->exclude = $config['exclude'];
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
                if ( ! $this->processDirectory( $directory ) )
                    $success = false;
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

            return true; // Is not a fatal error
        }

        try
        {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir_path,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ( $iterator as $file )
            {
                if ( $file->isFile() && $file->getExtension() === "php" )
                {
                    $file_path = $file->getPathname();

                    // Skips files in specific directories
                    if ( $this->shouldSkipFile( $file_path ) )
                        continue;

                    $this->processFile( $file_path );
                }
            }

            return true;
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
            $content     = file_get_contents( $path );

            $new_content = $this->addSpacesToArrays( $content );

            if ( $content !== $new_content )
            {
                $this->changed_count++;

                if ( ! $this->dry_run )
                {
                    file_put_contents( $path, $new_content );
                    $this->verboseLog( "✅ Processed: " . $path );
                }
                else
                {
                    $this->verboseLog( "🔍 Would be processed: " . $path );
                }

                return true;
            }

            $this->verboseLog( "⏭️ No changes needed: " . $path );

            return true;
        }
        catch ( Exception $e )
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
        $this->include = $include;

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
        $in_encapsed_string = false;
        $encapsed_string_delimiter = "";
        $in_heredoc = false;

        foreach ( $tokens as $index => $token )
        {
            if ( is_array( $token ) )
            {
                $result .= $token[ 1 ];

                if ( $token[ 0 ] === T_START_HEREDOC )
                    $in_heredoc = true;
                elseif ( $token[ 0 ] === T_END_HEREDOC )
                    $in_heredoc = false;

                continue;
            }

            if ( $in_heredoc )
            {
                $result .= $token;
                continue;
            }

            if ( $in_encapsed_string )
            {
                $result .= $token;

                if ( $token === $encapsed_string_delimiter )
                {
                    $in_encapsed_string = false;
                    $encapsed_string_delimiter = "";
                }

                continue;
            }

            if ( $token === '"' || $token === '`' )
            {
                $in_encapsed_string = true;
                $encapsed_string_delimiter = $token;
                $result .= $token;
                continue;
            }

            if ( $token === "[" )
            {
                $result .= "[";
                $next_token = $tokens[ $index + 1 ] ?? null;
                $next_text = is_array( $next_token ) ? $next_token[ 1 ] : $next_token;

                if ( $next_text !== "]" && $next_text !== null && ! preg_match( '/^[ \r\n\t\f\v]/', $next_text ) )
                    $result .= " ";

                continue;
            }

            if ( $token === "]" )
            {
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
        foreach ( $this->exclude as $exclude_pattern )
            if ( str_contains( $file_path, $exclude_pattern ) )
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
